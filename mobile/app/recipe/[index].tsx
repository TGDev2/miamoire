import { useLocalSearchParams } from 'expo-router';
import React from 'react';

import { EmptyState } from '../../src/components/ui';
import { useRecipes } from '../../src/recipes/RecipeContext';
import { RecipeDetailView } from '../../src/recipes/RecipeDetailView';

/**
 * Une proposition de la dernière génération.
 *
 * Tout l'affichage vit dans `RecipeDetailView`, partagé avec les recettes
 * enregistrées. Cet écran n'apporte que la source : quelle recette, et où vit
 * son état.
 *
 * Cet état — confirmation appliquée, enregistrement — est tenu par le contexte
 * et non par un `useState` local : un `useState` se réinitialisait à chaque
 * retour sur la page, et le bouton se réarmait alors qu'une déduction avait
 * déjà eu lieu.
 */
export default function RecipeDetailScreen() {
  const { index } = useLocalSearchParams<{ index: string }>();
  const {
    suggestion,
    operationKeyFor,
    confirmationFor,
    rememberConfirmation,
    savedIdFor,
    rememberSaved,
  } = useRecipes();

  const position = Number(index);
  const recipe = suggestion?.recipes[position];

  if (!recipe) {
    return (
      <EmptyState
        icon="pot-steam-outline"
        title="Recette introuvable"
        description="Générez de nouvelles propositions depuis l'onglet Cuisiner."
      />
    );
  }

  return (
    <RecipeDetailView
      recipe={recipe}
      plan={recipe.plan}
      operationKey={operationKeyFor(position)}
      confirmation={confirmationFor(position)}
      onConfirmed={(result) => rememberConfirmation(position, result)}
      savedId={savedIdFor(position)}
      onSavedChange={(id) => rememberSaved(position, id)}
    />
  );
}
