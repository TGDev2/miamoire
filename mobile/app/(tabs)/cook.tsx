import { useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import React, { useCallback, useRef, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';

import { api } from '../../src/api/client';
import { useSession } from '../../src/session/SessionContext';
import type { InventorySummary, Reference } from '../../src/api/types';
import { Button, Card, Chip, ErrorBanner, Loader, SectionTitle } from '../../src/components/ui';
import { IngredientPicker } from '../../src/components/IngredientPicker';
import { useRecipes } from '../../src/recipes/RecipeContext';
import { colors, radius, spacing } from '../../src/theme';

const TIME_OPTIONS = [15, 30, 45, 60];
const MAX_PRIORITY_KEYS = 3;

export default function CookScreen() {
  const router = useRouter();
  const { household } = useSession();
  const { setSuggestion } = useRecipes();
  const params = useLocalSearchParams<{ priorityKeys?: string }>();

  const [reference, setReference] = useState<Reference | null>(null);
  const [inventory, setInventory] = useState<InventorySummary | null>(null);
  // Un foyer sans membre déclaré retombe sur 2 convives, une supposition
  // raisonnable plutôt qu'une vraie donnée manquante.
  const [people, setPeople] = useState(() => Math.max(1, household?.members.length ?? 2));
  const [mealType, setMealType] = useState<string | null>(null);
  const [mealTemperature, setMealTemperature] = useState<string | null>(null);
  const [maxMinutes, setMaxMinutes] = useState<number | null>(null);
  const [equipment, setEquipment] = useState<string[]>([]);
  const [allowExtra, setAllowExtra] = useState(false);
  /** Aliments imposés aux trois recettes, jusqu'à 3. Vide = aucune contrainte, le défaut. */
  const [priorityKeys, setPriorityKeys] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  // Mémorise la dernière valeur de `priorityKeys` appliquée depuis les params,
  // pour ne semer qu'une fois par navigation — pas à chaque refocus de l'onglet,
  // ce qui écraserait silencieusement une édition faite entre-temps par l'utilisateur.
  const appliedParams = useRef<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [ref, inv] = await Promise.all([api.reference(), api.inventory()]);
      setReference(ref);
      setInventory(inv);

      const incoming = params.priorityKeys ?? null;
      const seeding = incoming !== null && incoming !== appliedParams.current;
      appliedParams.current = incoming;

      const known = new Set(inv.ingredients.map((entry) => entry.key));
      setPriorityKeys((current) => {
        const base = seeding ? (incoming ?? '').split(',').filter(Boolean) : current;
        // Un aliment épuisé entre deux visites ne doit pas rester sélectionné :
        // le serveur refuserait la génération avec une clé qu'il ne connaît plus.
        return base.filter((key) => known.has(key)).slice(0, MAX_PRIORITY_KEYS);
      });
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
    } finally {
      setLoading(false);
    }
  }, [params.priorityKeys]);

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
        mealTemperature,
        maxMinutes,
        equipment,
        exclusions: [],
        allowExtraIngredients: allowExtra,
        priorityKeys,
      });
      setSuggestion(result);
      router.push('/recipes');
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Génération impossible.');
    } finally {
      setBusy(false);
    }
  }

  const priorityLabels = priorityKeys
    .map((key) => inventory?.ingredients.find((entry) => entry.key === key)?.label)
    .filter((label): label is string => Boolean(label));

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

      <View style={styles.linkRow}>
        <Button
          label="Mon plan de repas"
          variant="secondary"
          onPress={() => router.push('/meal-plan')}
          style={styles.linkButton}
        />
        <Button
          label="Mes recettes enregistrées"
          variant="secondary"
          onPress={() => router.push('/saved')}
          style={styles.linkButton}
        />
      </View>

      <SectionTitle>Nous sommes</SectionTitle>
      {/* Les deux boutons n'avaient pour libellé que leur glyphe : un lecteur
          d'écran annonçait « moins » et « plus » sans dire de quoi, ni combien
          on est déjà. `accessibilityValue` porte le nombre courant. */}
      <View style={styles.stepper} accessibilityRole="adjustable">
        <Pressable
          style={styles.stepperButton}
          onPress={() => setPeople((value) => Math.max(1, value - 1))}
          accessibilityRole="button"
          accessibilityLabel="Un convive de moins"
          accessibilityState={{ disabled: people <= 1 }}
        >
          <Text style={styles.stepperGlyph} importantForAccessibility="no">
            −
          </Text>
        </Pressable>
        <Text
          style={styles.stepperValue}
          accessibilityLabel="Nombre de convives"
          accessibilityValue={{ min: 1, max: 20, now: people, text: `${people}` }}
        >
          {people} {people > 1 ? 'personnes' : 'personne'}
        </Text>
        <Pressable
          style={styles.stepperButton}
          onPress={() => setPeople((value) => Math.min(20, value + 1))}
          accessibilityRole="button"
          accessibilityLabel="Un convive de plus"
          accessibilityState={{ disabled: people >= 20 }}
        >
          <Text style={styles.stepperGlyph} importantForAccessibility="no">
            +
          </Text>
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

      <SectionTitle>Chaud ou froid ?</SectionTitle>
      <View style={styles.chips}>
        {reference?.mealTemperatures.map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={mealTemperature === option.value}
            onPress={() => setMealTemperature(mealTemperature === option.value ? null : option.value)}
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

      {/* Une ligne repliée, quelle que soit la taille de l'inventaire. Déroulé
          ici, il faisait 115 puces pleine largeur et repoussait « Proposer 3
          recettes » huit balayages plus bas — un réglage facultatif qui
          enterrait l'action principale de l'écran. */}
      <SectionTitle>Aliments à utiliser en priorité</SectionTitle>
      <IngredientPicker
        ingredients={inventory?.ingredients ?? []}
        selectedKeys={priorityKeys}
        onChange={setPriorityKeys}
        max={MAX_PRIORITY_KEYS}
      />

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
            Exclusions appliquées : {household.dietaryExclusions.map((entry) => entry.label).join(', ')}
          </Text>
          <Text style={styles.guaranteeHint}>
            Ces aliments ne sont pas seulement demandés au modèle : ils sont retirés de
            l'inventaire qui lui est envoyé, et toute recette qui les nomme — jusque dans
            ses étapes — est écartée avant de vous être montrée. Leurs dérivés comptent :
            exclure l'arachide écarte aussi le beurre de cacahuète.
          </Text>
          {household.dietaryExclusions.some((entry) => entry.severity === 'allergy') ? (
            <Text style={styles.guaranteeHint}>
              Pour vos allergies strictes, un produit dont la composition n'est pas connue
              n'est jamais présenté comme sûr : la recette signale « sécurité non
              vérifiable » plutôt qu'une garantie qu'elle ne peut pas tenir.
            </Text>
          ) : null}
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
      {/* Rappelé juste au-dessus du geste : la contrainte se pose en haut de
          l'écran et s'applique en bas, et rien ne la rappelait entre les deux. */}
      {priorityLabels.length > 0 ? (
        <Text style={styles.submitNote}>Autour de : {priorityLabels.join(', ')}</Text>
      ) : null}
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
  linkRow: { flexDirection: 'row', gap: spacing.md, marginTop: spacing.lg },
  linkButton: { flex: 1 },
  submitNote: {
    fontSize: 13,
    fontWeight: '600',
    color: colors.primary,
    textAlign: 'center',
    marginTop: spacing.sm,
  },
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
