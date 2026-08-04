import { useRouter } from 'expo-router';
import React from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import type { Feasibility, PriorityOutcome, Recipe } from '../src/api/types';
import { Badge, Card, EmptyState, Icon } from '../src/components/ui';
import { useRecipes } from '../src/recipes/RecipeContext';
import { colors, feasibilityPresentation, font, radius, spacing, text, thermal } from '../src/theme';

/** Ordre d'affichage : le plus immédiatement réalisable en premier. */
const ORDER: Feasibility[] = ['ready', 'adapted', 'needs_shopping'];

export default function RecipeListScreen() {
  const router = useRouter();
  const { suggestion } = useRecipes();

  if (!suggestion || suggestion.recipes.length === 0) {
    return (
      <EmptyState
        icon="pot-steam-outline"
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
        <Card style={styles.notice}>
          <Text style={text.label}>Aliment imposé</Text>
          <Text style={[text.subheading, styles.noticeTitle]}>
            {suggestion.requiredIngredient.label}
            {suggestion.requiredIngredient.targetLabel
              ? ` — ${suggestion.requiredIngredient.targetLabel} à écouler`
              : ''}
          </Text>
          <Text style={[text.small, styles.noticeLine]}>
            {suggestion.requiredIngredient.targetLabel
              ? "Chaque proposition ci-dessous l'utilise, et en écoule au moins l'essentiel. Celles qui restaient nettement en dessous ont été écartées."
              : "Chaque proposition ci-dessous l'utilise. Celles qui l'ignoraient ont été écartées."}
          </Text>
        </Card>
      ) : null}

      {/* Une proposition qui disparaît sans explication ressemble à une panne.
          C'est au contraire un contrôle qui a fonctionné. */}
      {suggestion.rejected.length > 0 ? (
        <Card style={styles.notice}>
          <View style={styles.noticeHead}>
            <Icon name="shield-check-outline" size={18} />
            <Text style={text.label}>
              {suggestion.rejected.length === 1
                ? '1 proposition écartée'
                : `${suggestion.rejected.length} propositions écartées`}
            </Text>
          </View>
          {suggestion.rejected.map((entry, position) => (
            <Text key={`rejected-${position}`} style={[text.small, styles.noticeLine]}>
              « {entry.recipeName} » — {entry.reason}
            </Text>
          ))}
          <Text style={[text.small, styles.noticeFooter]}>
            Chaque recette est relue avant affichage : titre, ingrédients, adaptations et étapes
            comprises, ainsi que l'usage réel de l'aliment imposé.
          </Text>
        </Card>
      ) : null}

      {sorted.map((recipe) => {
        const originalIndex = suggestion.recipes.indexOf(recipe);
        return (
          <RecipeCard
            key={`${recipe.recipeName}-${originalIndex}`}
            recipe={recipe}
            desiredIngredients={suggestion.desiredIngredients}
            onPress={() => router.push(`/recipe/${originalIndex}`)}
          />
        );
      })}
    </ScrollView>
  );
}

/**
 * Les aliments souhaités que CETTE recette utilise réellement — recoupés
 * côté client plutôt que renvoyés par le serveur, l'information existe déjà
 * dans `ingredientsFromInventory`/`stockConsumptionProposal`.
 */
function usedDesiredLabels(recipe: Recipe, desiredIngredients: PriorityOutcome[]): string[] {
  const usedKeys = new Set([
    ...recipe.ingredientsFromInventory.map((entry) => entry.key),
    ...recipe.stockConsumptionProposal.map((entry) => entry.key),
  ]);

  return desiredIngredients
    .filter((entry) => usedKeys.has(entry.key))
    .map((entry) => entry.label);
}

function RecipeCard({
  recipe,
  desiredIngredients,
  onPress,
}: {
  recipe: Recipe;
  desiredIngredients: PriorityOutcome[];
  onPress: () => void;
}) {
  const presentation = feasibilityPresentation[recipe.feasibility] ?? feasibilityPresentation.ready;
  const missingEssential = recipe.missingIngredients.filter((entry) => entry.essential);
  const usedDesired = usedDesiredLabels(recipe, desiredIngredients);

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`${recipe.recipeName}. ${presentation.label}. ${recipe.preparationTime} minutes, ${recipe.servings} ${recipe.servings > 1 ? 'personnes' : 'personne'}, ${recipe.ingredientsFromInventory.length} ingrédients de votre stock${
        missingEssential.length > 0
          ? `. À acheter : ${missingEssential.map((entry) => entry.label).join(', ')}`
          : ''
      }`}
      accessibilityHint="Ouvre la recette et sa préparation"
      style={({ pressed }) => [styles.card, pressed && styles.pressed]}
    >
      <Badge label={presentation.label} level={presentation.level} />

      <Text style={[text.title, styles.title]}>{recipe.recipeName}</Text>
      <Text style={[text.small, styles.summary]}>{recipe.summary}</Text>

      {/* Trois mesures alignées, dans la voix machine : le temps, les convives,
          ce que la recette prend déjà chez vous. */}
      <View style={styles.metaRow}>
        <Text style={styles.meta}>{recipe.preparationTime} min</Text>
        <Text style={styles.meta}>{recipe.servings} pers.</Text>
        <Text style={styles.meta}>{recipe.ingredientsFromInventory.length} du stock</Text>
      </View>

      {missingEssential.length > 0 ? (
        <Text style={[text.small, styles.missing]}>
          À acheter : {missingEssential.map((entry) => entry.label).join(', ')}
        </Text>
      ) : null}

      {recipe.substitutions.length > 0 ? (
        <Text style={[text.small, styles.detail]}>
          {recipe.substitutions.map((entry) => `${entry.original} → ${entry.replacedBy}`).join(' · ')}
        </Text>
      ) : null}

      {usedDesired.length > 0 ? (
        <Text style={[text.small, styles.detail]}>Utilise aussi : {usedDesired.join(', ')}</Text>
      ) : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl },
  card: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    padding: spacing.lg,
    marginBottom: spacing.md,
  },
  pressed: { backgroundColor: colors.surfaceSunk },
  title: { marginTop: spacing.md },
  summary: { marginTop: spacing.xs },
  metaRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.lg,
    marginTop: spacing.md,
    paddingTop: spacing.md,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  meta: { fontFamily: font.monoMedium, fontSize: 11, lineHeight: 15, color: colors.ink },
  missing: { marginTop: spacing.md, color: thermal.soon.line, fontFamily: font.sansMedium },
  detail: { marginTop: spacing.xs },

  notice: { marginBottom: spacing.md },
  noticeHead: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  noticeTitle: { marginTop: spacing.sm },
  noticeLine: { marginTop: spacing.xs },
  noticeFooter: { marginTop: spacing.sm },
});
