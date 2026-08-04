import { useRouter } from 'expo-router';
import React, { useEffect, useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError, api, generateOperationKey } from '../api/client';
import type {
  ConfirmationResponse,
  ConsumptionLine,
  MealSlot,
  ProductCutValue,
  Recipe,
  RecipeIngredient,
  RecipePlan,
  Reference,
} from '../api/types';
import { Badge, Button, Card, Chip, DateField, ErrorBanner, SectionTitle } from '../components/ui';
import { colors, feasibilityPresentation, font, mealSlotOptions, radius, spacing, text, thermal } from '../theme';

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
  plan,
  operationKey,
  confirmation,
  onConfirmed,
  savedId,
  onSavedChange,
  mealPlanEntryId,
}: {
  recipe: Recipe;
  /**
   * L'état actuel, recalculé — pour une recette fraîche, celui attaché par la
   * génération ; pour une recette enregistrée, celui tout juste refetché par
   * l'écran appelant. Jamais celui figé dans le JSON enregistré : c'est la
   * seule source de vérité pour le badge et pour bloquer la confirmation.
   */
  plan: RecipePlan;
  /** Rend la confirmation rejouable : la même clé ne déduit qu'une fois. */
  operationKey: string;
  confirmation?: ConfirmationResponse;
  onConfirmed: (result: ConfirmationResponse) => void;
  /** Identifiant de l'enregistrement, `null` si la recette n'est pas conservée. */
  savedId: string | null;
  onSavedChange: (id: string | null) => void;
  /** Le repas planifié dont cette confirmation tient lieu — sa réservation est remplacée par cette consommation. */
  mealPlanEntryId?: string;
}) {
  const router = useRouter();

  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [saving, setSaving] = useState(false);
  // Session seulement : un double ajout est sans risque (le serveur
  // dédoublonne par variante), donc rien n'oblige à retenir cet état au-delà
  // de cet écran.
  const [addedToList, setAddedToList] = useState(false);
  const [addingToList, setAddingToList] = useState(false);

  const [planningOpen, setPlanningOpen] = useState(false);
  const [planDate, setPlanDate] = useState<string | null>(null);
  const [planSlot, setPlanSlot] = useState<MealSlot | null>(null);
  const [addingToPlan, setAddingToPlan] = useState(false);
  const [addedToPlan, setAddedToPlan] = useState(false);

  // Une seule clé à la fois : évite qu'un double appui déclenche deux
  // résolutions concurrentes vers deux fiches différentes.
  const [resolvingKey, setResolvingKey] = useState<string | null>(null);

  /**
   * L'apprentissage par la recette.
   *
   * Quand l'inventaire ignore la forme d'un aliment, le modèle DÉCLARE celle
   * qu'il a supposée (`assumedCut`). On l'affiche à l'endroit exact où elle se
   * démentit — la fiche recette, là où le défaut « escalope au lieu de tranches »
   * a été vu — et un appui l'enregistre sur la variante, pour toujours.
   */
  const [reference, setReference] = useState<Reference | null>(null);
  const [correcting, setCorrecting] = useState<{
    key: string;
    itemId: string;
    options: Array<{ value: ProductCutValue; label: string }>;
  } | null>(null);
  const [correctionBusy, setCorrectionBusy] = useState(false);
  /** Clé d'inventaire → libellé retenu, pour dire ce qui vient d'être appris. */
  const [learned, setLearned] = useState<Record<string, string>>({});

  useEffect(() => {
    let cancelled = false;

    // Les libellés des découpes viennent du serveur, jamais du client. Un échec
    // n'a aucune conséquence : la ligne « supposé » ne s'affiche simplement pas.
    api
      .reference()
      .then((found) => {
        if (!cancelled) setReference(found);
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, []);

  const cutLabels = new Map((reference?.productCuts ?? []).map((option) => [option.value, option.label]));

  /** Ouvre le choix : les découpes plausibles pour CET aliment, telles que le serveur les connaît. */
  async function openCorrection(key: string) {
    setCorrectionBusy(true);
    try {
      const { id } = await api.stockItemIdForIngredient(key);
      const item = await api.stockItem(id);

      setCorrecting({
        key,
        itemId: id,
        options: (reference?.productCuts ?? []).filter(
          (option) =>
            item.variant.plausibleCuts.includes(option.value) || item.variant.cut === option.value,
        ),
      });
    } catch (exception) {
      Alert.alert(
        'Correction impossible',
        exception instanceof ApiError || exception instanceof Error
          ? exception.message
          : 'Impossible d\'ouvrir cet aliment.',
      );
    } finally {
      setCorrectionBusy(false);
    }
  }

  async function applyCorrection(value: ProductCutValue, label: string) {
    if (null === correcting) return;

    setCorrectionBusy(true);
    try {
      await api.updateStockItem(correcting.itemId, { cut: value }, generateOperationKey());
      setLearned((current) => ({ ...current, [correcting.key]: label }));
      setCorrecting(null);
    } catch (exception) {
      Alert.alert(
        'Correction impossible',
        exception instanceof ApiError || exception instanceof Error
          ? exception.message
          : 'Impossible d\'enregistrer cette découpe.',
      );
    } finally {
      setCorrectionBusy(false);
    }
  }

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
  const presentation = feasibilityPresentation[plan.status] ?? feasibilityPresentation.ready;
  // Ces trois états ne se lisent jamais comme sûrs : mieux vaut ne pas confirmer
  // que de laisser croire qu'une composition inconnue ou un aliment introuvable
  // ne change rien.
  const blocked = ['incompatible', 'safety_unknown', 'needs_reassociation'].includes(plan.status);

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
      const result = await api.confirmRecipe(
        operationKey,
        recipe.recipeName,
        lines,
        savedId !== null ? { savedId } : { recipe },
        mealPlanEntryId,
      );
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

  /** Tout ou rien : pas de sélection ligne par ligne, un retrait se fait depuis l'écran liste. */
  async function addMissingToList() {
    setError(null);
    setAddingToList(true);
    try {
      await api.addShoppingListItems(
        recipe.missingIngredients.map((ingredient) => ({
          label: ingredient.label,
          quantity: ingredient.quantity,
          unit: ingredient.unit,
        })),
      );
      setAddedToList(true);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Ajout à la liste de courses impossible.');
    } finally {
      setAddingToList(false);
    }
  }

  /** Une réservation souple, jamais une déduction : rien n'est retiré du stock avant une vraie préparation. */
  async function addToMealPlan() {
    if (null === planDate || null === planSlot) {
      setError('Choisissez une date et un moment de la journée.');
      return;
    }

    setError(null);
    setAddingToPlan(true);
    try {
      await api.addMealPlanEntry(
        savedId !== null
          ? { date: planDate, mealSlot: planSlot, savedRecipeId: savedId }
          : { date: planDate, mealSlot: planSlot, recipe },
      );
      setAddedToPlan(true);
      setPlanningOpen(false);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Ajout au plan de repas impossible.');
    } finally {
      setAddingToPlan(false);
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

  /** Ouvre la fiche du lot le plus urgent de cet aliment — le stock a pu changer depuis la génération. */
  async function openIngredient(key: string) {
    setResolvingKey(key);
    try {
      const { id } = await api.stockItemIdForIngredient(key);
      router.push(`/item/${id}`);
    } catch (exception) {
      Alert.alert(
        'Aliment introuvable',
        exception instanceof ApiError || exception instanceof Error
          ? exception.message
          : 'Impossible d\'ouvrir la fiche de cet aliment.',
      );
    } finally {
      setResolvingKey(null);
    }
  }

  return (
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ErrorBanner message={error} />

      <Badge label={presentation.label} level={presentation.level} />
      <Text style={[text.display, styles.title]}>{recipe.recipeName}</Text>
      <Text style={[text.bodyMuted, styles.summary]}>{recipe.summary}</Text>
      <View style={styles.metaRow}>
        <Text style={styles.meta}>{recipe.preparationTime} min</Text>
        <Text style={styles.meta}>
          {recipe.servings} {recipe.servings > 1 ? 'personnes' : 'personne'}
        </Text>
        {recipe.includesSideDish ? <Text style={styles.meta}>avec accompagnement</Text> : null}
      </View>

      {recipe.utensils && recipe.utensils.length > 0 ? (
        <>
          <SectionTitle>Ustensiles</SectionTitle>
          <Text style={text.small}>{recipe.utensils.join(' · ')}</Text>
        </>
      ) : null}

      {/* L'état « enregistrée » était rendu en variante ghost — sans bordure ni
          fond, il se lisait comme une phrase et non comme un bouton, si bien que
          rien ne distinguait une recette conservée d'une qui ne l'était pas. */}
      <Button
        label={savedId === null ? 'Enregistrer cette recette' : 'Enregistrée — retirer'}
        variant="secondary"
        icon={savedId === null ? 'content-save-outline' : 'check'}
        onPress={toggleSaved}
        loading={saving}
        style={[styles.save, savedId !== null && styles.saved]}
      />
      <Text style={styles.help}>
        {savedId === null
          ? 'Conservée sur le serveur, elle reste accessible à tout le foyer, même après avoir quitté cet écran.'
          : 'Enregistrée : vous la retrouverez dans « Mes recettes » depuis l\'onglet Cuisiner.'}
      </Text>

      {undefined === mealPlanEntryId ? (
        <>
          <Button
            label={addedToPlan ? 'Ajoutée à votre plan de repas' : 'Ajouter à mon plan de repas'}
            variant="secondary"
            icon={addedToPlan ? 'check' : 'calendar-blank-outline'}
            onPress={() => setPlanningOpen((current) => !current)}
            disabled={addedToPlan}
            style={styles.save}
          />
          {planningOpen ? (
            <Card style={styles.planCard}>
              <DateField label="Date du repas" value={planDate} onChange={setPlanDate} />
              <Text style={styles.fieldLabel}>Moment de la journée</Text>
              <View style={styles.chips}>
                {mealSlotOptions.map((option) => (
                  <Chip
                    key={option.value}
                    label={option.label}
                    selected={planSlot === option.value}
                    onPress={() => setPlanSlot(option.value)}
                  />
                ))}
              </View>
              <Button
                label="Ajouter au plan"
                onPress={addToMealPlan}
                loading={addingToPlan}
                style={styles.spaced}
              />
            </Card>
          ) : null}
        </>
      ) : null}

      <SectionTitle>Depuis votre stock</SectionTitle>
      {recipe.ingredientsFromInventory.map((ingredient, position) => {
        const key = ingredient.key;
        const assumed =
          undefined !== key && undefined !== ingredient.assumedCut && 'none' !== ingredient.assumedCut
            ? cutLabels.get(ingredient.assumedCut)
            : undefined;

        return (
          <View key={`inv-${position}`}>
            <IngredientRow
              ingredient={ingredient}
              onOpen={openIngredient}
              resolving={undefined !== key && key === resolvingKey}
            />

            {/* La supposition du modèle, montrée là où elle se dément. Une fois
                corrigée, la variante s'en souvient et toutes les recettes
                suivantes la connaîtront. */}
            {undefined !== key && undefined !== learned[key] ? (
              <Text style={styles.assumption}>Découpe enregistrée : {learned[key]}.</Text>
            ) : undefined !== assumed && undefined !== key ? (
              <Pressable
                onPress={() => openCorrection(key)}
                disabled={correctionBusy}
                accessibilityRole="button"
                accessibilityLabel={`Forme supposée : ${assumed}`}
                accessibilityHint="Corrige la découpe de cet aliment, une fois pour toutes"
              >
                <Text style={styles.assumption}>
                  Forme supposée : {assumed.toLowerCase()} — <Text style={styles.assumptionAction}>corriger</Text>
                </Text>
              </Pressable>
            ) : null}

            {undefined !== key && correcting?.key === key ? (
              <Card style={styles.correctionCard}>
                <Text style={text.small}>Sous quelle forme l'avez-vous réellement ?</Text>
                <View style={styles.correctionChips}>
                  {correcting.options.map((option) => (
                    <Chip
                      key={option.value}
                      label={option.label}
                      selected={false}
                      onPress={() => applyCorrection(option.value, option.label)}
                    />
                  ))}
                </View>
                <Text style={[text.small, styles.correctionHint]}>
                  L'application s'en souviendra : cet aliment gardera cette forme.
                </Text>
              </Card>
            ) : null}
          </View>
        );
      })}

      {recipe.missingIngredients.length > 0 ? (
        <>
          <SectionTitle>À compléter</SectionTitle>
          {recipe.missingIngredients.map((ingredient, position) => (
            <IngredientRow key={`missing-${position}`} ingredient={ingredient} missing />
          ))}
          <Button
            label={addedToList ? 'Ajoutés à la liste de courses' : 'Ajouter à la liste de courses'}
            variant="secondary"
            icon={addedToList ? 'check' : 'format-list-checks'}
            onPress={addMissingToList}
            loading={addingToList}
            disabled={addedToList}
            style={styles.addToList}
          />
        </>
      ) : null}

      {recipe.substitutions.length > 0 ? (
        <>
          <SectionTitle>Adaptations</SectionTitle>
          {recipe.substitutions.map((substitution, position) => (
            <Card key={`sub-${position}`} style={styles.substitutionCard}>
              <Text style={text.subheading}>
                {substitution.original} → {substitution.replacedBy}
              </Text>
              <Text style={[text.small, styles.substitutionReason]}>{substitution.reason}</Text>
            </Card>
          ))}
        </>
      ) : null}

      {/* Une préparation est une vraie séquence : le numéro y porte une
          information — l'ordre — et non une décoration. Il est composé dans la
          voix machine, en retrait, pour ne pas concurrencer l'instruction. */}
      <SectionTitle>Préparation</SectionTitle>
      {recipe.steps.map((step, position) => (
        <View key={`step-${position}`} style={styles.step}>
          <Text style={styles.stepNumber}>{String(position + 1).padStart(2, '0')}</Text>
          <Text style={[text.body, styles.flex]}>{step}</Text>
        </View>
      ))}

      <SectionTitle>Déduction du stock</SectionTitle>
      {blocked ? (
        <Text style={styles.warning}>
          {plan.reason ?? 'Cette recette ne peut pas être confirmée en toute sécurité pour le moment.'}
        </Text>
      ) : null}
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
              <Pressable
                style={styles.flex}
                onPress={() => openIngredient(line.key)}
                disabled={resolvingKey === line.key}
                accessibilityRole="button"
                accessibilityLabel={line.label}
                accessibilityHint="Ouvre la fiche de cet aliment"
              >
                <Text style={text.body}>{line.label}</Text>
                {parseQuantity(amounts[line.key]) !== line.quantity ? (
                  <Text style={[text.data, styles.proposalOriginal]}>
                    recette : {line.quantity} {line.unit}
                  </Text>
                ) : null}
              </Pressable>
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
            disabled={confirmed || blocked}
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
  onOpen,
  resolving = false,
}: {
  ingredient: RecipeIngredient;
  missing?: boolean;
  /** Absent pour les ingrédients manquants : ils n'ont pas de `key`, donc rien à ouvrir. */
  onOpen?: (key: string) => void;
  resolving?: boolean;
}) {
  const label = (
    <Text style={[styles.ingredientLabel, missing && styles.ingredientMissing]}>
      {ingredient.label}
      {!ingredient.essential ? ' (facultatif)' : ''}
    </Text>
  );

  return (
    <View style={styles.ingredient}>
      {undefined !== ingredient.key && undefined !== onOpen ? (
        <Pressable
          style={styles.flex}
          onPress={() => onOpen(ingredient.key as string)}
          disabled={resolving}
          accessibilityRole="button"
          accessibilityLabel={ingredient.label}
          accessibilityHint="Ouvre la fiche de cet aliment"
        >
          {label}
        </Pressable>
      ) : (
        label
      )}
      <Text style={styles.ingredientQuantity}>
        {ingredient.quantity} {ingredient.unit}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2 },
  flex: { flex: 1 },
  title: { marginTop: spacing.md },
  summary: { marginTop: spacing.sm },
  metaRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.lg,
    marginTop: spacing.lg,
    paddingTop: spacing.md,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  meta: { fontFamily: font.monoMedium, fontSize: 11, lineHeight: 15, color: colors.ink },
  save: { marginTop: spacing.lg },
  saved: { backgroundColor: colors.surfaceSunk },
  planCard: { marginTop: spacing.md },
  fieldLabel: { ...text.label, marginTop: spacing.md },
  chips: { flexDirection: 'row', flexWrap: 'wrap', marginTop: spacing.sm },
  spaced: { marginTop: spacing.md },
  ingredient: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  ingredientLabel: { ...text.body, flex: 1 },
  ingredientMissing: { color: thermal.soon.line },
  ingredientQuantity: { fontFamily: font.monoMedium, fontSize: 12, color: colors.ink },
  assumption: { ...text.small, marginTop: -spacing.xs, marginBottom: spacing.sm },
  assumptionAction: { fontFamily: font.monoMedium, color: colors.ink },
  correctionCard: { marginBottom: spacing.md },
  correctionChips: { flexDirection: 'row', flexWrap: 'wrap', marginTop: spacing.sm },
  correctionHint: { marginTop: spacing.xs },
  addToList: { marginTop: spacing.md },
  substitutionCard: { marginBottom: spacing.sm },
  substitutionReason: { marginTop: spacing.xs },
  step: { flexDirection: 'row', gap: spacing.md, marginBottom: spacing.lg },
  stepNumber: {
    fontFamily: font.monoMedium,
    fontSize: 12,
    lineHeight: 22,
    color: colors.inkMuted,
    width: 22,
  },
  proposalRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  proposalOriginal: { marginTop: 2 },
  proposalUnit: { ...text.data, width: 36 },
  amountInput: {
    width: 76,
    backgroundColor: colors.surfaceSunk,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.sm,
    paddingHorizontal: spacing.sm,
    paddingVertical: 8,
    fontFamily: font.monoMedium,
    fontSize: 14,
    color: colors.ink,
    textAlign: 'right',
  },
  amountLocked: { color: colors.inkMuted, opacity: 0.7 },
  reset: { marginTop: spacing.xs },
  help: { ...text.small, marginTop: spacing.md },
  warning: { ...text.small, color: thermal.soon.line, marginTop: spacing.md },
  confirm: { marginTop: spacing.lg },
});
