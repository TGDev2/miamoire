<?php

declare(strict_types=1);

namespace App\Service\Ai\Generator;

use App\Exception\StockException;

/**
 * Le fournisseur n'a pas pu répondre : quota épuisé, panne, réseau, délai.
 *
 * Distinct d'un refus de la requête : une clé invalide ou un modèle inexistant
 * échouera identiquement partout, alors que celle-ci dit seulement « pas ici,
 * pas maintenant ». C'est cette nuance qui autorise `FallbackRecipeGenerator` à
 * passer à la tentative suivante — la confondre avec une erreur fatale faisait
 * de tout dépassement de quota une impasse.
 *
 * Étend `StockException` pour que le message reste celui que l'application
 * affiche, mais `ApiExceptionListener` lui réserve un 503 : un 422 la noierait
 * parmi les erreurs de saisie.
 */
class RecipeGeneratorUnavailableException extends StockException
{
}
