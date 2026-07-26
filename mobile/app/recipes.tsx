import { useRouter } from 'expo-router';
import React from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import type { Feasibility, Recipe } from '../src/api/types';
import { Badge, EmptyState } from '../src/components/ui';
import { useRecipes } from '../src/recipes/RecipeContext';
import { colors, feasibilityPresentation, radius, spacing } from '../src/theme';

/** Ordre d'affichage : le plus immédiatement réalisable en premier. */
const ORDER: Feasibility[] = ['ready', 'adapted', 'needs_shopping'];

export default function RecipeListScreen() {
  const router = useRouter();
  const { suggestion } = useRecipes();

  if (!suggestion || suggestion.recipes.length === 0) {
    return (
      <EmptyState
        title="Aucune proposition"
        description="Revenez à l'onglet Cuisiner pour générer des idées de repas."
      />
    );
  }

  const sorted = [...suggestion.recipes].sort(
    (a, b) => ORDER.indexOf(a.feasibility) - ORDER.indexOf(b.feasibility),
  );

  return (
    <ScrollView contentContainerStyle={styles.container}>
      {/* Ce que l'utilisateur a imposé, rappelé là où il en voit le résultat :
          sans cela, trois recettes autour du même aliment ressemblent à une
          coïncidence plutôt qu'à une contrainte respectée. */}
      {suggestion.requiredIngredient ? (
        <View style={styles.required}>
          <Text style={styles.requiredTitle}>
            Aliment imposé : {suggestion.requiredIngredient.label}
          </Text>
          <Text style={styles.requiredHint}>
            Chaque proposition ci-dessous l'utilise. Celles qui l'ignoraient ont été écartées.
          </Text>
        </View>
      ) : null}

      {/* Une proposition qui disparaît sans explication ressemble à une panne.
          C'est au contraire un contrôle qui a fonctionné. */}
      {suggestion.rejected.length > 0 ? (
        <View style={styles.rejected}>
          <Text style={styles.rejectedTitle}>
            {suggestion.rejected.length === 1
              ? '1 proposition écartée'
              : `${suggestion.rejected.length} propositions écartées`}
          </Text>
          {suggestion.rejected.map((entry, position) => (
            <Text key={`rejected-${position}`} style={styles.rejectedLine}>
              « {entry.recipeName} » — {entry.reason}
            </Text>
          ))}
          <Text style={styles.rejectedFooter}>
            Chaque recette est relue avant affichage : titre, ingrédients, adaptations
            et étapes comprises, ainsi que l'usage réel de l'aliment imposé.
          </Text>
        </View>
      ) : null}

      {sorted.map((recipe) => {
        const originalIndex = suggestion.recipes.indexOf(recipe);
        return (
          <RecipeCard
            key={`${recipe.recipeName}-${originalIndex}`}
            recipe={recipe}
            onPress={() => router.push(`/recipe/${originalIndex}`)}
          />
        );
      })}
    </ScrollView>
  );
}

function RecipeCard({ recipe, onPress }: { recipe: Recipe; onPress: () => void }) {
  const presentation = feasibilityPresentation[recipe.feasibility] ?? feasibilityPresentation.ready;
  const missingEssential = recipe.missingIngredients.filter((entry) => entry.essential);

  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.card, pressed && styles.pressed]}>
      <Badge
        label={presentation.label}
        color={presentation.color}
        background={presentation.background}
      />

      <Text style={styles.title}>{recipe.recipeName}</Text>
      <Text style={styles.summary}>{recipe.summary}</Text>

      <View style={styles.metaRow}>
        <Text style={styles.meta}>{recipe.preparationTime} min</Text>
        <Text style={styles.metaSeparator}>·</Text>
        <Text style={styles.meta}>
          {recipe.servings} {recipe.servings > 1 ? 'personnes' : 'personne'}
        </Text>
        <Text style={styles.metaSeparator}>·</Text>
        <Text style={styles.meta}>
          {recipe.ingredientsFromInventory.length} ingrédient(s) de votre stock
        </Text>
      </View>

      {missingEssential.length > 0 ? (
        <Text style={styles.missing}>
          À acheter : {missingEssential.map((entry) => entry.label).join(', ')}
        </Text>
      ) : null}

      {recipe.substitutions.length > 0 ? (
        <Text style={styles.substitution}>
          {recipe.substitutions
            .map((entry) => `${entry.original} → ${entry.replacedBy}`)
            .join(' · ')}
        </Text>
      ) : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl },
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
  metaRow: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', marginTop: spacing.md },
  meta: { fontSize: 13, color: colors.ink },
  metaSeparator: { fontSize: 13, color: colors.inkMuted, marginHorizontal: spacing.sm },
  missing: { fontSize: 13, color: colors.accent, marginTop: spacing.md, fontWeight: '600' },
  substitution: { fontSize: 13, color: colors.info, marginTop: spacing.xs },
  rejected: {
    backgroundColor: colors.accentSoft,
    borderRadius: radius.md,
    padding: spacing.md,
    marginBottom: spacing.lg,
  },
  required: {
    backgroundColor: colors.primarySoft,
    borderRadius: radius.md,
    padding: spacing.md,
    marginBottom: spacing.lg,
  },
  requiredTitle: { fontSize: 14, fontWeight: '700', color: colors.primary },
  requiredHint: { fontSize: 13, color: colors.primary, marginTop: spacing.xs, lineHeight: 19 },
  rejectedTitle: { fontSize: 14, fontWeight: '700', color: colors.accent },
  rejectedLine: { fontSize: 13, color: colors.accent, marginTop: spacing.xs, lineHeight: 19 },
  rejectedFooter: { fontSize: 12, color: colors.accent, marginTop: spacing.sm, lineHeight: 17 },
});
