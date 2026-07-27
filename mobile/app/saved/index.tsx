import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { api } from '../../src/api/client';
import type { SavedRecipe } from '../../src/api/types';
import { Badge, EmptyState, ErrorBanner, Loader } from '../../src/components/ui';
import { colors, feasibilityPresentation, radius, spacing } from '../../src/theme';

/**
 * Les recettes que le foyer a gardées.
 *
 * Rechargées à chaque affichage plutôt que mises en cache : un autre membre peut
 * en avoir ajouté ou retiré depuis son téléphone, et une liste périmée ferait
 * ouvrir une recette qui n'existe plus.
 */
export default function SavedRecipesScreen() {
  const router = useRouter();

  const [recipes, setRecipes] = useState<SavedRecipe[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const result = await api.savedRecipes();
      setRecipes(result.recipes);
      setError(null);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
      setRecipes([]);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  function confirmRemoval(saved: SavedRecipe) {
    Alert.alert(
      'Retirer cette recette ?',
      `« ${saved.recipeName} » disparaîtra de vos recettes enregistrées. Votre stock n'est pas touché.`,
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Retirer',
          style: 'destructive',
          onPress: async () => {
            try {
              await api.deleteSavedRecipe(saved.id);
              setRecipes((current) =>
                (current ?? []).filter((entry) => entry.id !== saved.id),
              );
            } catch (exception) {
              setError(exception instanceof Error ? exception.message : 'Retrait impossible.');
            }
          },
        },
      ],
    );
  }

  if (recipes === null) return <Loader />;

  if (recipes.length === 0) {
    return (
      <View style={styles.emptyWrapper}>
        <ErrorBanner message={error} />
        <EmptyState
          title="Aucune recette enregistrée"
          description="Ouvrez une proposition depuis l'onglet Cuisiner et touchez « Enregistrer cette recette » pour la conserver ici."
        />
      </View>
    );
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <ErrorBanner message={error} />

      {recipes.map((saved) => {
        const presentation =
          feasibilityPresentation[saved.recipe.feasibility] ?? feasibilityPresentation.ready;

        return (
          <Pressable
            key={saved.id}
            onPress={() => router.push(`/saved/${saved.id}`)}
            accessibilityRole="button"
            accessibilityLabel={`${saved.recipeName}. ${presentation.label}. ${saved.recipe.preparationTime} minutes, ${saved.recipe.servings} ${saved.recipe.servings > 1 ? 'personnes' : 'personne'}`}
            accessibilityHint="Ouvre la recette"
            style={({ pressed }) => [styles.card, pressed && styles.pressed]}
          >
            <Badge
              label={presentation.label}
              color={presentation.color}
              background={presentation.background}
            />
            <Text style={styles.title}>{saved.recipeName}</Text>
            <Text style={styles.summary}>{saved.recipe.summary}</Text>
            <Text style={styles.meta}>
              {saved.recipe.preparationTime} min · {saved.recipe.servings}{' '}
              {saved.recipe.servings > 1 ? 'personnes' : 'personne'}
              {saved.savedBy ? ` · enregistrée par ${saved.savedBy}` : ''}
            </Text>
            {/* Imbriqué dans la carte : sans libellé propre, il se confond avec
                elle et l'on ne sait plus quelle recette on retire. */}
            <Pressable
              onPress={() => confirmRemoval(saved)}
              hitSlop={8}
              accessibilityRole="button"
              accessibilityLabel={`Retirer « ${saved.recipeName} » de mes recettes`}
            >
              <Text style={styles.remove}>Retirer de mes recettes</Text>
            </Pressable>
          </Pressable>
        );
      })}

      <Text style={styles.footer}>
        Une recette enregistrée garde ses quantités d'origine. Les ajuster au moment de
        déduire le stock ne les modifie pas ici.
      </Text>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl },
  emptyWrapper: { flex: 1, padding: spacing.xl },
  card: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    padding: spacing.lg,
    marginBottom: spacing.lg,
  },
  pressed: { opacity: 0.75 },
  title: { fontSize: 19, fontWeight: '800', color: colors.ink, marginTop: spacing.md },
  summary: { fontSize: 14, color: colors.inkMuted, marginTop: spacing.xs, lineHeight: 20 },
  meta: { fontSize: 13, color: colors.ink, marginTop: spacing.md },
  remove: { fontSize: 13, color: colors.danger, fontWeight: '600', marginTop: spacing.md },
  footer: { fontSize: 12, color: colors.inkMuted, lineHeight: 18, marginTop: spacing.sm },
});
