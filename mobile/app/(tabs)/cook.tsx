import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';

import { api } from '../../src/api/client';
import { useSession } from '../../src/session/SessionContext';
import type { InventorySummary, Reference } from '../../src/api/types';
import { Button, Card, Chip, ErrorBanner, Loader, SectionTitle } from '../../src/components/ui';
import { useRecipes } from '../../src/recipes/RecipeContext';
import { colors, radius, spacing } from '../../src/theme';

const TIME_OPTIONS = [15, 30, 45, 60];

export default function CookScreen() {
  const router = useRouter();
  const { household } = useSession();
  const { setSuggestion } = useRecipes();

  const [reference, setReference] = useState<Reference | null>(null);
  const [inventory, setInventory] = useState<InventorySummary | null>(null);
  const [people, setPeople] = useState(2);
  const [mealType, setMealType] = useState<string | null>(null);
  const [maxMinutes, setMaxMinutes] = useState<number | null>(null);
  const [equipment, setEquipment] = useState<string[]>([]);
  const [allowExtra, setAllowExtra] = useState(false);
  /** Aliment imposé aux trois recettes. `null` = aucune contrainte, et c'est le défaut. */
  const [priorityKey, setPriorityKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const [ref, inv] = await Promise.all([api.reference(), api.inventory()]);
      setReference(ref);
      setInventory(inv);
      // Un aliment épuisé entre deux visites ne doit pas rester sélectionné :
      // le serveur refuserait la génération avec une clé qu'il ne connaît plus.
      setPriorityKey((current) =>
        current !== null && inv.ingredients.some((entry) => entry.key === current) ? current : null,
      );
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  function toggleEquipment(value: string) {
    setEquipment((current) =>
      current.includes(value) ? current.filter((entry) => entry !== value) : [...current, value],
    );
  }

  async function generate() {
    setError(null);
    setBusy(true);
    try {
      const result = await api.suggestRecipes({
        people,
        mealType,
        maxMinutes,
        equipment,
        exclusions: [],
        allowExtraIngredients: allowExtra,
        priorityKey,
      });
      setSuggestion(result);
      router.push('/recipes');
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Génération impossible.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <Loader />;

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <ErrorBanner message={error} />

      <Card>
        <Text style={styles.summaryTitle}>Votre inventaire</Text>
        <Text style={styles.summaryLine}>
          {inventory?.stats.distinctIngredients ?? 0} ingrédients · {inventory?.stats.lots ?? 0} lots
        </Text>
        {inventory && inventory.stats.expiringSoon > 0 ? (
          <Text style={styles.summaryUrgent}>
            {inventory.stats.expiringSoon} lot(s) à consommer en priorité
          </Text>
        ) : null}
      </Card>

      <Button
        label="Mes recettes enregistrées"
        variant="secondary"
        onPress={() => router.push('/saved')}
        style={styles.savedLink}
      />

      <SectionTitle>Nous sommes</SectionTitle>
      <View style={styles.stepper}>
        <Pressable
          style={styles.stepperButton}
          onPress={() => setPeople((value) => Math.max(1, value - 1))}
        >
          <Text style={styles.stepperGlyph}>−</Text>
        </Pressable>
        <Text style={styles.stepperValue}>
          {people} {people > 1 ? 'personnes' : 'personne'}
        </Text>
        <Pressable
          style={styles.stepperButton}
          onPress={() => setPeople((value) => Math.min(20, value + 1))}
        >
          <Text style={styles.stepperGlyph}>+</Text>
        </Pressable>
      </View>

      <SectionTitle>Type de repas</SectionTitle>
      <View style={styles.chips}>
        {reference?.mealTypes.map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={mealType === option.value}
            onPress={() => setMealType(mealType === option.value ? null : option.value)}
          />
        ))}
      </View>

      <SectionTitle>Temps disponible</SectionTitle>
      <View style={styles.chips}>
        {TIME_OPTIONS.map((minutes) => (
          <Chip
            key={minutes}
            label={`moins de ${minutes} min`}
            selected={maxMinutes === minutes}
            onPress={() => setMaxMinutes(maxMinutes === minutes ? null : minutes)}
          />
        ))}
      </View>

      <SectionTitle>Équipements utilisables</SectionTitle>
      <View style={styles.chips}>
        {reference?.equipment.map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={equipment.includes(option.value)}
            onPress={() => toggleEquipment(option.value)}
          />
        ))}
      </View>

      {/* Facultatif, et affiché comme tel : la plupart des générations ne
          contraignent rien. L'inventaire arrive déjà trié — ce qui périme
          bientôt d'abord, puis ce qui est entamé — donc les aliments à écouler
          sont en tête sans qu'on ait à les chercher. */}
      <SectionTitle>Aliment à utiliser en priorité</SectionTitle>
      <Text style={styles.hint}>
        {priorityKey === null
          ? 'Facultatif. Choisissez-en un pour que les trois recettes l\'utilisent.'
          : 'Les recettes qui ne l\'utilisent pas seront écartées avant de vous être montrées.'}
      </Text>
      <View style={styles.chips}>
        {(inventory?.ingredients ?? []).map((ingredient) => (
          <Chip
            key={ingredient.key}
            label={`${ingredient.label}${ingredient.expiresSoon ? ' ⏳' : ingredient.alreadyOpened ? ' •' : ''}`}
            selected={priorityKey === ingredient.key}
            onPress={() =>
              setPriorityKey(priorityKey === ingredient.key ? null : ingredient.key)
            }
          />
        ))}
      </View>
      {inventory && inventory.ingredients.length > 0 ? (
        <Text style={styles.legend}>⏳ périme bientôt · • paquet déjà entamé</Text>
      ) : null}

      <SectionTitle>Courses</SectionTitle>
      <View style={styles.switchRow}>
        <View style={styles.flex}>
          <Text style={styles.switchLabel}>Autoriser quelques ingrédients manquants</Text>
          <Text style={styles.switchHint}>
            Désactivé, seules les recettes réalisables avec votre stock sont proposées.
          </Text>
        </View>
        <Switch
          value={allowExtra}
          onValueChange={setAllowExtra}
          trackColor={{ true: colors.primary, false: colors.border }}
        />
      </View>

      {household && household.dietaryExclusions.length > 0 ? (
        <View style={styles.guarantee}>
          <Text style={styles.guaranteeTitle}>
            Exclusions vérifiées : {household.dietaryExclusions.join(', ')}
          </Text>
          <Text style={styles.guaranteeHint}>
            Ces aliments ne sont pas seulement demandés au modèle : ils sont retirés de
            l'inventaire qui lui est envoyé, et toute recette qui les nomme — jusque dans
            ses étapes — est écartée avant de vous être montrée. Leurs dérivés comptent :
            exclure l'arachide écarte aussi le beurre de cacahuète.
          </Text>
        </View>
      ) : (
        <Text style={styles.exclusions}>
          Aucune exclusion enregistrée. Ajoutez-en dans Profil pour qu'elles soient
          vérifiées automatiquement sur chaque recette.
        </Text>
      )}

      <Button
        label="Proposer 3 recettes"
        onPress={generate}
        loading={busy}
        style={styles.submit}
      />
      <Text style={styles.disclaimer}>
        Les recettes sont des propositions : rien n'est retiré de votre stock sans votre
        confirmation.
      </Text>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2 },
  flex: { flex: 1 },
  summaryTitle: { fontSize: 16, fontWeight: '700', color: colors.ink },
  summaryLine: { fontSize: 14, color: colors.inkMuted, marginTop: spacing.xs },
  summaryUrgent: { fontSize: 14, color: colors.accent, fontWeight: '600', marginTop: spacing.xs },
  stepper: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    padding: spacing.md,
  },
  stepperButton: {
    width: 56,
    height: 44,
    borderRadius: radius.md,
    backgroundColor: colors.primarySoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  stepperGlyph: { fontSize: 22, fontWeight: '700', color: colors.primary },
  stepperValue: { fontSize: 17, fontWeight: '700', color: colors.ink },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  savedLink: { marginTop: spacing.lg },
  hint: { fontSize: 13, color: colors.inkMuted, marginBottom: spacing.sm, lineHeight: 19 },
  legend: { fontSize: 12, color: colors.inkMuted, marginTop: spacing.xs },
  switchRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  switchLabel: { fontSize: 15, fontWeight: '600', color: colors.ink },
  switchHint: { fontSize: 13, color: colors.inkMuted, marginTop: 2, lineHeight: 18 },
  exclusions: { fontSize: 13, color: colors.inkMuted, marginTop: spacing.md, lineHeight: 19 },
  guarantee: {
    backgroundColor: colors.primarySoft,
    borderRadius: radius.md,
    padding: spacing.md,
    marginTop: spacing.md,
  },
  guaranteeTitle: { fontSize: 14, fontWeight: '700', color: colors.primary },
  guaranteeHint: { fontSize: 13, color: colors.primary, marginTop: spacing.xs, lineHeight: 19 },
  submit: { marginTop: spacing.xl },
  disclaimer: {
    fontSize: 12,
    color: colors.inkMuted,
    textAlign: 'center',
    marginTop: spacing.md,
    lineHeight: 18,
  },
});
