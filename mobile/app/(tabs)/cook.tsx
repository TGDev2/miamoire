import { useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import React, { useCallback, useRef, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';

import { api } from '../../src/api/client';
import { useSession } from '../../src/session/SessionContext';
import type { InventorySummary, Reference } from '../../src/api/types';
import {
  Badge,
  Button,
  Card,
  Chip,
  ErrorBanner,
  Loader,
  Row,
  ScreenHeader,
  SectionTitle,
  Stepper,
  ToggleRow,
  useTopInset,
} from '../../src/components/ui';
import { IngredientPicker } from '../../src/components/IngredientPicker';
import { useRecipes } from '../../src/recipes/RecipeContext';
import { spacing, text } from '../../src/theme';

const TIME_OPTIONS = [15, 30, 45, 60];
const MAX_PRIORITY_KEYS = 3;

export default function CookScreen() {
  const router = useRouter();
  const { household } = useSession();
  const topInset = useTopInset();
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

  const { setSuggestion } = useRecipes();

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

  if (loading) return <Loader label="Lecture de votre inventaire" />;

  const stats = inventory?.stats;
  const allergies = household?.dietaryExclusions.filter((entry) => 'allergy' === entry.severity) ?? [];

  return (
    <ScrollView style={topInset} contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ScreenHeader
        eyebrow={
          stats
            ? `${stats.distinctIngredients} ingrédients · ${stats.lots} lots`
            : 'Inventaire indisponible'
        }
        title="Cuisiner"
        description="Trois recettes construites sur ce que vous avez déjà, pas sur ce qu'il faudrait acheter."
      />

      <ErrorBanner message={error} />

      <Card style={styles.linkCard}>
        <Row
          icon="calendar-blank-outline"
          label="Mon plan de repas"
          subtitle="Les repas déjà prévus et ce qu'ils réservent"
          onPress={() => router.push('/meal-plan')}
        />
        <Row
          icon="content-save-outline"
          label="Mes recettes enregistrées"
          subtitle="Celles que le foyer a décidé de garder"
          onPress={() => router.push('/saved')}
          last
        />
      </Card>

      <SectionTitle>Convives à table</SectionTitle>
      <Stepper
        value={people}
        unitLabel={people > 1 ? 'personnes' : 'personne'}
        onChange={setPeople}
        min={1}
        max={20}
      />

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

      <SectionTitle>Chaud ou froid</SectionTitle>
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
      <Card style={styles.toggleCard}>
        <ToggleRow
          label="Autoriser quelques ingrédients manquants"
          hint="Désactivé, seules les recettes réalisables avec votre stock sont proposées."
          value={allowExtra}
          onValueChange={setAllowExtra}
        />
      </Card>

      <SectionTitle>Exclusions du foyer</SectionTitle>
      {household && household.dietaryExclusions.length > 0 ? (
        <Card>
          <View style={styles.exclusionBadges}>
            {household.dietaryExclusions.map((entry) => (
              <Badge
                key={entry.label}
                label={entry.label}
                level={'allergy' === entry.severity ? 'critical' : 'calm'}
              />
            ))}
          </View>
          <Text style={[text.small, styles.exclusionHint]}>
            Ces aliments sont retirés de l'inventaire envoyé au modèle, et toute recette qui les
            nomme — jusque dans ses étapes — est écartée avant de vous être montrée. Les dérivés
            comptent : exclure l'arachide écarte aussi le beurre de cacahuète.
          </Text>
          {allergies.length > 0 ? (
            <Text style={[text.small, styles.exclusionHint]}>
              Pour une allergie stricte, un produit dont la composition est inconnue n'est jamais
              présenté comme sûr : la recette affiche « sécurité non vérifiable ».
            </Text>
          ) : null}
        </Card>
      ) : (
        <Card>
          <Text style={text.small}>
            Aucune exclusion enregistrée. Ajoutez-en dans Foyer pour qu'elles soient vérifiées sur
            chaque recette.
          </Text>
        </Card>
      )}

      <Button label="Proposer 3 recettes" onPress={generate} loading={busy} style={styles.submit} />
      {/* Rappelé juste au-dessus du geste : la contrainte se pose en haut de
          l'écran et s'applique en bas, et rien ne la rappelait entre les deux. */}
      {priorityLabels.length > 0 ? (
        <Text style={[text.label, styles.submitNote]}>Autour de : {priorityLabels.join(', ')}</Text>
      ) : null}
      <Text style={[text.small, styles.disclaimer]}>
        Rien ne quitte votre stock sans votre confirmation.
      </Text>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2 },
  linkCard: { padding: 0 },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  toggleCard: { padding: 0 },
  exclusionBadges: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs },
  exclusionHint: { marginTop: spacing.md },
  submit: { marginTop: spacing.xl },
  submitNote: { textAlign: 'center', marginTop: spacing.md },
  disclaimer: { textAlign: 'center', marginTop: spacing.sm },
});
