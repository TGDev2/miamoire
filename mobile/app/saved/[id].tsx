import { useLocalSearchParams, useRouter } from 'expo-router';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { ApiError, api } from '../../src/api/client';
import type { ConfirmationResponse, RecipePlan, SavedRecipe } from '../../src/api/types';
import { Button, EmptyState, ErrorBanner, Loader } from '../../src/components/ui';
import { RecipeDetailView } from '../../src/recipes/RecipeDetailView';
import { spacing } from '../../src/theme';

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
  const [plan, setPlan] = useState<RecipePlan | null>(null);
  const [missing, setMissing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<ConfirmationResponse | undefined>();
  const operationKey = useRef(`saved${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`);

  /**
   * Une panne n'est pas une absence.
   *
   * Toute exception concluait ici à « Recette introuvable » — serveur éteint,
   * Wi-Fi coupé, 500, code du foyer périmé : la seule réponse était « elle a été
   * retirée », et le message capturé n'était même pas affiché. On annonçait donc
   * la disparition d'une recette qui n'a jamais bougé. Seul un 404 le dit ;
   * le reste est un incident, et un incident se réessaie.
   *
   * Le plan est recalculé à chaque ouverture, à côté de la recette elle-même :
   * stock, exclusions et réassociation d'un aliment renommé ont pu changer
   * depuis l'enregistrement, et c'est lui — pas `saved.recipe.feasibility` —
   * qui fait foi à l'affichage.
   */
  const load = useCallback(async () => {
    setError(null);
    try {
      const [savedRecipe, freshPlan] = await Promise.all([api.savedRecipe(id), api.recipePlan(id)]);
      setSaved(savedRecipe);
      setPlan(freshPlan);
      setMissing(false);
    } catch (exception) {
      if (exception instanceof ApiError && exception.status === 404) {
        setMissing(true);
        return;
      }

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

  if (!saved || !plan) {
    return error ? (
      <View style={styles.failure}>
        <ErrorBanner message={error} />
        <Button label="Réessayer" onPress={() => void load()} />
      </View>
    ) : (
      <Loader />
    );
  }

  return (
    <RecipeDetailView
      recipe={saved.recipe}
      plan={plan}
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

const styles = StyleSheet.create({
  failure: { padding: spacing.lg },
});
