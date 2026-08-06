import { useRouter } from 'expo-router';
import React, { useEffect, useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { api } from '../src/api/client';
import type { Feasibility, InventorySummary, PriorityOutcome, Recipe } from '../src/api/types';
import { Badge, Button, Card, EmptyState, Icon, Stamp, fromIsoDate } from '../src/components/ui';
import * as haptics from '../src/haptics';
import { useRecipes } from '../src/recipes/RecipeContext';
import {
  colors,
  feasibilityPresentation,
  font,
  radius,
  spacing,
  text,
  thermal,
  thermalFor,
} from '../src/theme';

/** Ordre d'affichage : le plus immédiatement réalisable en premier. */
const ORDER: Feasibility[] = ['ready', 'adapted', 'needs_shopping'];

/** L'échéance d'un ingrédient de l'inventaire, réduite à ce que le cachet imprime. */
interface Urgency {
  days: number;
  date: string;
  estimated: boolean;
}

/** « 2026-08-09 » → 3, compté en jours entiers et en heure locale. */
function daysUntil(iso: string): number {
  const midnight = new Date();
  midnight.setHours(0, 0, 0, 0);

  return Math.round((fromIsoDate(iso).getTime() - midnight.getTime()) / 86_400_000);
}

export default function RecipeListScreen() {
  const router = useRouter();
  const { suggestion, lastRequest, generate } = useRecipes();

  /**
   * L'inventaire, uniquement pour ses échéances.
   *
   * L'échelle thermique — la seule couleur de l'application, celle qui dit le
   * temps qui reste — disparaissait de tout le parcours recette. Or c'est
   * exactement l'écran où l'on choisit quoi sauver : une proposition qui écoule
   * un lot à J−1 ne le montrait nulle part.
   */
  const [inventory, setInventory] = useState<InventorySummary | null>(null);

  useEffect(() => {
    let cancelled = false;

    api
      .inventory()
      .then((value) => {
        if (!cancelled) setInventory(value);
      })
      // Sans les échéances, les cartes se lisent comme avant : ce n'est pas bloquant.
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, []);

  const urgencies = useMemo(() => {
    const map = new Map<string, Urgency>();

    for (const ingredient of inventory?.ingredients ?? []) {
      if (null === ingredient.earliestExpiration) continue;

      map.set(ingredient.key, {
        days: daysUntil(ingredient.earliestExpiration),
        date: ingredient.earliestExpiration,
        estimated: ingredient.expirationIsEstimated,
      });
    }

    return map;
  }, [inventory]);

  /** Rejouer les mêmes réglages : l'attente, elle, se regarde sur « Cuisiner ». */
  function regenerate() {
    if (null === lastRequest) return;

    haptics.tap();
    generate(lastRequest);
    router.back();
  }

  if (!suggestion || 0 === suggestion.recipes.length) {
    return (
      <EmptyState
        icon="pot-steam-outline"
        title="Aucune proposition"
        description="Réglez les convives, le temps et les équipements, puis lancez une génération."
        action={<Button label="Aller à Cuisiner" onPress={() => router.back()} />}
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
              {1 === suggestion.rejected.length
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

      {/* L'ordre était imposé et jamais expliqué. */}
      <Text style={[text.label, styles.orderNote]}>
        De la plus immédiatement réalisable à celle qui demande un complément
      </Text>

      {sorted.map((recipe) => {
        const originalIndex = suggestion.recipes.indexOf(recipe);
        return (
          <RecipeCard
            key={`${recipe.recipeName}-${originalIndex}`}
            recipe={recipe}
            desiredIngredients={suggestion.desiredIngredients}
            urgencies={urgencies}
            onPress={() => router.push(`/recipe/${originalIndex}`)}
          />
        );
      })}

      {null === lastRequest ? null : (
        <Button
          label="Relancer avec les mêmes réglages"
          variant="secondary"
          icon="refresh"
          onPress={regenerate}
          hint="Reprend convives, temps et équipements sans repasser par le formulaire"
          style={styles.again}
        />
      )}
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

/**
 * Le lot le plus pressé que la recette écoule.
 *
 * C'est le seul chiffre qui départage vraiment trois propositions également
 * réalisables : celle qui sauve ce qui périme demain n'est pas l'égale de celle
 * qui entame un paquet de riz.
 */
function mostUrgent(recipe: Recipe, urgencies: Map<string, Urgency>): Urgency | null {
  let earliest: Urgency | null = null;

  for (const ingredient of recipe.ingredientsFromInventory) {
    // `key` est facultatif sur une recette enregistrée avant ce champ : sans
    // clé, l'ingrédient ne peut être rattaché à aucun lot.
    if (undefined === ingredient.key) continue;

    const urgency = urgencies.get(ingredient.key);
    if (undefined === urgency) continue;
    if (null === earliest || urgency.days < earliest.days) earliest = urgency;
  }

  return earliest;
}

function RecipeCard({
  recipe,
  desiredIngredients,
  urgencies,
  onPress,
}: {
  recipe: Recipe;
  desiredIngredients: PriorityOutcome[];
  urgencies: Map<string, Urgency>;
  onPress: () => void;
}) {
  const presentation = feasibilityPresentation[recipe.feasibility] ?? feasibilityPresentation.ready;
  const missingEssential = recipe.missingIngredients.filter((entry) => entry.essential);
  const usedDesired = usedDesiredLabels(recipe, desiredIngredients);
  const urgency = mostUrgent(recipe, urgencies);

  // Le rail thermique de la carte reprend l'échéance qu'elle sauve : une colonne
  // de propositions se hiérarchise alors d'un regard, comme une liste de lots.
  const level = null === urgency ? undefined : thermalFor(urgency.days);

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`${recipe.recipeName}. ${presentation.label}. ${recipe.preparationTime} minutes, ${recipe.servings} ${recipe.servings > 1 ? 'personnes' : 'personne'}, ${recipe.ingredientsFromInventory.length} ingrédients de votre stock${
        null === urgency ? '' : `. Sauve un lot à ${urgency.days} jours`
      }${
        missingEssential.length > 0
          ? `. À acheter : ${missingEssential.map((entry) => entry.label).join(', ')}`
          : ''
      }`}
      accessibilityHint="Ouvre la recette et sa préparation"
      style={({ pressed }) => [styles.card, pressed && styles.pressed]}
    >
      {undefined === level ? null : (
        <View style={[styles.rail, { backgroundColor: thermal[level].line }]} />
      )}

      <View style={styles.cardHead}>
        <Badge label={presentation.label} level={presentation.level} />
        {null === urgency ? null : (
          <Stamp days={urgency.days} date={urgency.date} estimated={urgency.estimated} decorative />
        )}
      </View>

      <Text style={[text.title, styles.title]}>{recipe.recipeName}</Text>
      <Text style={[text.small, styles.summary]}>{recipe.summary}</Text>

      {/* Trois mesures alignées, dans la voix machine : le temps, les convives,
          ce que la recette prend déjà chez vous. Ce sont les seuls chiffres
          comparables entre les propositions, et ils étaient les plus petits de
          l'écran — la valeur passe devant, la rubrique la légende. */}
      <View style={styles.metaRow}>
        <Measure value={`${recipe.preparationTime}`} unit="min" caption="Préparation" />
        <Measure value={`${recipe.servings}`} unit="pers." caption="Convives" />
        <Measure
          value={`${recipe.ingredientsFromInventory.length}`}
          unit=""
          caption="De votre stock"
        />
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

function Measure({ value, unit, caption }: { value: string; unit: string; caption: string }) {
  return (
    <View style={styles.measure}>
      <Text style={styles.measureValue} numberOfLines={1}>
        {value}
        {unit ? <Text style={styles.measureUnit}> {unit}</Text> : null}
      </Text>
      <Text style={styles.measureCaption} numberOfLines={1}>
        {caption}
      </Text>
    </View>
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
    paddingLeft: spacing.lg + spacing.xs,
    marginBottom: spacing.md,
    overflow: 'hidden',
  },
  pressed: { backgroundColor: colors.surfaceSunk },
  rail: { position: 'absolute', left: 0, top: 0, bottom: 0, width: 3 },
  cardHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
  title: { marginTop: spacing.md },
  summary: { marginTop: spacing.xs },

  metaRow: {
    flexDirection: 'row',
    marginTop: spacing.md,
    paddingTop: spacing.md,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  measure: { flex: 1 },
  measureValue: { fontFamily: font.monoSemiBold, fontSize: 17, lineHeight: 22, color: colors.ink },
  measureUnit: { fontFamily: font.mono, fontSize: 11, color: colors.inkMuted },
  measureCaption: { ...text.label, fontSize: 9, marginTop: 2 },

  missing: { marginTop: spacing.md, color: thermal.soon.line, fontFamily: font.sansMedium },
  detail: { marginTop: spacing.xs },

  notice: { marginBottom: spacing.md },
  noticeHead: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  noticeTitle: { marginTop: spacing.sm },
  noticeLine: { marginTop: spacing.xs },
  noticeFooter: { marginTop: spacing.sm },

  orderNote: { marginBottom: spacing.md },
  again: { marginTop: spacing.md },
});
