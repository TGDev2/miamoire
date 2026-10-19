import { useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ScrollView, Share, StyleSheet, Text, View } from 'react-native';

import { api } from '../../src/api/client';
import { useSession } from '../../src/session/SessionContext';
import type { InventorySummary, Priority, Reference } from '../../src/api/types';
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
import { useFeedback } from '../../src/components/Feedback';
import { IngredientPicker } from '../../src/components/IngredientPicker';
import * as haptics from '../../src/haptics';
import { useRecipes, type GenerationRequest } from '../../src/recipes/RecipeContext';
import {
  defaultPreferences,
  readPreferences,
  writePreferences,
  type CookPreferences,
} from '../../src/recipes/preferences';
import { colors, font, spacing, text, thermal } from '../../src/theme';

// Un plat mijoté du dimanche n'entrait dans aucune des quatre bornes : la plus
// longue disait « moins de 60 min », et il n'existait aucune façon de dire
// « prenez le temps qu'il faut ».
const TIME_OPTIONS = [15, 30, 45, 60, 90];
const MAX_PRIORITY_KEYS = 3;

/**
 * Ce que le serveur fait pendant qu'on attend, dans l'ordre où il le fait.
 *
 * Ce n'est pas une barre de progression déguisée : le serveur n'en rend aucune,
 * et prétendre le contraire serait mentir. C'est la vraie séquence du
 * traitement — agréger l'inventaire, interroger le modèle, revérifier
 * exclusions et quantités — avec des seuils tirés des durées observées. Le
 * chiffre qui, lui, est exact, c'est le temps écoulé.
 */
const PHASES = [
  { after: 0, label: 'Lecture de votre inventaire' },
  { after: 8, label: 'Composition des propositions' },
  { after: 26, label: 'Vérification de vos exclusions et de vos quantités' },
  { after: 55, label: "C'est plus long que d'habitude. On attend encore." },
];

export default function CookScreen() {
  const router = useRouter();
  const { household } = useSession();
  const topInset = useTopInset();
  const params = useLocalSearchParams<{ priorityKeys?: string }>();

  const [reference, setReference] = useState<Reference | null>(null);
  const [inventory, setInventory] = useState<InventorySummary | null>(null);
  /** Les réglages retenus d'une génération à l'autre. Voir `preferences.ts`. */
  const [settings, setSettings] = useState<CookPreferences>(() =>
    defaultPreferences(household?.members.length ?? 0),
  );
  /**
   * Aliments imposés aux trois recettes, jusqu'à 3. Vide = aucune contrainte,
   * le défaut. Chacun peut porter une quantité à écouler, elle aussi facultative.
   */
  const [priorities, setPriorities] = useState<Priority[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  /** L'écran est-il celui qu'on regarde ? Décide de l'ouverture automatique des résultats. */
  const [focused, setFocused] = useState(false);

  const { generation, elapsed, generate, cancelGeneration, acknowledgeGeneration } = useRecipes();
  const { notify } = useFeedback();
  const [exporting, setExporting] = useState(false);

  const { people, mealType, mealTemperature, maxMinutes, equipment, foodGroups, allowExtra } = settings;

  /** Un réglage change : il s'applique et il est retenu, dans le même geste. */
  const update = useCallback((patch: Partial<CookPreferences>) => {
    setSettings((current) => {
      const next = { ...current, ...patch };
      void writePreferences(next);
      return next;
    });
  }, []);

  // Mémorise la dernière valeur de `priorityKeys` appliquée depuis les params,
  // qui restent une simple liste de clés : « Aujourd'hui » désigne des aliments
  // à sauver, il ne dit pas encore combien en écouler.
  // pour ne semer qu'une fois par navigation — pas à chaque refocus de l'onglet,
  // ce qui écraserait silencieusement une édition faite entre-temps par l'utilisateur.
  const appliedParams = useRef<string | null>(null);

  /** Ne relire le disque qu'une fois : ensuite, l'écran fait foi. */
  const settingsRestored = useRef(false);

  const load = useCallback(async () => {
    try {
      const [ref, inv] = await Promise.all([api.reference(), api.inventory()]);
      setReference(ref);
      setInventory(inv);

      if (!settingsRestored.current) {
        settingsRestored.current = true;
        setSettings(
          await readPreferences(household?.members.length ?? 0, {
            mealTypes: ref.mealTypes.map((option) => option.value),
            mealTemperatures: ref.mealTemperatures.map((option) => option.value),
            equipment: ref.equipment.map((option) => option.value),
            foodGroups: ref.foodGroups.map((option) => option.value),
          }),
        );
      }

      const incoming = params.priorityKeys ?? null;
      const seeding = incoming !== null && incoming !== appliedParams.current;
      appliedParams.current = incoming;

      const known = new Set(inv.ingredients.map((entry) => entry.key));
      setPriorities((current) => {
        const base = seeding
          ? (incoming ?? '')
              .split(',')
              .filter(Boolean)
              .map((key): Priority => ({ key }))
          : current;
        // Un aliment épuisé entre deux visites ne doit pas rester sélectionné :
        // le serveur refuserait la génération avec une clé qu'il ne connaît plus.
        return base.filter((entry) => known.has(entry.key)).slice(0, MAX_PRIORITY_KEYS);
      });
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
    } finally {
      setLoading(false);
    }
  }, [household?.members.length, params.priorityKeys]);

  useFocusEffect(
    useCallback(() => {
      load();
      setFocused(true);

      return () => setFocused(false);
    }, [load]),
  );

  function toggleEquipment(value: string) {
    haptics.select();
    update({
      equipment: equipment.includes(value)
        ? equipment.filter((entry) => entry !== value)
        : [...equipment, value],
    });
  }

  function toggleFoodGroup(value: string) {
    haptics.select();
    update({
      foodGroups: foodGroups.includes(value)
        ? foodGroups.filter((entry) => entry !== value)
        : [...foodGroups, value],
    });
  }

  const request: GenerationRequest = {
    people,
    mealType,
    mealTemperature,
    maxMinutes,
    equipment,
    exclusions: [],
    allowExtraIngredients: allowExtra,
    priorities,
    foodGroups,
  };

  /**
   * La même demande, à coller soi-même dans un autre assistant quand le modèle
   * de l'application ne répond pas — quota, panne. Le serveur la rédige : c'est
   * la consigne exacte de la génération, aucun modèle n'est sollicité.
   *
   * Reste possible pendant une génération : c'est quand elle traîne qu'on en a
   * besoin. Fermer la feuille de partage n'est pas une erreur.
   */
  async function exportPrompt(body: GenerationRequest) {
    haptics.tap();
    setExporting(true);

    try {
      const { prompt } = await api.recipePrompt(body);
      await Share.share({ message: prompt });
    } catch (cause) {
      notify(cause instanceof Error ? cause.message : "L'export a échoué. Réessayez.", 'critical');
    } finally {
      setExporting(false);
    }
  }

  /**
   * Le résultat s'ouvre tout seul si l'on a attendu devant l'écran, et attend
   * derrière un bouton si l'on est parti.
   *
   * C'est la transition qui décide, pas l'état : sans mémoire du statut
   * précédent, revenir sur « Cuisiner » rouvrirait les résultats à chaque fois,
   * y compris une heure plus tard.
   */
  const previousStatus = useRef(generation.status);

  useEffect(() => {
    const was = previousStatus.current;
    previousStatus.current = generation.status;

    if (focused && 'running' === was && 'ready' === generation.status) {
      haptics.success();
      acknowledgeGeneration();
      router.push('/recipes');
    }
  }, [acknowledgeGeneration, focused, generation.status, router]);

  // « Courgettes (400 g) » : la quantité imposée se relit juste au-dessus du
  // bouton, là où on décide de lancer — pas seulement dans la feuille où on l'a
  // saisie et qu'il faudrait rouvrir pour la vérifier.
  const priorityLabels = priorities
    .map((priority) => {
      const ingredient = inventory?.ingredients.find((entry) => entry.key === priority.key);

      if (!ingredient) return null;

      if (priority.packages !== undefined) {
        const unit = priority.packages > 1 ? 'exemplaires' : 'exemplaire';
        return `${ingredient.label} (${priority.packages} ${unit})`;
      }

      if (priority.quantity !== undefined) {
        return `${ingredient.label} (${priority.quantity} ${ingredient.unitLabel})`;
      }

      return ingredient.label;
    })
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

      {/* Une génération lancée depuis cet écran puis quittée n'était visible
          nulle part au retour : elle attend désormais ici. */}
      {'running' === generation.status ? (
        <GeneratingCard elapsed={elapsed} onCancel={cancelGeneration} />
      ) : null}

      {'ready' === generation.status ? (
        <Card level="safe" style={styles.stateCard}>
          <Text style={text.label}>Génération terminée</Text>
          <Text style={[text.heading, styles.stateTitle]}>Vos propositions sont prêtes</Text>
          <Button
            label="Voir les recettes"
            icon="arrow-right"
            onPress={() => {
              acknowledgeGeneration();
              router.push('/recipes');
            }}
            style={styles.stateAction}
          />
        </Card>
      ) : null}

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

      {/* Les six réglages ci-dessous sont retenus d'une fois sur l'autre. Une
          puce déjà choisie se retape pour revenir à « peu importe » — ce que la
          rubrique dit maintenant, faute de quoi le geste ne s'invente pas. */}
      <SectionTitle>Convives à table</SectionTitle>
      <Stepper
        value={people}
        unitLabel={people > 1 ? 'personnes' : 'personne'}
        onChange={(value) => update({ people: value })}
        min={1}
        max={20}
      />

      <SectionTitle>Type de repas</SectionTitle>
      <Text style={[text.small, styles.sectionHint]}>
        Facultatif — retapez pour revenir à « peu importe ».
      </Text>
      <View style={styles.chips}>
        {reference?.mealTypes.map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={mealType === option.value}
            onPress={() => {
              haptics.select();
              update({ mealType: mealType === option.value ? null : option.value });
            }}
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
            onPress={() => {
              haptics.select();
              update({
                mealTemperature: mealTemperature === option.value ? null : option.value,
              });
            }}
          />
        ))}
      </View>

      <SectionTitle>À inclure dans le plat</SectionTitle>
      <View style={styles.chips}>
        {reference?.foodGroups.map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={foodGroups.includes(option.value)}
            onPress={() => toggleFoodGroup(option.value)}
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
            onPress={() => {
              haptics.select();
              update({ maxMinutes: maxMinutes === minutes ? null : minutes });
            }}
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
        value={priorities}
        onChange={setPriorities}
        max={MAX_PRIORITY_KEYS}
      />

      <SectionTitle>Courses</SectionTitle>
      <Card style={styles.toggleCard}>
        <ToggleRow
          label="Autoriser quelques ingrédients manquants"
          hint="Désactivé, seules les recettes réalisables avec votre stock sont proposées."
          value={allowExtra}
          onValueChange={(value) => update({ allowExtra: value })}
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

      {/* L'échec s'affichait dans la bannière du haut, à dix balayages du geste
          qui l'avait causé : on regardait le bouton, le message était ailleurs.
          Il se lit ici, avec de quoi repartir sans retoucher le formulaire. */}
      {'error' === generation.status ? (
        <Card level="critical" style={styles.stateCard}>
          <Text style={text.label}>La génération a échoué</Text>
          <Text style={[text.body, styles.stateTitle]}>{generation.message}</Text>
          <View style={styles.retryRow}>
            <Button
              label="Réessayer"
              icon="refresh"
              onPress={() => generate(generation.request)}
              style={styles.retryMain}
            />
            <Button label="Fermer" variant="ghost" onPress={acknowledgeGeneration} />
          </View>
          <Button
            label="Exporter vers un autre assistant"
            variant="secondary"
            icon="share-variant-outline"
            onPress={() => exportPrompt(generation.request)}
            loading={exporting}
            disabled={exporting}
            style={styles.stateAction}
          />
        </Card>
      ) : null}

      <Button
        label="Proposer 3 recettes"
        onPress={() => {
          haptics.tap();
          generate(request);
        }}
        loading={'running' === generation.status}
        disabled={'running' === generation.status}
        hint="Compte environ une minute : le modèle lit tout votre inventaire"
        style={styles.submit}
      />
      {/* Rappelé juste au-dessus du geste : la contrainte se pose en haut de
          l'écran et s'applique en bas, et rien ne la rappelait entre les deux. */}
      {priorityLabels.length > 0 ? (
        <Text style={[text.label, styles.submitNote]}>Autour de : {priorityLabels.join(', ')}</Text>
      ) : null}
      <Text style={[text.small, styles.disclaimer]}>
        Rien ne quitte votre stock sans votre confirmation. Comptez environ une minute.
      </Text>

      {/* Toujours là, pas seulement après un échec : sans clé d'API ou quota
          épuisé, aucune génération n'aboutit, et l'échec n'arrive qu'au bout
          de l'attente. */}
      <Button
        label="Exporter vers un autre assistant"
        variant="secondary"
        icon="share-variant-outline"
        onPress={() => exportPrompt(request)}
        loading={exporting}
        disabled={exporting}
        hint="Partage la demande et votre inventaire, à coller dans un autre assistant"
        style={styles.export}
      />
      <Button
        label="Importer une recette"
        variant="secondary"
        icon="tray-arrow-down"
        onPress={() => router.push('/recipe-import')}
        hint="Rapporte une recette écrite par l'autre assistant"
        style={styles.stateAction}
      />
      <Text style={[text.small, styles.disclaimer]}>
        L'export contient votre inventaire, vos notes et vos exclusions ; les aliments exclus en
        sont retirés. Une recette importée est revérifiée — exclusions et stock — puis se garde
        et se cuisine comme les autres.
      </Text>
    </ScrollView>
  );
}

/** `73` → `01:13`. Le seul chiffre exact de l'attente, dans la voix machine. */
function clock(seconds: number): string {
  return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
}

/**
 * Ce qu'on regarde pendant quarante à cinquante secondes.
 *
 * Il n'y avait rien : le bouton perdait son libellé au profit d'un indicateur,
 * et l'écran ne disait plus un mot jusqu'au résultat. On ne pouvait ni savoir
 * où en était la demande, ni renoncer, ni quitter l'écran sans la perdre.
 */
function GeneratingCard({ elapsed, onCancel }: { elapsed: number; onCancel: () => void }) {
  const phase = PHASES.filter((entry) => elapsed >= entry.after).at(-1) ?? PHASES[0];
  const step = PHASES.indexOf(phase) + 1;

  return (
    <Card level="safe" style={styles.stateCard}>
      <View style={styles.waitHead}>
        <Text style={text.label}>Génération en cours</Text>
        <Text style={styles.clock}>{clock(elapsed)}</Text>
      </View>

      <Text
        style={[text.heading, styles.stateTitle]}
        accessibilityLiveRegion="polite"
        accessibilityLabel={`${phase.label}. ${elapsed} secondes écoulées.`}
      >
        {phase.label}
      </Text>

      {/* Quatre segments pour quatre étapes réelles du traitement — pas une
          progression continue, que le serveur ne rend pas et qu'il faudrait
          donc inventer. */}
      <View style={styles.track} importantForAccessibility="no-hide-descendants">
        {PHASES.map((entry, index) => (
          <View
            key={entry.after}
            style={[styles.segment, index < step ? styles.segmentDone : null]}
          />
        ))}
      </View>

      <Text style={[text.small, styles.stateHint]}>
        Vous pouvez quitter cet écran : la génération continue, et le résultat vous attendra ici.
      </Text>

      <Button label="Annuler" variant="ghost" onPress={onCancel} style={styles.stateAction} />
    </Card>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2 },
  linkCard: { padding: 0 },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  sectionHint: { marginTop: -spacing.sm, marginBottom: spacing.sm },
  toggleCard: { padding: 0 },
  exclusionBadges: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs },
  exclusionHint: { marginTop: spacing.md },
  submit: { marginTop: spacing.xl },
  submitNote: { textAlign: 'center', marginTop: spacing.md },
  disclaimer: { textAlign: 'center', marginTop: spacing.sm },
  export: { marginTop: spacing.xl },

  stateCard: { marginBottom: spacing.lg },
  stateTitle: { marginTop: spacing.sm },
  stateHint: { marginTop: spacing.md },
  stateAction: { marginTop: spacing.md },
  retryRow: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.lg },
  retryMain: { flex: 1 },

  waitHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  clock: { fontFamily: font.monoSemiBold, fontSize: 15, lineHeight: 19, color: colors.ink },

  track: { flexDirection: 'row', gap: 3, marginTop: spacing.lg },
  segment: { flex: 1, height: 3, borderRadius: 1, backgroundColor: colors.border },
  segmentDone: { backgroundColor: thermal.safe.line },
});
