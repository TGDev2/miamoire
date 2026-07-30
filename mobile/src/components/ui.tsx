import DateTimePicker from '@react-native-community/datetimepicker';
import React, { useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  type StyleProp,
  Text,
  TextInput,
  type TextInputProps,
  View,
  type ViewStyle,
} from 'react-native';

import { colors, radius, spacing } from '../theme';

/** `Date` → `« 2026-08-02 »`, le format que l'API attend pour une date sans heure. */
export function toIsoDate(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

/** L'inverse, interprété localement : `new Date(iso)` serait en UTC, donc décalé d'un jour. */
export function fromIsoDate(value: string): Date {
  const [year, month, day] = value.split('-').map(Number);
  return new Date(year, month - 1, day);
}

export function Card({ children, style }: { children: React.ReactNode; style?: ViewStyle }) {
  return <View style={[styles.card, style]}>{children}</View>;
}

export function Badge({
  label,
  color,
  background,
}: {
  label: string;
  color: string;
  background: string;
}) {
  return (
    <View style={[styles.badge, { backgroundColor: background }]} accessible accessibilityLabel={label}>
      <Text style={[styles.badgeText, { color }]}>{label}</Text>
    </View>
  );
}

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';

/**
 * L'état de chargement remplace le texte par un indicateur : sans
 * `accessibilityState`, le bouton devient donc muet ET inactif, sans qu'un
 * lecteur d'écran puisse dire pourquoi. Le libellé est conservé dans tous les cas.
 */
export function Button({
  label,
  onPress,
  variant = 'primary',
  disabled = false,
  loading = false,
  hint,
  style,
}: {
  label: string;
  onPress: () => void;
  variant?: ButtonVariant;
  disabled?: boolean;
  loading?: boolean;
  /** Ce que l'action va faire, quand le libellé seul ne suffit pas à le dire. */
  hint?: string;
  /** `StyleProp` et non `ViewStyle` : un état conditionnel se compose en tableau. */
  style?: StyleProp<ViewStyle>;
}) {
  const isInactive = disabled || loading;

  return (
    <Pressable
      onPress={onPress}
      disabled={isInactive}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityHint={hint}
      accessibilityState={{ disabled: isInactive, busy: loading }}
      style={({ pressed }) => [
        styles.button,
        buttonVariants[variant].container,
        pressed && !isInactive && styles.buttonPressed,
        isInactive && styles.buttonDisabled,
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={buttonVariants[variant].text.color} />
      ) : (
        <Text style={[styles.buttonText, buttonVariants[variant].text]}>{label}</Text>
      )}
    </Pressable>
  );
}

/** La sélection n'est portée que par la couleur : sans `selected`, elle est invisible. */
export function Chip({
  label,
  selected,
  onPress,
}: {
  label: string;
  selected: boolean;
  onPress: () => void;
}) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ selected }}
      style={[styles.chip, selected ? styles.chipSelected : styles.chipIdle]}
    >
      <Text style={[styles.chipText, selected && styles.chipTextSelected]}>{label}</Text>
    </Pressable>
  );
}

/**
 * Le libellé et l'aide sont des `Text` voisins, donc invisibles pour le champ
 * lui-même : à la saisie, un lecteur d'écran n'annonçait que « champ de texte ».
 * On les rattache, sans empêcher un appelant de préciser son propre libellé.
 */
export function Field({
  label,
  hint,
  containerStyle,
  ...inputProps
}: TextInputProps & { label: string; hint?: string; containerStyle?: ViewStyle }) {
  return (
    <View style={[styles.field, containerStyle]}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <TextInput
        placeholderTextColor={colors.inkMuted}
        accessibilityLabel={label}
        accessibilityHint={hint}
        style={styles.input}
        {...inputProps}
      />
      {hint ? <Text style={styles.fieldHint}>{hint}</Text> : null}
    </View>
  );
}

/** Une date au format AAAA-MM-JJ, saisie via le sélecteur natif plutôt qu'au clavier. */
export function DateField({
  label,
  value,
  onChange,
  placeholder = 'Aucune date',
}: {
  label: string;
  value: string | null;
  onChange: (value: string) => void;
  placeholder?: string;
}) {
  const [showPicker, setShowPicker] = useState(false);

  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <Pressable
        onPress={() => setShowPicker(true)}
        style={styles.input}
        accessibilityRole="button"
        accessibilityLabel={label}
        accessibilityHint={value ? formatDisplayDate(fromIsoDate(value)) : placeholder}
      >
        <Text style={{ color: value ? colors.ink : colors.inkMuted, fontSize: 16 }}>
          {value ? formatDisplayDate(fromIsoDate(value)) : placeholder}
        </Text>
      </Pressable>
      {showPicker ? (
        <DateTimePicker
          value={value ? fromIsoDate(value) : new Date()}
          mode="date"
          onChange={(event, selected) => {
            setShowPicker(false);
            if ('set' === event.type && selected) {
              onChange(toIsoDate(selected));
            }
          }}
        />
      ) : null}
    </View>
  );
}

function formatDisplayDate(date: Date): string {
  return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
}

export function SectionTitle({ children }: { children: React.ReactNode }) {
  return (
    <Text style={styles.sectionTitle} accessibilityRole="header">
      {children}
    </Text>
  );
}

/**
 * Une erreur apparaît en haut de l'écran, souvent loin du geste qui l'a causée.
 * `assertive` parce qu'elle répond à une action que la personne vient de faire :
 * attendre la fin de la lecture en cours lui ferait croire que rien ne s'est passé.
 */
export function ErrorBanner({ message }: { message: string | null }) {
  if (!message) return null;

  return (
    <View
      style={styles.errorBanner}
      accessible
      accessibilityRole="alert"
      accessibilityLiveRegion="assertive"
      accessibilityLabel={`Erreur : ${message}`}
    >
      <Text style={styles.errorText}>{message}</Text>
    </View>
  );
}

export function EmptyState({ title, description }: { title: string; description: string }) {
  return (
    <View style={styles.empty} accessible accessibilityLabel={`${title}. ${description}`}>
      <Text style={styles.emptyTitle}>{title}</Text>
      <Text style={styles.emptyDescription}>{description}</Text>
    </View>
  );
}

export function Loader({ label }: { label?: string }) {
  return (
    <View
      style={styles.loader}
      accessible
      accessibilityRole="progressbar"
      accessibilityLabel={label ?? 'Chargement en cours'}
    >
      <ActivityIndicator color={colors.primary} size="large" />
      {label ? <Text style={styles.loaderLabel}>{label}</Text> : null}
    </View>
  );
}

const buttonVariants: Record<ButtonVariant, { container: ViewStyle; text: { color: string } }> = {
  primary: { container: { backgroundColor: colors.primary }, text: { color: '#FFFFFF' } },
  secondary: {
    container: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
    text: { color: colors.ink },
  },
  ghost: { container: { backgroundColor: 'transparent' }, text: { color: colors.primary } },
  danger: { container: { backgroundColor: colors.dangerSoft }, text: { color: colors.danger } },
};

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
    borderWidth: 1,
    borderColor: colors.border,
  },
  badge: {
    paddingHorizontal: spacing.sm + 2,
    paddingVertical: 3,
    borderRadius: radius.pill,
    alignSelf: 'flex-start',
  },
  badgeText: { fontSize: 12, fontWeight: '600' },
  button: {
    paddingVertical: 14,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.md,
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 48,
  },
  buttonPressed: { opacity: 0.8 },
  buttonDisabled: { opacity: 0.5 },
  buttonText: { fontSize: 15, fontWeight: '600' },
  chip: {
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderRadius: radius.pill,
    borderWidth: 1,
    marginRight: spacing.sm,
    marginBottom: spacing.sm,
  },
  chipIdle: { backgroundColor: colors.surface, borderColor: colors.border },
  chipSelected: { backgroundColor: colors.primary, borderColor: colors.primary },
  chipText: { color: colors.ink, fontSize: 14 },
  chipTextSelected: { color: '#FFFFFF', fontWeight: '600' },
  field: { marginBottom: spacing.lg },
  fieldLabel: {
    fontSize: 13,
    fontWeight: '600',
    color: colors.inkMuted,
    marginBottom: spacing.xs + 2,
  },
  fieldHint: { fontSize: 12, color: colors.inkMuted, marginTop: spacing.xs },
  input: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
    fontSize: 16,
    color: colors.ink,
  },
  sectionTitle: {
    fontSize: 13,
    fontWeight: '700',
    color: colors.inkMuted,
    textTransform: 'uppercase',
    letterSpacing: 0.6,
    marginBottom: spacing.sm,
    marginTop: spacing.lg,
  },
  errorBanner: {
    backgroundColor: colors.dangerSoft,
    borderRadius: radius.md,
    padding: spacing.md,
    marginBottom: spacing.lg,
  },
  errorText: { color: colors.danger, fontSize: 14 },
  empty: { alignItems: 'center', paddingVertical: spacing.xxl, paddingHorizontal: spacing.lg },
  emptyTitle: { fontSize: 17, fontWeight: '700', color: colors.ink, marginBottom: spacing.sm },
  emptyDescription: { fontSize: 14, color: colors.inkMuted, textAlign: 'center', lineHeight: 20 },
  loader: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
  loaderLabel: { marginTop: spacing.md, color: colors.inkMuted, fontSize: 14 },
});
