<?php

declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Household;
use App\Entity\Member;
use App\Entity\StockOperation;
use App\Enum\StockOperationStatus;
use App\Enum\StockOperationType;
use App\Exception\StockConflictException;
use App\Exception\StockException;
use App\Repository\StockOperationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

/**
 * Porte unique par laquelle une mutation de stock devient rejouable sans effet
 * supplémentaire — née de `RecipeConfirmationService`, qui en était la seule
 * implémentation avant que les mutations ordinaires n'en aient besoin aussi.
 *
 * Trois garanties, et la première corrige un défaut que l'ancienne
 * implémentation avait sans test pour le voir : le statut `succeeded` est écrit
 * ICI, DANS la même transaction que la mutation elle-même — jamais dans un
 * flush séparé après coup. Un flush séparé laissait une fenêtre : un arrêt du
 * process entre les deux laissait le stock débité et l'opération `pending`,
 * qui redevenait rejouable après le délai de péremption — double déduction.
 *
 * La deuxième : relancer une opération périmée (`retry()`) est protégé par la
 * colonne `#[ORM\Version]` de `StockOperation`. Deux relances concurrentes de
 * la même clé ne peuvent plus gagner toutes les deux.
 *
 * La troisième distingue deux familles d'échec, et ne capture QUE la première :
 *
 * - `StockConflictException` : un rejet constaté SOUS VERROU (manque FEFO,
 *   lot déjà épuisé, révision périmée). Par construction, dans tout le code
 *   existant, ce constat précède toute mutation — le capturer ici pour
 *   enregistrer un échec propre et laisser la clé immédiatement réutilisable
 *   est donc sûr.
 * - `StockException` « simple » (saisie invalide, collision de renommage...) :
 *   PAS interceptée. Elle doit pouvoir survenir après qu'un champ a déjà été
 *   modifié (un PATCH composé qui renomme puis échoue sur la quantité, par
 *   exemple) ; la capturer laisserait `wrapInTransaction` valider ce qui a
 *   déjà changé au lieu de tout annuler. La laisser remonter déclenche un vrai
 *   ROLLBACK — l'opération reste `pending` et redevient rejouable après le
 *   délai de péremption, ce qui est le prix correct d'une écriture composée.
 */
final class StockOperationGuard
{
    /**
     * Au-delà de ce délai, une opération restée « en cours » est considérée
     * comme abandonnée — processus tué, conteneur redémarré — et sa clé
     * redevient utilisable. Sans cela, un incident bloquerait définitivement
     * le geste.
     */
    private const STALE_PENDING_MINUTES = 2;

    public function __construct(
        private readonly StockOperationRepository $operations,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param callable(StockOperation): array<string, mixed> $work
     *
     * @return array<string, mixed> la réponse — figée d'un rejeu, ou fraîchement produite
     */
    public function run(
        Household $household,
        string $operationKey,
        StockOperationType $type,
        string $requestHash,
        ?Member $author,
        StockService $stockService,
        callable $work,
    ): array {
        $key = $this->validateKey($operationKey);

        $replay = $this->replayIfKnown($household, $key, $requestHash);
        if (null !== $replay) {
            return $replay;
        }

        $operation = $this->reserve($household, $key, $type, $requestHash, $author);

        /** @var array{ok: bool, response?: array<string, mixed>, failureReason?: string, context?: array<string, mixed>} $outcome */
        $outcome = $stockService->transactional(function () use ($operation, $work): array {
            try {
                $response = $work($operation);
            } catch (StockConflictException $exception) {
                return ['ok' => false, 'failureReason' => $exception->getMessage(), 'context' => $exception->getContext()];
            }

            $operation->succeed($response);

            return ['ok' => true, 'response' => $response];
        });

        if (!$outcome['ok']) {
            $operation->fail($outcome['failureReason'] ?? 'Rejeté.');
            $this->entityManager->flush();

            throw new StockConflictException($outcome['failureReason'] ?? 'Le stock a changé entre-temps.', $outcome['context'] ?? []);
        }

        return $outcome['response'];
    }

    /** @return array<string, mixed>|null */
    private function replayIfKnown(Household $household, string $key, string $requestHash): ?array
    {
        $existing = $this->operations->findOneByKey($household, $key);

        if (null === $existing) {
            return null;
        }

        // Le contenu ne compte que pour une opération ABOUTIE : c'est la seule
        // qui détient une réponse, et renvoyer celle d'un autre geste serait pire
        // que de refuser. Une opération échouée n'a rien écrit — sa clé est
        // libre, quel que soit le contenu de la nouvelle tentative.
        if (StockOperationStatus::Succeeded === $existing->getStatus()) {
            if (!$existing->matches($requestHash)) {
                throw new StockException(
                    'Cette clé a déjà servi pour une autre action. Recommencez avec une nouvelle tentative.',
                );
            }

            return ['replayed' => true] + ($existing->getResponse() ?? []);
        }

        if (StockOperationStatus::Pending === $existing->getStatus() && !$this->isStale($existing)) {
            throw new StockConflictException(
                'Cette action est déjà en cours de traitement. Patientez quelques instants.',
            );
        }

        // Échouée, ou abandonnée depuis assez longtemps : la clé se réemploie.
        $existing->retry($requestHash);

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            // Une autre relance de la même clé périmée a gagné la course.
            throw new StockConflictException(
                'Cette action est déjà en cours de traitement. Patientez quelques instants.',
            );
        }

        return null;
    }

    private function reserve(
        Household $household,
        string $key,
        StockOperationType $type,
        string $requestHash,
        ?Member $author,
    ): StockOperation {
        $existing = $this->operations->findOneByKey($household, $key);

        if (null !== $existing) {
            return $existing;
        }

        $operation = new StockOperation($household, $key, $type, $requestHash, $author);
        $this->entityManager->persist($operation);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux appels concurrents ont réservé la même clé au même instant.
            // L'index unique a tranché : celui qui perd n'écrit rien.
            throw new StockConflictException(
                'Cette action est déjà en cours de traitement. Patientez quelques instants.',
            );
        }

        return $operation;
    }

    private function isStale(StockOperation $operation): bool
    {
        return $operation->getCreatedAt()
            < new \DateTimeImmutable(sprintf('-%d minutes', self::STALE_PENDING_MINUTES));
    }

    private function validateKey(string $operationKey): string
    {
        $key = trim($operationKey);

        if (1 !== preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) {
            throw new StockException(
                'Identifiant d\'opération invalide : l\'application doit en fournir un stable.',
            );
        }

        return $key;
    }
}
