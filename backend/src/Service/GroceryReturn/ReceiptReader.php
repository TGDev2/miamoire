<?php

declare(strict_types=1);

namespace App\Service\GroceryReturn;

use App\Entity\Household;
use App\Entity\ReceiptCapture;
use App\Enum\Unit;
use App\Exception\StockException;
use App\Repository\ReceiptAliasRepository;
use App\Repository\ReceiptCaptureRepository;
use App\Service\Ai\Generator\RecipeGeneratorFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns the photo of a receipt into lines of a grocery return.
 *
 * The model transcribes; it decides nothing. Each printed label is first
 * looked up in what the household taught (ReceiptAlias): a known label names
 * its food outright, an ignored one is set aside. The rest goes through the
 * same resolver as a scanned line, with the model's readable name as the
 * only hint — and a name the resolver cannot match stays "à préciser".
 *
 * The one thing written here is the receipt's fingerprint (ReceiptCapture),
 * so that the same receipt read twice is recognised. The image is not kept.
 */
final class ReceiptReader
{
    public const MAX_IMAGES = 3;

    /** How long a receipt is remembered for the duplicate check. */
    private const DUPLICATE_WINDOW = '-60 days';

    private const USER_TEXT = 'Voici le ticket de caisse (%d photo%s). Réponds uniquement par le JSON demandé, sans texte d\'introduction ni bloc de code.';

    public function __construct(
        private readonly RecipeGeneratorFactory $generators,
        private readonly ReceiptAliasRepository $aliases,
        private readonly ReceiptCaptureRepository $captures,
        private readonly ReceiptAliasKey $keys,
        private readonly GroceryReturnResolver $resolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        /** A provider chain for the receipts only (`RECEIPT_PROVIDER`); empty = the recipes' chain. */
        private readonly string $receiptProvider = '',
    ) {
    }

    /** @param list<array{mediaType: string, data: string}> $images */
    public function read(Household $household, array $images): ReceiptReading
    {
        if ([] === $images) {
            throw new StockException('Aucune photo de ticket à lire.');
        }

        if (count($images) > self::MAX_IMAGES) {
            throw new StockException(sprintf('Au plus %d photos par ticket.', self::MAX_IMAGES));
        }

        $generator = '' !== trim($this->receiptProvider)
            ? $this->generators->forProvider($this->receiptProvider)
            : $this->generators->create();
        $count = count($images);

        $raw = $generator->generate(
            self::systemPrompt(),
            sprintf(self::USER_TEXT, $count, $count > 1 ? 's' : ''),
            ReceiptSchema::definition(),
            $images,
        );

        $data = $this->decode($raw);

        $store = trim((string) ($data['store'] ?? ''));
        $storeKey = $this->keys->of($store);
        $receiptDate = $this->date($data['receiptDate'] ?? null);
        $total = is_numeric($data['total'] ?? null) && (float) $data['total'] > 0 ? round((float) $data['total'], 2) : null;

        $inputs = [];
        $receiptLines = [];
        $ignored = [];
        $labelKeys = [];

        foreach ($data['lines'] ?? [] as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $rawLabel = trim((string) ($entry['rawLabel'] ?? ''));
            if ('' === $rawLabel) {
                continue;
            }

            $labelKey = $this->keys->of($rawLabel);
            $labelKeys[] = $labelKey;

            $expandedName = trim((string) ($entry['expandedName'] ?? ''));
            $isFood = (bool) ($entry['isFood'] ?? true);
            $quantity = max(1, (int) ($entry['quantity'] ?? 1));
            $packageQuantity = is_numeric($entry['packageQuantity'] ?? null) ? (float) $entry['packageQuantity'] : 0.0;
            $packageUnit = Unit::tryFrom((string) ($entry['packageUnit'] ?? ''));
            $lineTotal = is_numeric($entry['lineTotal'] ?? null) && (float) $entry['lineTotal'] > 0
                ? round((float) $entry['lineTotal'], 2)
                : null;

            $alias = $this->aliases->findOne($household, $storeKey, $labelKey);
            if (null !== $alias && !$alias->isUsable()) {
                $alias = null;
            }

            // The household's word beats the model's: a label taught as "not
            // food" is skipped, a label the model doubts but the household
            // confirmed before is kept.
            if (null !== $alias ? $alias->isIgnored() : !$isFood) {
                $ignored[] = [
                    'rawLabel' => $rawLabel,
                    'expandedName' => $expandedName,
                    'learned' => null !== $alias,
                ];
                continue;
            }

            // "OEUFS X6", "ESCALOPE DE DINDE X4": a count with no package is
            // N pieces of one. The count is the receipt's fact; nobody is asked
            // a weight per egg.
            $unit = $packageUnit;
            $perPackage = $packageQuantity > 0 ? $packageQuantity : null;
            if (null === $perPackage && null === $unit && $quantity > 1) {
                $unit = Unit::Piece;
                $perPackage = 1.0;
            }

            $inputs[] = [
                'lineKey' => sprintf('receipt-%d', $index),
                'variantId' => null !== $alias ? (string) $alias->getVariant()?->getId() : null,
                'name' => null !== $alias ? null : ('' !== $expandedName ? $expandedName : $rawLabel),
                'receiptLabel' => $rawLabel,
                'unit' => $unit?->value,
                'quantityPerPackage' => $perPackage,
                'numberOfPackages' => $quantity,
            ];
            $receiptLines[] = ['rawLabel' => $rawLabel, 'lineTotal' => $lineTotal];
        }

        $resolved = $this->resolver->resolve($household, $inputs);

        $lines = [];
        foreach ($resolved as $position => $line) {
            $lines[] = [
                'line' => $line,
                'rawLabel' => $receiptLines[$position]['rawLabel'],
                'lineTotal' => $receiptLines[$position]['lineTotal'],
            ];
        }

        $duplicateOf = $this->remember($household, $storeKey, $store, $receiptDate, $total, $labelKeys, count($lines));

        return new ReceiptReading('' !== $store ? $store : null, $receiptDate, $total, $lines, $ignored, $duplicateOf);
    }

    /**
     * Records this read and returns the earlier read of the same receipt, if any.
     *
     * The fingerprint prefers what a till prints once per receipt — shop,
     * date, total — and falls back to the shop plus the printed labels when
     * one of them was not read. Two readings of one photo differ in details
     * (an expanded name, a quantity), never in those.
     *
     * @param list<string> $labelKeys
     */
    private function remember(
        Household $household,
        string $storeKey,
        string $store,
        ?\DateTimeImmutable $receiptDate,
        ?float $total,
        array $labelKeys,
        int $lineCount,
    ): ?ReceiptCapture {
        if ([] === $labelKeys) {
            return null;
        }

        if (null !== $receiptDate && null !== $total) {
            $material = sprintf('%s|%s|%.2f', $storeKey, $receiptDate->format('Y-m-d'), $total);
        } else {
            sort($labelKeys);
            $material = $storeKey.'|'.implode("\n", $labelKeys);
        }
        $fingerprint = hash('sha256', $material);

        $earlier = $this->captures->findEarlierRead(
            $household,
            $fingerprint,
            new \DateTimeImmutable(self::DUPLICATE_WINDOW),
        );

        $this->entityManager->persist(new ReceiptCapture(
            $household,
            $fingerprint,
            '' !== $store ? $store : null,
            $receiptDate,
            $total,
            $lineCount,
        ));
        $this->entityManager->flush();

        return $earlier;
    }

    /** @return array<string, mixed> */
    private function decode(string $raw): array
    {
        $text = trim($raw);
        // A model that wraps its answer in a code fence despite the schema.
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text) ?? $text;
        }

        try {
            $data = json_decode($text, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logger->error('Lecture de ticket inexploitable', ['error' => $exception->getMessage()]);

            throw new StockException('La lecture du ticket a échoué : réponse inexploitable. Reprenez la photo, bien à plat et nette.');
        }

        if (!is_array($data) || !is_array($data['lines'] ?? null)) {
            throw new StockException('La lecture du ticket a échoué : aucune ligne reconnue. Reprenez la photo, bien à plat et nette.');
        }

        return $data;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return false !== $date ? $date : null;
    }

    private static function systemPrompt(): string
    {
        return <<<'TXT'
            Tu lis la photo d'un ticket de caisse d'un magasin alimentaire français et tu en transcris les lignes d'articles.

            Règles :
            - `rawLabel` : le libellé exactement tel qu'il est imprimé, abréviations et majuscules comprises, sans le corriger.
            - `expandedName` : le nom lisible de l'aliment, en français, au singulier, sans marque ni contenance ni prix — « LAIT 1/2 ECR 6X1L » donne « Lait demi-écrémé », « YAOURT NAT X12 » donne « Yaourt nature ». Garde les mots qui disent l'état de l'aliment : surgelé, en conserve, fumé, râpé, frais (« POISSON PANE SURG » donne « Poisson pané surgelé »). Chaîne vide si tu ne sais pas lire la ligne.
            - `isFood` : faux pour tout ce qui ne se mange ni ne se boit (papier, piles, entretien, hygiène, sacs, consigne, bon de réduction).
            - `quantity` : le nombre d'articles identiques de la ligne. « 3 x 1,20 », « x3 », « X6 » après le libellé comptent 3 ou 6 ; 1 sinon.
            - `packageQuantity` et `packageUnit` : la contenance d'un article quand le libellé ou la ligne la donne. « 6X125G » donne 125 et g avec quantity 6 ; « 0,842 KG » donne 0.842 et kg ; « 1L » donne 1 et l. Sinon 0 et chaîne vide.
            - Un article pesé est souvent imprimé sur deux rangées : le libellé (« TOMATES RONDES »), puis le poids et le prix au kilo (« 0,655 KG X 2,29 »). C'est UNE ligne : le libellé, avec le poids pour contenance. Ne crée jamais une ligne pour la rangée de poids seule.
            - `lineTotal` : le montant de la ligne ; 0 si illisible.
            - Ignore ce qui n'est pas un article : en-tête, adresse, total, TVA, paiement, carte de fidélité, remises et bons (« REMISE », « BON DE REDUCTION », « -20% »), messages.
            - N'invente aucune ligne et n'en fusionne aucune hormis la rangée de poids : chaque article imprimé donne une ligne, une ligne illisible est transcrite telle quelle avec `expandedName` vide.
            - `store` : l'enseigne lue en haut du ticket ; `receiptDate` : la date d'achat au format AAAA-MM-JJ ; `total` : le montant total payé. Chaîne vide ou 0 quand ce n'est pas lisible.
            TXT;
    }
}
