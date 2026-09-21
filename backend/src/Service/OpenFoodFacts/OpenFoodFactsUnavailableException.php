<?php

declare(strict_types=1);

namespace App\Service\OpenFoodFacts;

/**
 * Open Food Facts n'a pas pu dire s'il connaît un code-barres : réseau, délai,
 * quota (15 req/min/IP), erreur serveur, réponse illisible.
 *
 * Distinct d'un produit inconnu, qui est une réponse : celle-ci n'en est pas
 * une, et se réessaie. La confondre avec un `null` faisait ajouter une ligne
 * vide pour un produit connu, puis apprendre au catalogue une fiche manuelle
 * à sa place.
 */
class OpenFoodFactsUnavailableException extends \RuntimeException
{
}
