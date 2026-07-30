import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useState } from 'react';
import { ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { api } from '../src/api/client';
import type { MealPlanEntry, MealPlanOverview, MealSlot, SavedRecipe } from '../src/api/types';
import {
  Badge,
  Button,
  Card,
  Chip,
  DateField,
  EmptyState,
  ErrorBanner,
  Loader,
  SectionTitle,
} from '../src/components/ui';
import { colors, feasibilityPresentation, mealSlotOptions, radius, spacing } from '../src/theme';

const MAX_ACTIVE_ENTRIES = 4;

/**
 * Un plan très court — quatre repas au plus, jamais un calendrier. Le stock
 * n'est jamais déduit ici : chaque ligne dit ce qui est déjà couvert et ce que
 * les repas plus proches ont déjà réclamé, jusqu'à ce que la préparation
 * réelle remplace cette réservation.
 */
export default function MealPlanScreen() {
  const router = useRouter();

  const [overview, setOverview] = useState<MealPlanOverview | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [newDate, setNewDate] = useState<string | null>(null);
  const [newSlot, setNewSlot] = useState<MealSlot | null>(null);
  const [freeformLabel, setFreeformLabel] = useState('');
  const [creating, setCreating] = useState(false);

  const [pickingRecipe, setPickingRecipe] = useState(false);
  const [savedRecipes, setSavedRecipes] = useState<SavedRecipe[] | null>(null);

  const load = useCallback(() => {
    setLoadError(null);
    return api
      .mealPlan()
      .then(setOverview)
      .catch((exception) => {
        setLoadError(exception instanceof Error ? exception.message : 'Chargement impossible.');
      });
  }, []);

  // Un autre membre du foyer peut avoir modifié le plan depuis son téléphone.
  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load]),
  );

  function resetForm() {
    setFreeformLabel('');
    setPickingRecipe(false);
  }

  async function createEntry(content: { savedRecipeId: string } | { label: string }) {
    if (null === newDate || null === newSlot) {
      setActionError('Choisissez une date et un moment de la journée.');
      return;
    }

    setActionError(null);
    setCreating(true);
    try {
      await api.addMealPlanEntry({ date: newDate, mealSlot: newSlot, ...content });
      resetForm();
      await load();
    } catch (exception) {
      setActionError(exception instanceof Error ? exception.message : 'Ajout impossible.');
    } finally {
      setCreating(false);
    }
  }

  async function deleteEntry(id: string) {
    setActionError(null);
    setBusy(true);
    try {
      await api.deleteMealPlanEntry(id);
      await load();
    } catch (exception) {
      setActionError(exception instanceof Error ? exception.message : 'Retrait impossible.');
    } finally {
      setBusy(false);
    }
  }

  async function addShortfallToShoppingList() {
    setActionError(null);
    setBusy(true);
    try {
      await api.addMealPlanShortfallToShoppingList();
      await load();
    } catch (exception) {
      setActionError(exception instanceof Error ? exception.message : 'Ajout à la liste de courses impossible.');
    } finally {
      setBusy(false);
    }
  }

  function openRecipePicker() {
    setPickingRecipe(true);
    if (null === savedRecipes) {
      void api
        .savedRecipes()
        .then((result) => setSavedRecipes(result.recipes))
        .catch((exception) => {
          setActionError(exception instanceof Error ? exception.message : 'Chargement impossible.');
        });
    }
  }

  function cookEntry(entry: MealPlanEntry) {
    if ('saved_recipe' === entry.kind && null !== entry.savedRecipeId) {
      router.push({ pathname: '/saved/[id]', params: { id: entry.savedRecipeId, mealPlanEntryId: entry.id } });
    } else if ('snapshot' === entry.kind) {
      router.push(`/meal-plan-entry/${entry.id}`);
    }
  }

  if (null === overview && !loadError) {
    return <Loader label="Chargement de votre plan de repas…" />;
  }

  if (loadError) {
    return (
      <View style={styles.container}>
        <ErrorBanner message={loadError} />
      </View>
    );
  }

  const atCapacity = (overview?.entries.length ?? 0) >= MAX_ACTIVE_ENTRIES;

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <ErrorBanner message={actionError} />

      {0 === overview?.entries.length ? (
        <EmptyState
          title="Rien de planifié pour l'instant"
          description="Ajoutez jusqu'à quatre repas à venir : une recette enregistrée, une proposition de Cuisiner, ou simplement un repas libre."
        />
      ) : (
        (overview?.entries ?? []).map((entry) => (
          <MealPlanEntryCard
            key={entry.id}
            entry={entry}
            busy={busy}
            onCook={() => cookEntry(entry)}
            onDelete={() => deleteEntry(entry.id)}
          />
        ))
      )}

      {overview && overview.shortfallSummary.length > 0 ? (
        <Card style={styles.card}>
          <SectionTitle>Ce qu'il manque au total</SectionTitle>
          {overview.shortfallSummary.map((line, position) => (
            <Text key={position} style={styles.shortfallLine}>
              • {line.label} : {line.quantity} {line.unit}
            </Text>
          ))}
          <Button
            label="Ajouter tout à la liste de courses"
            onPress={addShortfallToShoppingList}
            disabled={busy}
            style={styles.spaced}
          />
        </Card>
      ) : null}

      {overview && overview.unclaimedUrgentItems.length > 0 ? (
        <Card style={styles.card}>
          <SectionTitle>Urgents, mais pas encore prévus</SectionTitle>
          {overview.unclaimedUrgentItems.map((candidate) => (
            <View key={candidate.stockItem.id} style={styles.urgentRow}>
              <Text style={styles.urgentLabel}>{candidate.stockItem.variant.culinaryLabel}</Text>
              <View style={styles.reasons}>
                {candidate.reasons.map((reason) => (
                  <Badge key={reason.code} label={reason.label} color={colors.accent} background={colors.accentSoft} />
                ))}
              </View>
            </View>
          ))}
        </Card>
      ) : null}

      <Card style={styles.card}>
        <SectionTitle>Ajouter un repas</SectionTitle>
        {atCapacity ? (
          <Text style={styles.help}>
            Votre plan est complet ({MAX_ACTIVE_ENTRIES} repas). Retirez-en un avant d'en ajouter un autre.
          </Text>
        ) : (
          <>
            <DateField label="Date du repas" value={newDate} onChange={setNewDate} />
            <Text style={styles.fieldLabel}>Moment de la journée</Text>
            <View style={styles.chips}>
              {mealSlotOptions.map((option) => (
                <Chip
                  key={option.value}
                  label={option.label}
                  selected={newSlot === option.value}
                  onPress={() => setNewSlot(option.value)}
                />
              ))}
            </View>

            {pickingRecipe ? (
              <>
                <Text style={styles.fieldLabel}>Choisir une recette enregistrée</Text>
                {null === savedRecipes ? (
                  <Loader label="Chargement de vos recettes…" />
                ) : 0 === savedRecipes.length ? (
                  <Text style={styles.help}>
                    Aucune recette enregistrée. Enregistrez-en une depuis Cuisiner, ou ajoutez un repas libre.
                  </Text>
                ) : (
                  savedRecipes.map((saved) => (
                    <Button
                      key={saved.id}
                      label={saved.recipeName}
                      variant="secondary"
                      disabled={creating}
                      onPress={() => createEntry({ savedRecipeId: saved.id })}
                      style={styles.spacedSmall}
                    />
                  ))
                )}
                <Button
                  label="Annuler"
                  variant="ghost"
                  onPress={() => setPickingRecipe(false)}
                  style={styles.spacedSmall}
                />
              </>
            ) : (
              <>
                <TextInput
                  value={freeformLabel}
                  onChangeText={setFreeformLabel}
                  placeholder="Repas libre — « Resto ce soir »…"
                  placeholderTextColor={colors.inkMuted}
                  style={styles.input}
                />
                <View style={styles.row}>
                  <Button
                    label="Ajouter ce repas libre"
                    onPress={() => createEntry({ label: freeformLabel })}
                    loading={creating}
                    disabled={'' === freeformLabel.trim()}
                    style={styles.rowItem}
                  />
                  <Button
                    label="Choisir une recette"
                    variant="secondary"
                    onPress={openRecipePicker}
                    style={styles.rowItem}
                  />
                </View>
              </>
            )}
          </>
        )}
      </Card>

      <Text style={styles.disclaimer}>
        Une réservation ne bloque jamais une action : elle prévient seulement quand un même
        aliment est déjà prévu ailleurs.
      </Text>
    </ScrollView>
  );
}

function MealPlanEntryCard({
  entry,
  busy,
  onCook,
  onDelete,
}: {
  entry: MealPlanEntry;
  busy: boolean;
  onCook: () => void;
  onDelete: () => void;
}) {
  const presentation = entry.plan ? feasibilityPresentation[entry.plan.status] ?? feasibilityPresentation.ready : null;

  return (
    <Card style={styles.card}>
      <Text style={styles.dateLine}>
        {formatDisplayDate(entry.date)} · {entry.mealSlotLabel}
      </Text>
      <Text style={styles.title}>{entry.displayName}</Text>
      {presentation ? (
        <Badge label={presentation.label} color={presentation.color} background={presentation.background} />
      ) : null}

      {entry.lines.map((line) => (
        <View key={line.key} style={styles.lineRow}>
          <Text style={styles.lineLabel}>
            {line.label} — {line.statusLabel}
            {'short' === line.status ? ` (manque ${line.shortfall} ${line.requestedUnit})` : ''}
          </Text>
          {line.alsoClaimedBy.map((ref) => (
            <Text key={ref.entryId} style={styles.crossReference}>
              Également prévu pour {ref.slotLabel.toLowerCase()} du {formatDisplayDate(ref.date)} ({ref.displayName})
            </Text>
          ))}
        </View>
      ))}

      <View style={styles.row}>
        {'freeform' !== entry.kind ? (
          <Button label="Cuisiner maintenant" onPress={onCook} disabled={busy} style={styles.rowItem} />
        ) : null}
        <Button label="Retirer" variant="danger" onPress={onDelete} disabled={busy} style={styles.rowItem} />
      </View>
    </Card>
  );
}

function formatDisplayDate(iso: string): string {
  const [year, month, day] = iso.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('fr-FR', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  });
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2, flexGrow: 1 },
  card: { marginBottom: spacing.lg },
  dateLine: { fontSize: 13, fontWeight: '700', color: colors.inkMuted, textTransform: 'capitalize' },
  title: { fontSize: 19, fontWeight: '800', color: colors.ink, marginTop: 2 },
  lineRow: { marginTop: spacing.md },
  lineLabel: { fontSize: 14, color: colors.ink },
  crossReference: { fontSize: 12, color: colors.accent, marginTop: 2 },
  row: { flexDirection: 'row', gap: spacing.md, marginTop: spacing.lg },
  rowItem: { flex: 1 },
  shortfallLine: { fontSize: 14, color: colors.ink, marginTop: spacing.xs },
  urgentRow: { marginTop: spacing.sm },
  urgentLabel: { fontSize: 14, fontWeight: '600', color: colors.ink },
  reasons: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs, marginTop: spacing.xs },
  fieldLabel: { fontSize: 13, fontWeight: '600', color: colors.inkMuted, marginTop: spacing.md },
  chips: { flexDirection: 'row', flexWrap: 'wrap', marginTop: spacing.sm },
  input: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
    fontSize: 16,
    color: colors.ink,
    marginTop: spacing.sm,
  },
  spaced: { marginTop: spacing.md },
  spacedSmall: { marginTop: spacing.sm },
  help: { fontSize: 13, color: colors.inkMuted, marginTop: spacing.sm, lineHeight: 19 },
  disclaimer: {
    fontSize: 12,
    color: colors.inkMuted,
    textAlign: 'center',
    marginTop: spacing.md,
    lineHeight: 18,
  },
});
