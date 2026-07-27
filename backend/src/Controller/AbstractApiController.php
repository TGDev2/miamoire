<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StockItem;
use App\Http\RequestContext;
use App\Repository\StockItemRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

abstract class AbstractApiController extends AbstractController
{
    /**
     * Récupère un lot en garantissant qu'il appartient bien au foyer courant.
     * C'est le point de passage unique qui rend l'isolation entre foyers effective.
     */
    protected function ownedStockItem(
        StockItemRepository $repository,
        RequestContext $context,
        string $id,
    ): StockItem {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Aliment introuvable.');
        }

        $item = $repository->find(Uuid::fromString($id));

        if (null === $item || !$item->getHousehold()->getId()->equals($context->household()->getId())) {
            // On ne distingue pas « inexistant » de « appartient à un autre foyer ».
            throw new NotFoundHttpException('Aliment introuvable.');
        }

        return $item;
    }

    /**
     * La même chose, mais verrouillée pour la durée de la transaction en cours.
     *
     * Deux téléphones qui liraient chacun 500 g puis retireraient 100 g et 200 g
     * peuvent, sans verrou, écrire deux soldes concurrents — une mise à jour
     * perdue. Le verrou doit être pris AVANT toute lecture des champs du lot
     * dans cette requête : verrouiller un lot déjà chargé ne le rafraîchit pas
     * (Doctrine ne réhydrate pas une entité déjà gérée), ce qui annulerait
     * l'intérêt même du verrou. C'est pourquoi cette méthode verrouille d'abord,
     * et ne doit être appelée qu'à l'intérieur d'un `StockService::transactional()`
     * — un verrou pessimiste exige une transaction ouverte.
     */
    protected function ownedStockItemForUpdate(
        StockItemRepository $repository,
        RequestContext $context,
        string $id,
    ): StockItem {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Aliment introuvable.');
        }

        $uuid = Uuid::fromString($id);
        $repository->lockForUpdate([$uuid]);

        $item = $repository->find($uuid);

        if (null === $item || !$item->getHousehold()->getId()->equals($context->household()->getId())) {
            throw new NotFoundHttpException('Aliment introuvable.');
        }

        return $item;
    }
}
