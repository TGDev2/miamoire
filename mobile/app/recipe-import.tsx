import { useRouter } from 'expo-router';
import React, { useRef, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError, api } from '../src/api/client';
import type { ImportedRecipe, RecipeImportResponse } from '../src/api/types';
import { Badge, Button, Card, ErrorBanner, Field, SectionTitle } from '../src/components/ui';
import { useFeedback } from '../src/components/Feedback';
import * as haptics from '../src/haptics';
import { colors, feasibilityPresentation, font, spacing, text, thermal } from '../src/theme';

/**
 * Brings back a recipe written by another assistant.
 *
 * The server only reads here: it finds the recipes in the pasted answer, ties
 * them to the household's foods, refuses those that break an exclusion and
 * plans the rest against the stock. Keeping one goes through the same save as
 * any proposal — which is what makes it a recipe like the others, visible to
 * the whole household.
 *
 * Each card says, before saving, what will and will not be deducted: the
 * assistant's readable text and its import block can disagree, and the block
 * is what the app acts on.
 */
export default function RecipeImportScreen() {
  const router = useRouter();
  const { notify } = useFeedback();

  const [answer, setAnswer] = useState('');
  const [reading, setReading] = useState(false);
  const [result, setResult] = useState<RecipeImportResponse | null>(null);
  /** What the user should do about the text itself: copy more, ask again. */
  const [guidance, setGuidance] = useState<string | null>(null);
  /** The server could not be reached or failed: the text is kept, the same button retries. */
  const [failure, setFailure] = useState<string | null>(null);
  const [savingIndex, setSavingIndex] = useState<number | null>(null);
  // Two taps can land in the same render: state alone would let both through
  // and save before either navigates (lesson 9).
  const busy = useRef(false);

  function edit(value: string) {
    setAnswer(value);
    // Results describe a text that no longer exists.
    setResult(null);
    setGuidance(null);
    setFailure(null);
  }

  async function read() {
    if (busy.current) return;
    busy.current = true;
    haptics.tap();
    setReading(true);
    setResult(null);
    setGuidance(null);
    setFailure(null);

    try {
      const response = await api.importRecipes(answer);
      setResult(response);
      if (response.recipes.length > 0) {
        haptics.success();
      } else {
        haptics.failure();
      }
    } catch (cause) {
      haptics.failure();
      // A refused text is a fact about the text; anything else is a failure to
      // know, and says so — never "no recipe found" (lesson 27).
      if (cause instanceof ApiError && (422 === cause.status || 400 === cause.status)) {
        setGuidance(cause.message);
      } else {
        const message = cause instanceof Error ? cause.message : 'La lecture a échoué.';
        setFailure(`${message} Votre texte est conservé : touchez « Lire la recette » pour réessayer.`);
      }
    } finally {
      setReading(false);
      busy.current = false;
    }
  }

  async function save(imported: ImportedRecipe, index: number) {
    if (busy.current) return;
    busy.current = true;
    haptics.tap();
    setSavingIndex(index);
    setFailure(null);

    try {
      const saved = await api.saveRecipe(imported.recipe);
      notify('Recette enregistrée, visible par tout le foyer.', 'safe');
      // The id the server returned, not one read from state.
      router.replace(`/saved/${saved.id}`);
    } catch (cause) {
      haptics.failure();
      setFailure(cause instanceof Error ? cause.message : "L'enregistrement a échoué. Réessayez.");
    } finally {
      setSavingIndex(null);
      busy.current = false;
    }
  }

  const empty = '' === answer.trim();

  return (
    <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
        <Text style={text.body}>
          Rapportez ici une recette écrite par un autre assistant, pour la garder avec les vôtres.
        </Text>

        {/* A real sequence: each step needs the one before. */}
        <View style={styles.steps}>
          {[
            'Dans Cuisiner, « Exporter vers un autre assistant » — ou, sur une recette, « Modifier avec un autre assistant ».',
            "Dans l'assistant, copiez sa réponse : en entier, ou seulement la recette choisie avec son bloc de code.",
            'Collez-la ci-dessous : appui long dans le champ, puis « Coller ».',
          ].map((step, position) => (
            <View key={step} style={styles.step}>
              <Text style={styles.stepNumber} accessibilityElementsHidden importantForAccessibility="no">
                {position + 1}
              </Text>
              <Text style={[text.small, styles.flex]} accessibilityLabel={`Étape ${position + 1} : ${step}`}>
                {step}
              </Text>
            </View>
          ))}
        </View>

        <Field
          label="Réponse de l'assistant"
          value={answer}
          onChangeText={edit}
          multiline
          placeholder="Collez la réponse ici"
          autoCorrect={false}
          autoCapitalize="none"
          hint="Seuls les blocs de code de la réponse sont lus ; le texte autour est ignoré."
          style={styles.pasted}
        />

        <Button
          label="Lire la recette"
          icon="tray-arrow-down"
          onPress={read}
          loading={reading}
          disabled={empty || reading}
        />

        <View style={styles.feedback}>
          <ErrorBanner message={failure} />
          {guidance ? (
            <Card level="soon">
              <Text style={text.body}>{guidance}</Text>
            </Card>
          ) : null}
        </View>

        {result ? (
          <>
            {result.recipes.length > 0 ? (
              <SectionTitle>
                {1 === result.recipes.length ? 'Recette trouvée' : `${result.recipes.length} recettes trouvées`}
              </SectionTitle>
            ) : null}

            {result.recipes.map((imported, index) => (
              <ImportedCard
                key={`${index}-${imported.recipe.recipeName}`}
                imported={imported}
                saving={savingIndex === index}
                disabled={savingIndex !== null}
                onSave={() => save(imported, index)}
              />
            ))}

            {result.rejected.length > 0 ? (
              <>
                <SectionTitle>Non importée{result.rejected.length > 1 ? 's' : ''}</SectionTitle>
                {result.rejected.map((rejected, index) => (
                  <Card key={`${index}-${rejected.recipeName}`} level="critical" style={styles.card}>
                    <Text style={text.heading}>{rejected.recipeName}</Text>
                    <Text style={[text.small, styles.spaced]}>{rejected.reason}</Text>
                  </Card>
                ))}
              </>
            ) : null}
          </>
        ) : null}

        <Text style={[text.small, styles.footer]}>
          L'application revérifie les exclusions du foyer et ce que votre stock permet de retirer.
          Le reste de la demande — aliment imposé, composition, temps, équipements — est laissé à
          l'autre assistant.
        </Text>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

/** One recipe found in the answer, with what keeping and cooking it would do to the stock. */
function ImportedCard({
  imported,
  saving,
  disabled,
  onSave,
}: {
  imported: ImportedRecipe;
  saving: boolean;
  disabled: boolean;
  onSave: () => void;
}) {
  const { recipe, notices } = imported;
  const presentation = feasibilityPresentation[recipe.plan.status] ?? feasibilityPresentation.ready;

  return (
    <Card level={presentation.level} style={styles.card}>
      <Badge label={presentation.label} level={presentation.level} />
      <Text style={[text.title, styles.title]}>{recipe.recipeName}</Text>
      {recipe.summary ? <Text style={[text.small, styles.spaced]}>{recipe.summary}</Text> : null}
      {recipe.plan.reason ? <Text style={[text.small, styles.spaced]}>{recipe.plan.reason}</Text> : null}

      <View style={styles.metaRow}>
        <Text style={styles.meta}>{recipe.preparationTime} min</Text>
        <Text style={styles.meta}>{recipe.servings} pers.</Text>
        <Text style={styles.meta}>
          {recipe.steps.length} étape{recipe.steps.length > 1 ? 's' : ''}
        </Text>
      </View>

      {/* The same name as on the recipe screen, where it is confirmed after cooking. */}
      <Text style={[text.label, styles.blockLabel]}>Déduction du stock, une fois cuisinée</Text>
      {recipe.stockConsumptionProposal.length > 0 ? (
        recipe.stockConsumptionProposal.map((line) => (
          <View key={`deduct-${line.key}`} style={styles.line}>
            <Text style={[text.body, styles.flex]}>{line.label}</Text>
            <Text style={styles.quantity}>
              {line.quantity} {line.unit}
            </Text>
          </View>
        ))
      ) : (
        <Text style={text.small}>Rien à retirer automatiquement de votre stock.</Text>
      )}

      {notices.length > 0 ? (
        <>
          <Text style={[text.label, styles.blockLabel, { color: thermal.soon.line }]}>Non déduit</Text>
          {notices.map((notice) => (
            <Text key={notice} style={[text.small, styles.notice]}>
              {notice}
            </Text>
          ))}
        </>
      ) : null}

      <Button
        label="Enregistrer dans mes recettes"
        variant="secondary"
        icon="content-save-outline"
        onPress={onSave}
        loading={saving}
        disabled={disabled}
        hint={`« ${recipe.recipeName} » rejoint les recettes du foyer`}
        style={styles.save}
      />
    </Card>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  container: { padding: spacing.xl, paddingBottom: spacing.xxl },
  steps: { marginTop: spacing.lg, marginBottom: spacing.xl, gap: spacing.sm },
  step: { flexDirection: 'row', gap: spacing.md },
  stepNumber: { fontFamily: font.monoMedium, fontSize: 13, lineHeight: 19, color: colors.ink, minWidth: 12 },
  pasted: { fontFamily: font.mono, fontSize: 12, lineHeight: 17 },
  feedback: { marginTop: spacing.lg, gap: spacing.md },
  card: { marginBottom: spacing.lg },
  title: { marginTop: spacing.md },
  spaced: { marginTop: spacing.xs },
  metaRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.lg,
    marginTop: spacing.md,
    paddingTop: spacing.md,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  meta: { fontFamily: font.monoMedium, fontSize: 11, lineHeight: 15, color: colors.ink },
  blockLabel: { marginTop: spacing.lg, marginBottom: spacing.xs },
  line: { flexDirection: 'row', gap: spacing.md, paddingVertical: 2 },
  quantity: { fontFamily: font.mono, fontSize: 13, lineHeight: 22, color: colors.ink },
  notice: { color: thermal.soon.line, marginTop: spacing.xs },
  save: { marginTop: spacing.lg },
  footer: { marginTop: spacing.xl },
});
