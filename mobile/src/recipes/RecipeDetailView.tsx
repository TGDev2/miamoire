import { useRouter } from 'expo-router';
import React, { useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { api } from '../api/client';
import type {
  ConfirmationResponse,
  ConsumptionLine,
  Recipe,
  RecipeIngredient,
} from '../api/types';
import { Badge, Button, Card, ErrorBanner, SectionTitle } from '../components/ui';
import { colors, feasibilityPresentation, radius, spacing } from '../theme';

/**
 * L'écran d'une recette, qu'elle vienne d'une génération ou des enregistrées.
 *
 * Il était écrit dans `app/recipe/[index].tsx` et n'y servait qu'aux
 * propositions du moment. Le dupliquer pour les recettes conservées aurait
 * dédoublé la déduction de stock — la partie qu'il vaut le mieux n'avoir écrite
 * qu'une fois.
 */
export function RecipeDetailView({
  recipe,
  operationKey,
  confirmation,
  onConfirmed,
  savedId,
  onSavedChange,
}: {
  recipe: Recipe;
  /** Rend la confirmation rejouable : la même clé ne déduit qu'une fois. */
  operationKey: string;
  confirmation?: ConfirmationResponse;
  onConfirmed: (result: ConfirmationResponse) => void;
  /** Identifiant de l'enregistrement, `null` si la recette n'est pas conservée. */
  savedId: string | null;
  onSavedChange: (id: string | null) => void;
}) {
  const router = useRouter();

  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [saving, setSaving] = useState(false);

  /**
   * Les quantités réellement utilisées, saisies par l'utilisateur.
   *
   * La recette dit 250 g de crème ; on en a mis 180, ou 300. Déduire la valeur
   * proposée ferait mentir le stock à chaque repas, et c'est le stock qui pilote
   * ensuite les alertes et les propositions suivantes.
   */
  const [amounts, setAmounts] = useState<Record<string, string>>(() =>
    Object.fromEntries(
      recipe.stockConsumptionProposal.map((line) => [line.key, String(line.quantity)]),
    ),
  );

  const confirmed = confirmation !== undefined;
  const presentation = feasibilityPresentation[recipe.feasibility] ?? feasibilityPresentation.ready;

  /** Les lignes telles qu'elles seront envoyées : une quantité à zéro n'en est plus une. */
  function editedLines(): ConsumptionLine[] {
    return recipe.stockConsumptionProposal
      .map((line) => ({ ...line, quantity: parseQuantity(amounts[line.key]) }))
      .filter((line): line is ConsumptionLine => line.quantity !== null && line.quantity > 0);
  }

  function resetAmounts() {
    setAmounts(
      Object.fromEntries(
        recipe.stockConsumptionProposal.map((line) => [line.key, String(line.quantity)]),
      ),
    );
  }

  /**
   * Rien n'est retiré du stock tant que l'utilisateur n'a pas validé cet écran :
   * l'IA propose, l'utilisateur décide — et corrige les quantités.
   */
  function askConfirmation() {
    const lines = editedLines();

    if (lines.length === 0) {
      setError('Indiquez au moins une quantité à retirer, ou laissez cette recette telle quelle.');
      return;
    }

    const invalid = recipe.stockConsumptionProposal.find(
      (line) => parseQuantity(amounts[line.key]) === null,
    );

    if (invalid) {
      setError(`Quantité illisible pour « ${invalid.label} ».`);
      return;
    }

    const summary = lines.map((line) => `• ${line.quantity} ${line.unit} de ${line.label}`).join('\n');

    Alert.alert(
      'Retirer du stock ?',
      `Les quantités suivantes seront déduites :\n\n${summary}\n\nLes lots qui périment le plus tôt sont utilisés en premier, et les paquets déjà entamés avant les paquets fermés.`,
      [
        { text: 'Annuler', style: 'cancel' },
        { text: 'Confirmer', onPress: () => applyConsumption(lines) },
      ],
    );
  }

  async function applyConsumption(lines: ConsumptionLine[]) {
    setError(null);
    setBusy(true);
    try {
      // La même clé à chaque tentative : réessayer après une réponse perdue ne
      // déduit rien de plus, le serveur reconnaît le geste et rejoue sa réponse.
      const result = await api.confirmRecipe(operationKey, recipe.recipeName, lines);
      onConfirmed(result);

      const detail = result.lines
        .map(
          (line) =>
            `${line.label} : ${line.applied} ${line.unit}${
              line.quantityWasEstimated ? ' (quantité estimée, à vérifier)' : ''
            }`,
        )
        .join('\n');

      Alert.alert(result.replayed ? 'Déjà retiré du stock' : 'Stock mis à jour', detail, [
        { text: 'Voir mon stock', onPress: () => router.replace('/(tabs)') },
        { text: 'Rester ici', style: 'cancel' },
      ]);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Déduction impossible.');
    } finally {
      setBusy(false);
    }
  }

  async function toggleSaved() {
    setError(null);
    setSaving(true);
    try {
      if (savedId === null) {
        const saved = await api.saveRecipe(recipe);
        onSavedChange(saved.id);
      } else {
        await api.deleteSavedRecipe(savedId);
        onSavedChange(null);
      }
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Enregistrement impossible.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
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
        {recipe.includesSideDish ? ' · avec accompagnement' : ''}
      </Text>

      {/* L'état « enregistrée » était rendu en variante ghost — sans bordure ni
          fond, il se lisait comme une phrase et non comme un bouton, si bien que
          rien ne distinguait une recette conservée d'une qui ne l'était pas. */}
      <Button
        label={savedId === null ? 'Enregistrer cette recette' : '✓ Enregistrée — toucher pour retirer'}
        variant="secondary"
        onPress={toggleSaved}
        loading={saving}
        style={[styles.save, savedId !== null && styles.saved]}
      />
      <Text style={styles.help}>
        {savedId === null
          ? 'Conservée sur le serveur, elle reste accessible à tout le foyer, même après avoir quitté cet écran.'
          : 'Enregistrée : vous la retrouverez dans « Mes recettes » depuis l\'onglet Cuisiner.'}
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
          <Text style={styles.help}>
            Ajustez chaque quantité si vous en avez utilisé plus ou moins. À zéro, l'ingrédient
            n'est pas déduit.
          </Text>

          {recipe.stockConsumptionProposal.map((line) => (
            <View key={`prop-${line.key}`} style={styles.proposalRow}>
              <View style={styles.flex}>
                <Text style={styles.proposalLabel}>{line.label}</Text>
                {parseQuantity(amounts[line.key]) !== line.quantity ? (
                  <Text style={styles.proposalOriginal}>
                    recette : {line.quantity} {line.unit}
                  </Text>
                ) : null}
              </View>
              <TextInput
                value={amounts[line.key] ?? ''}
                onChangeText={(value) =>
                  setAmounts((current) => ({ ...current, [line.key]: value }))
                }
                keyboardType="decimal-pad"
                editable={!confirmed}
                selectTextOnFocus={!confirmed}
                style={[styles.amountInput, confirmed && styles.amountLocked]}
              />
              <Text style={styles.proposalUnit}>{line.unit}</Text>
            </View>
          ))}

          {!confirmed ? (
            <Button
              label="Revenir aux quantités de la recette"
              variant="ghost"
              onPress={resetAmounts}
              style={styles.reset}
            />
          ) : null}

          <Text style={styles.help}>
            Les lots qui périment le plus tôt sont utilisés en premier, et les paquets déjà
            entamés avant les paquets fermés. Le retrait est appliqué en une seule fois : si
            le stock a changé entre-temps, rien n'est déduit.
          </Text>

          {confirmation?.lines.some((line) => line.quantityWasEstimated) ? (
            <Text style={styles.warning}>
              Une des quantités retirées était estimée, pas mesurée. Vérifiez ce qu'il reste
              réellement après le repas.
            </Text>
          ) : null}

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

/** « 1,5 » comme « 1.5 ». Vide vaut zéro ; illisible vaut `null`, et se signale. */
function parseQuantity(raw: string | undefined): number | null {
  const text = (raw ?? '').trim();

  if (text === '') return 0;

  const value = Number(text.replace(',', '.'));

  return Number.isFinite(value) && value >= 0 ? Math.round(value * 1000) / 1000 : null;
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
  flex: { flex: 1 },
  title: { fontSize: 24, fontWeight: '800', color: colors.ink, marginTop: spacing.md },
  summary: { fontSize: 15, color: colors.inkMuted, marginTop: spacing.sm, lineHeight: 21 },
  meta: { fontSize: 14, color: colors.ink, marginTop: spacing.md, fontWeight: '600' },
  save: { marginTop: spacing.lg },
  saved: { backgroundColor: colors.primarySoft, borderColor: colors.primary },
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
    alignItems: 'center',
    gap: spacing.sm,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  proposalLabel: { fontSize: 15, color: colors.ink },
  proposalOriginal: { fontSize: 12, color: colors.inkMuted, marginTop: 2 },
  proposalUnit: { fontSize: 14, color: colors.inkMuted, width: 36 },
  amountInput: {
    width: 76,
    backgroundColor: colors.surfaceMuted,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.sm,
    paddingHorizontal: spacing.sm,
    paddingVertical: 8,
    fontSize: 16,
    fontWeight: '700',
    color: colors.danger,
    textAlign: 'right',
  },
  amountLocked: { color: colors.inkMuted, opacity: 0.7 },
  reset: { marginTop: spacing.xs },
  help: { fontSize: 13, color: colors.inkMuted, marginTop: spacing.md, lineHeight: 19 },
  warning: { fontSize: 13, color: colors.accent, marginTop: spacing.md, lineHeight: 19 },
  confirm: { marginTop: spacing.lg },
});
