/**
 * Le plan de travail.
 *
 * La fiche recette est faite pour décider : un badge, un résumé, deux boutons,
 * les ingrédients, les manquants, les adaptations — et la préparation en
 * sixième rubrique, à refaire défiler à chaque ouverture. En cuisine, on ne
 * décide plus : on exécute, téléphone posé à cinquante centimètres, mains
 * occupées, souvent sales.
 *
 * D'où un mode à part, qui ne garde que ce qui sert à ce moment-là :
 *
 * - **l'écran ne s'éteint pas** (`useKeepAwake`) — il se verrouillait au milieu
 *   de l'étape 4, et il fallait des mains propres pour le rallumer ;
 * - **les étapes passent de 15 à 21 px**, la taille d'un texte d'aide devenant
 *   celle d'une instruction ;
 * - **une étape se coche d'un appui** : après une interruption, on ne relit plus
 *   la séquence pour retrouver sa place ;
 * - **les ingrédients restent à un geste**, dans un panneau qui se pose
 *   par-dessus sans faire perdre la place ;
 * - **durées et températures sont composées dans la voix machine**, comme
 *   partout ailleurs dans l'application — ce sont elles qu'on cherche des yeux.
 */

import { useKeepAwake } from 'expo-keep-awake';
import React, { useMemo, useState } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { Recipe } from '../api/types';
import { Icon } from '../components/ui';
import * as haptics from '../haptics';
import { colors, font, radius, spacing, text, thermal } from '../theme';

/**
 * Ce qui se lit comme une durée ou une température dans une instruction.
 *
 * `10 min`, `180 °C`, `3 à 4 minutes` — la règle typographique de l'application
 * veut que tout ce qui est chiffré soit composé en machine. Ici elle a un usage
 * concret : ce sont exactement les fragments qu'on cherche des yeux en relevant
 * la tête d'une casserole.
 *
 * L'intervalle fait partie du motif. Sans lui, « pendant 3 à 4 minutes » ne
 * détachait que « 4 minutes » et donnait à lire une fourchette coupée en deux.
 *
 * Les poids et les longueurs restent en texte courant, volontairement : on les
 * a mesurés à la préparation, et détacher la moitié d'une phrase ne détache
 * plus rien.
 */
const MEASURE =
  /(\d+(?:[.,]\d+)?(?:\s?(?:à|-|–)\s?\d+(?:[.,]\d+)?)?\s?(?:°C|°|min(?:utes?)?|h(?:eures?)?|secondes?)\b)/gi;

function StepText({ value }: { value: string }) {
  const parts = useMemo(() => value.split(MEASURE), [value]);

  return (
    <Text style={styles.stepText}>
      {parts.map((part, index) =>
        // `split` avec un groupe capturant intercale les captures aux rangs
        // impairs : pas besoin de retester le motif.
        1 === index % 2 ? (
          <Text key={index} style={styles.measure}>
            {part}
          </Text>
        ) : (
          part
        ),
      )}
    </Text>
  );
}

export function CookMode({
  recipe,
  visible,
  done,
  onToggleStep,
  onClose,
}: {
  recipe: Recipe;
  visible: boolean;
  /** Les étapes déjà faites. Portées par la fiche, elles survivent à une fermeture. */
  done: boolean[];
  onToggleStep: (position: number) => void;
  onClose: () => void;
}) {
  const [showIngredients, setShowIngredients] = useState(false);

  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <CookSurface
        recipe={recipe}
        done={done}
        onToggleStep={onToggleStep}
        onClose={onClose}
        showIngredients={showIngredients}
        onToggleIngredients={() => setShowIngredients((current) => !current)}
      />
    </Modal>
  );
}

/**
 * Le contenu, séparé du `Modal` pour une seule raison : `useKeepAwake` doit être
 * monté avec l'écran et démonté avec lui. Placé sur le composant qui rend le
 * `Modal`, le verrou resterait posé après la fermeture — et le téléphone ne se
 * verrouillerait plus jamais de la session.
 */
function CookSurface({
  recipe,
  done,
  onToggleStep,
  onClose,
  showIngredients,
  onToggleIngredients,
}: {
  recipe: Recipe;
  done: boolean[];
  onToggleStep: (position: number) => void;
  onClose: () => void;
  showIngredients: boolean;
  onToggleIngredients: () => void;
}) {
  useKeepAwake();

  const insets = useSafeAreaInsets();
  const completed = done.filter(Boolean).length;
  const total = recipe.steps.length;

  return (
    <View style={[styles.surface, { paddingTop: insets.top }]}>
      <View style={styles.head}>
        <Pressable
          onPress={onClose}
          hitSlop={10}
          accessibilityRole="button"
          accessibilityLabel="Quitter le mode cuisine"
          style={({ pressed }) => [styles.headButton, pressed && styles.headButtonPressed]}
        >
          <Icon name="close" size={22} color={colors.ink} />
        </Pressable>

        <View style={styles.headText}>
          <Text style={text.label}>Mode cuisine</Text>
          <Text style={[text.subheading, styles.headTitle]} numberOfLines={1}>
            {recipe.recipeName}
          </Text>
        </View>

        <Text
          style={styles.progress}
          accessibilityLabel={`${completed} étapes faites sur ${total}`}
        >
          {completed}/{total}
        </Text>
      </View>

      {/* Un segment par étape : la progression est un compte d'étapes réelles,
          pas un pourcentage lissé. */}
      <View style={styles.track} importantForAccessibility="no-hide-descendants">
        {recipe.steps.map((step, position) => (
          <View
            key={`track-${position}`}
            style={[styles.segment, done[position] ? styles.segmentDone : null]}
          />
        ))}
      </View>

      <ScrollView contentContainerStyle={[styles.list, { paddingBottom: insets.bottom + 96 }]}>
        {recipe.steps.map((step, position) => {
          const checked = Boolean(done[position]);

          return (
            <Pressable
              key={`step-${position}`}
              onPress={() => {
                haptics.tap();
                onToggleStep(position);
              }}
              accessibilityRole="checkbox"
              accessibilityState={{ checked }}
              accessibilityLabel={`Étape ${position + 1}. ${step}`}
              accessibilityHint={checked ? 'Marquer comme à faire' : 'Marquer comme faite'}
              style={({ pressed }) => [
                styles.step,
                checked && styles.stepDone,
                pressed && styles.stepPressed,
              ]}
            >
              <View style={[styles.marker, checked && styles.markerDone]}>
                {checked ? (
                  <Icon name="check" size={16} color={colors.onInk} />
                ) : (
                  <Text style={styles.markerNumber}>{position + 1}</Text>
                )}
              </View>

              <View style={styles.stepBody}>
                <StepText value={step} />
              </View>
            </Pressable>
          );
        })}
      </ScrollView>

      {showIngredients ? (
        <View style={[styles.sheet, { bottom: insets.bottom + 76 }]}>
          <Text style={[text.label, styles.sheetTitle]}>Ingrédients · {recipe.servings} pers.</Text>
          <ScrollView style={styles.sheetList}>
            {recipe.ingredientsFromInventory.map((ingredient, position) => (
              <View key={`stock-${position}`} style={styles.ingredient}>
                <Text style={[text.body, styles.flex]}>{ingredient.label}</Text>
                <Text style={styles.ingredientAmount}>
                  {ingredient.quantity} {ingredient.unit}
                </Text>
              </View>
            ))}

            {/* Ce qui n'était pas au stock reste utile ici : on l'a acheté
                depuis, et il faut quand même savoir combien en mettre. */}
            {recipe.missingIngredients.map((ingredient, position) => (
              <View key={`missing-${position}`} style={styles.ingredient}>
                <Text style={[text.body, styles.flex]}>
                  {ingredient.label}
                  <Text style={styles.ingredientNote}>  à compléter</Text>
                </Text>
                <Text style={styles.ingredientAmount}>
                  {ingredient.quantity} {ingredient.unit}
                </Text>
              </View>
            ))}
          </ScrollView>
        </View>
      ) : null}

      <View style={[styles.foot, { paddingBottom: insets.bottom + spacing.md }]}>
        <Pressable
          onPress={onToggleIngredients}
          accessibilityRole="button"
          accessibilityLabel={showIngredients ? 'Masquer les ingrédients' : 'Voir les ingrédients'}
          accessibilityState={{ expanded: showIngredients }}
          style={({ pressed }) => [styles.footButton, pressed && styles.footButtonPressed]}
        >
          <Icon
            name={showIngredients ? 'chevron-down' : 'format-list-bulleted'}
            size={20}
            color={colors.ink}
          />
          <Text style={[text.button, styles.footLabel]}>
            {showIngredients ? 'Masquer les ingrédients' : 'Ingrédients'}
          </Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  surface: { flex: 1, backgroundColor: colors.ground },
  flex: { flex: 1 },

  head: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingHorizontal: spacing.lg,
    paddingTop: spacing.md,
    paddingBottom: spacing.md,
  },
  headButton: { width: 44, height: 44, borderRadius: radius.md, alignItems: 'center', justifyContent: 'center' },
  headButtonPressed: { backgroundColor: colors.surfaceSunk },
  headText: { flex: 1 },
  headTitle: { marginTop: 2 },
  progress: { fontFamily: font.monoSemiBold, fontSize: 17, lineHeight: 22, color: colors.ink },

  track: { flexDirection: 'row', gap: 2, paddingHorizontal: spacing.lg, paddingBottom: spacing.md },
  segment: { flex: 1, height: 3, borderRadius: 1, backgroundColor: colors.border },
  segmentDone: { backgroundColor: thermal.safe.line },

  list: { paddingHorizontal: spacing.lg },
  step: {
    flexDirection: 'row',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    padding: spacing.lg,
    marginBottom: spacing.sm,
  },
  stepPressed: { backgroundColor: colors.surfaceSunk },
  /** Faite : elle reste lisible — on doit pouvoir revenir en arrière — mais recule. */
  stepDone: { opacity: 0.42, backgroundColor: colors.surfaceSunk },
  stepBody: { flex: 1, paddingTop: 3 },

  marker: {
    width: 32,
    height: 32,
    borderRadius: radius.sm,
    borderWidth: 1,
    borderColor: colors.borderStrong,
    alignItems: 'center',
    justifyContent: 'center',
  },
  markerDone: { backgroundColor: colors.ink, borderColor: colors.ink },
  markerNumber: { fontFamily: font.monoSemiBold, fontSize: 14, lineHeight: 18, color: colors.ink },

  /** 21 px : la taille d'une instruction lue debout, pas celle d'une notice. */
  stepText: { fontFamily: font.sans, fontSize: 21, lineHeight: 31, color: colors.ink },
  measure: { fontFamily: font.monoMedium, fontSize: 18, color: colors.ink },

  sheet: {
    position: 'absolute',
    left: spacing.lg,
    right: spacing.lg,
    maxHeight: '52%',
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.borderStrong,
    padding: spacing.lg,
  },
  sheetTitle: { marginBottom: spacing.md },
  sheetList: { flexGrow: 0 },
  ingredient: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  ingredientAmount: { fontFamily: font.monoMedium, fontSize: 14, lineHeight: 19, color: colors.ink },
  ingredientNote: { ...text.small, color: thermal.soon.line },

  foot: {
    borderTopWidth: 1,
    borderTopColor: colors.border,
    backgroundColor: colors.surface,
    paddingHorizontal: spacing.lg,
    paddingTop: spacing.md,
  },
  footButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.sm,
    minHeight: 48,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
  },
  footButtonPressed: { backgroundColor: colors.surfaceSunk },
  footLabel: { color: colors.ink },
});
