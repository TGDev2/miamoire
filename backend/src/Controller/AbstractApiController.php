<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Household;
use App\Entity\StockItem;
use App\Entity\User;
use App\Repository\StockItemRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

abstract class AbstractApiController extends AbstractController
{
    protected function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        return $user;
    }

    protected function currentHousehold(): Household
    {
        return $this->currentUser()->getHousehold();
    }

    /** Récupère un lot en garantissant qu'il appartient bien au foyer de l'appelant. */
    protected function ownedStockItem(StockItemRepository $repository, string $id): StockItem
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Aliment introuvable.');
        }

        $item = $repository->find(Uuid::fromString($id));

        if (null === $item || $item->getHousehold()->getId() !== $this->currentHousehold()->getId()) {
            // On ne distingue pas « inexistant » de « appartient à un autre foyer ».
            throw new NotFoundHttpException('Aliment introuvable.');
        }

        return $item;
    }
}
