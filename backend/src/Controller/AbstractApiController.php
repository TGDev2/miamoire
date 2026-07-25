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
}
