import { useLocalSearchParams, useRouter } from 'expo-router';
import React, { useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';

import { api } from '../../src/api/client';
import type { RecipeIngredient } from '../../src/api/types';
import { Badge, Button, Card, EmptyState, ErrorBanner, SectionTitle } from '../../src/components/ui';
import { useRecipes } from '../../src/recipes/RecipeContext';
import { colors, feasibilityPresentation, radius, spacing } from '../../src/theme';

export default function RecipeDetailScreen() {
  const router = useRouter();
  const { index } = useLocalSearchParams<{ index: string }>();
  const { suggestion } = useRecipes();

  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirmed, setConfirmed] = useState(false);

  const recipe = suggestion?.recipes[Number(index)];

  if (!recipe) {
    return (
      <EmptyState
        title="Recette introuvable"
        description="Générez de nouvelles propositions depuis l'onglet Cuisiner."
      />
    );
  }

  const presentation = feasibilityPresentation[recipe.feasibility] ?? feasibilityPresentation.ready;

  /**
   * Rien n'est retiré du stock tant que l'utilisateur n'a pas validé cet écran :
   * l'IA propose, l'utilisateur décide.
   */
  function askConfirmation() {
    const lines = recipe!.stockConsumptionProposal
      .map((line) => `• ${line.quantity} ${line.unit} de ${line.label}`)
      .join('\n');

    Alert.alert(
      'Retirer du stock ?',
      `Les quantités suivantes seront déduites :\n\n${lines}\n\nLes lots qui périment le plus tôt sont utilisés en premier.`,
      [
        { text: 'Annuler', style: 'cancel' },
        { text: 'Confirmer', onPress: applyConsumption },
      ],
    );
  }

  async function applyConsumption() {
    setError(null);
    setBusy(true);
    try {
      const result = await api.confirmRecipe(
        recipe!.recipeName,
        recipe!.stockConsumptionProposal,
      );
      setConfirmed(true);

      const detail = result.lines
        .map(
          (line) =>
            `${line.label} : ${line.applied} ${line.unit}${
              line.shortfall > 0 ? ` (il manquait ${line.shortfall})` : ''
            }`,
        )
        .join('\n');

      Alert.alert('Stock mis à jour', detail, [
        { text: 'Voir mon stock', onPress: () => router.replace('/(tabs)') },
        { text: 'Rester ici', style: 'cancel' },
      ]);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Déduction impossible.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <ErrorBanner message={error} />

      <Badge
        label={presentation.label}
        color={presentation.color}
        background={presentation.background}
      />
      <Text style={styles.title}>{recipe.recipeName}</Text>
      <Text style={styles.summary}>{recipe.summary}</Text>
      <Text style={styles.meta}>
        {recipe.preparationTime} min · {recipe.servings}{' '}
        {recipe.servings > 1 ? 'personnes' : 'personne'}
      </Text>

      <SectionTitle>Depuis votre stock</SectionTitle>
      {recipe.ingredientsFromInventory.map((ingredient, position) => (
        <IngredientRow key={`inv-${position}`} ingredient={ingredient} />
      ))}

      {recipe.missingIngredients.length > 0 ? (
        <>
          <SectionTitle>À compléter</SectionTitle>
          {recipe.missingIngredients.map((ingredient, position) => (
            <IngredientRow key={`missing-${position}`} ingredient={ingredient} missing />
          ))}
        </>
      ) : null}

      {recipe.substitutions.length > 0 ? (
        <>
          <SectionTitle>Adaptations</SectionTitle>
          {recipe.substitutions.map((substitution, position) => (
            <Card key={`sub-${position}`} style={styles.substitutionCard}>
              <Text style={styles.substitutionTitle}>
                {substitution.original} → {substitution.replacedBy}
              </Text>
              <Text style={styles.substitutionReason}>{substitution.reason}</Text>
            </Card>
          ))}
        </>
      ) : null}

      <SectionTitle>Préparation</SectionTitle>
      {recipe.steps.map((step, position) => (
        <View key={`step-${position}`} style={styles.step}>
          <View style={styles.stepNumber}>
            <Text style={styles.stepNumberText}>{position + 1}</Text>
          </View>
          <Text style={styles.stepText}>{step}</Text>
        </View>
      ))}

      <SectionTitle>Déduction du stock</SectionTitle>
      {recipe.stockConsumptionProposal.length === 0 ? (
        <Text style={styles.help}>Cette recette ne consomme rien de votre inventaire.</Text>
      ) : (
        <Card>
          {recipe.stockConsumptionProposal.map((line, position) => (
            <View key={`prop-${position}`} style={styles.proposalRow}>
              <Text style={styles.proposalLabel}>{line.label}</Text>
              <Text style={styles.proposalQuantity}>
                −{line.quantity} {line.unit}
              </Text>
            </View>
          ))}
          <Text style={styles.help}>
            Les lots qui périment le plus tôt seront utilisés en premier.
          </Text>
          <Button
            label={confirmed ? 'Déjà retiré du stock' : 'Retirer ces quantités du stock'}
            onPress={askConfirmation}
            loading={busy}
            disabled={confirmed}
            style={styles.confirm}
          />
        </Card>
      )}
    </ScrollView>
  );
}

function IngredientRow({
  ingredient,
  missing = false,
}: {
  ingredient: RecipeIngredient;
  missing?: boolean;
}) {
  return (
    <View style={styles.ingredient}>
      <Text style={[styles.ingredientLabel, missing && styles.ingredientMissing]}>
        {ingredient.label}
        {!ingredient.essential ? ' (facultatif)' : ''}
      </Text>
      <Text style={styles.ingredientQuantity}>
        {ingredient.quantity} {ingredient.unit}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2 },
  title: { fontSize: 24, fontWeight: '800', color: colors.ink, marginTop: spacing.md },
  summary: { fontSize: 15, color: colors.inkMuted, marginTop: spacing.sm, lineHeight: 21 },
  meta: { fontSize: 14, color: colors.ink, marginTop: spacing.md, fontWeight: '600' },
  ingredient: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: spacing.sm + 2,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  ingredientLabel: { fontSize: 15, color: colors.ink, flex: 1 },
  ingredientMissing: { color: colors.accent },
  ingredientQuantity: { fontSize: 15, color: colors.inkMuted, fontWeight: '600' },
  substitutionCard: { marginBottom: spacing.sm },
  substitutionTitle: { fontSize: 15, fontWeight: '700', color: colors.ink },
  substitutionReason: { fontSize: 13, color: colors.inkMuted, marginTop: spacing.xs, lineHeight: 19 },
  step: { flexDirection: 'row', gap: spacing.md, marginBottom: spacing.md },
  stepNumber: {
    width: 26,
    height: 26,
    borderRadius: 13,
    backgroundColor: colors.primarySoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  stepNumberText: { fontSize: 13, fontWeight: '700', color: colors.primary },
  stepText: { flex: 1, fontSize: 15, color: colors.ink, lineHeight: 22 },
  proposalRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  proposalLabel: { fontSize: 15, color: colors.ink },
  proposalQuantity: { fontSize: 15, fontWeight: '700', color: colors.danger },
  help: { fontSize: 13, color: colors.inkMuted, marginTop: spacing.md, lineHeight: 19 },
  confirm: { marginTop: spacing.lg },
});
