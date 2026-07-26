import { useLocalSearchParams, useRouter } from 'expo-router';
import React, { useCallback, useEffect, useRef, useState } from 'react';

import { api } from '../../src/api/client';
import type { ConfirmationResponse, SavedRecipe } from '../../src/api/types';
import { EmptyState, ErrorBanner, Loader } from '../../src/components/ui';
import { RecipeDetailView } from '../../src/recipes/RecipeDetailView';

/**
 * Une recette enregistrée, affichée par le même écran qu'une proposition.
 *
 * La clé d'opération est tirée à l'ouverture de l'écran et non une fois pour
 * toutes : une recette conservée est faite plusieurs fois, et deux repas à deux
 * jours d'intervalle doivent bien déduire deux fois. Elle reste stable pendant
 * la visite, ce qui protège du seul cas qui compte — une réponse réseau perdue
 * suivie d'une nouvelle tentative.
 */
export default function SavedRecipeScreen() {
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();

  const [saved, setSaved] = useState<SavedRecipe | null>(null);
  const [missing, setMissing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<ConfirmationResponse | undefined>();
  const operationKey = useRef(`saved${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`);

  const load = useCallback(async () => {
    try {
      setSaved(await api.savedRecipe(id));
    } catch (exception) {
      setMissing(true);
      setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
    }
  }, [id]);

  useEffect(() => {
    load();
  }, [load]);

  if (missing) {
    return (
      <EmptyState
        title="Recette introuvable"
        description="Elle a peut-être été retirée depuis un autre téléphone du foyer."
      />
    );
  }

  if (!saved) {
    return (
      <>
        <ErrorBanner message={error} />
        <Loader />
      </>
    );
  }

  return (
    <RecipeDetailView
      recipe={saved.recipe}
      operationKey={operationKey.current}
      confirmation={confirmation}
      onConfirmed={setConfirmation}
      savedId={saved.id}
      onSavedChange={(next) => {
        // Retirée depuis sa propre fiche : il n'y a plus rien à afficher.
        if (next === null) router.back();
      }}
    />
  );
}
