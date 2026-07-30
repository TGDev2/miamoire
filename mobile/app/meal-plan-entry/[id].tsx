import { useLocalSearchParams } from 'expo-router';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { api } from '../../src/api/client';
import type { ConfirmationResponse, MealPlanEntry } from '../../src/api/types';
import { Button, ErrorBanner, Loader } from '../../src/components/ui';
import { RecipeDetailView } from '../../src/recipes/RecipeDetailView';
import { spacing } from '../../src/theme';

/**
 * Une proposition de Cuisiner ajoutée au plan de repas sans jamais avoir été
 * enregistrée dans « Mes recettes » — son document vit dans l'entrée du plan
 * elle-même, pas ailleurs.
 */
export default function MealPlanEntryScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();

  const [entry, setEntry] = useState<MealPlanEntry | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<ConfirmationResponse | undefined>();
  const operationKey = useRef(`plan${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`);

  const load = useCallback(async () => {
    setError(null);
    try {
      setEntry(await api.mealPlanEntry(id));
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
    }
  }, [id]);

  useEffect(() => {
    load();
  }, [load]);

  if (!entry || !entry.recipe || !entry.plan) {
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
      recipe={entry.recipe}
      plan={entry.plan}
      operationKey={operationKey.current}
      confirmation={confirmation}
      onConfirmed={setConfirmation}
      savedId={null}
      onSavedChange={() => {}}
      mealPlanEntryId={entry.id}
    />
  );
}

const styles = StyleSheet.create({
  failure: { padding: spacing.lg },
});
