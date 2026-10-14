import React, { useState } from 'react';
import { Alert, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '../api/client';
import type { ProductCutValue, Reference } from '../api/types';
import { spacing, text, thermal } from '../theme';
import { Chip } from './ui';

type CutOption = Reference['productCuts'][number];

/**
 * "Sous quelle forme ?" — asked only for a food whose cut changes the cooking.
 *
 * An unknown cut is the one thing on the line that asks for something, so it
 * alone takes the amber of the thermal scale. A known cut stays quiet: with
 * `collapsible`, it shrinks to its own chip and a "Changer" — a grocery trip
 * of twenty lines must not become twenty rows of nine chips.
 */
export function CutPicker({
  options,
  value,
  onChange,
  collapsible = false,
  help = true,
}: {
  /** The cuts this food can take, already filtered: empty means "do not ask". */
  options: CutOption[];
  value: ProductCutValue | null;
  onChange: (cut: ProductCutValue | null) => void;
  collapsible?: boolean;
  /** Off where the screen already explains why the cut matters. */
  help?: boolean;
}) {
  const [expanded, setExpanded] = useState(false);

  if (0 === options.length) return null;

  const selected = options.find((option) => option.value === value) ?? null;

  if (null === selected) {
    return (
      <View>
        <Text style={[text.label, styles.unknown]}>Sous quelle forme ?</Text>
        {help ? (
          <Text style={[text.small, styles.help]}>La cuisson en dépend : précisez-la, elle sera retenue.</Text>
        ) : null}
        <View style={styles.chips}>
          {options.map((option) => (
            <Chip
              key={option.value}
              label={option.label}
              selected={false}
              onPress={() => {
                onChange(option.value);
                setExpanded(false);
              }}
            />
          ))}
        </View>
      </View>
    );
  }

  if (collapsible && !expanded) {
    return (
      <View>
        <Text style={[text.label, styles.label]}>Forme</Text>
        <View style={styles.chips}>
          <Chip label={selected.label} selected onPress={() => setExpanded(true)} />
          <Chip label="Changer" selected={false} onPress={() => setExpanded(true)} />
        </View>
      </View>
    );
  }

  return (
    <View>
      <Text style={[text.label, styles.label]}>Forme</Text>
      <View style={styles.chips}>
        {options.map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={option.value === value}
            onPress={() => {
              // Tapping the chosen cut again clears it: "I don't know" is an answer.
              onChange(option.value === value ? null : option.value);
              setExpanded(false);
            }}
          />
        ))}
      </View>
    </View>
  );
}

/**
 * A cut that makes this food identical to one already in stock is refused by
 * the server (`variant_collision`): two stocks are never merged silently. The
 * answer is the user's — merge, or keep both apart. Returns `false` when the
 * failure is something else, for the caller to report.
 */
export function confirmCutMerge(exception: unknown, merge: () => void): boolean {
  if (!(exception instanceof ApiError) || 'variant_collision' !== exception.code) return false;

  Alert.alert('Même aliment', `${exception.message}\n\nRéunir les deux ? Ses lots rejoindront cet aliment.`, [
    { text: 'Annuler', style: 'cancel' },
    { text: 'Réunir les deux', onPress: merge },
  ]);

  return true;
}

const styles = StyleSheet.create({
  label: { marginBottom: spacing.sm },
  unknown: { color: thermal.soon.line, marginBottom: spacing.sm },
  help: { marginTop: -spacing.xs, marginBottom: spacing.sm },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
});
