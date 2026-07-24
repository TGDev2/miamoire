import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  type TextInputProps,
  View,
  type ViewStyle,
} from 'react-native';

import { colors, radius, spacing } from '../theme';

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
    <View style={[styles.badge, { backgroundColor: background }]}>
      <Text style={[styles.badgeText, { color }]}>{label}</Text>
    </View>
  );
}

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';

export function Button({
  label,
  onPress,
  variant = 'primary',
  disabled = false,
  loading = false,
  style,
}: {
  label: string;
  onPress: () => void;
  variant?: ButtonVariant;
  disabled?: boolean;
  loading?: boolean;
  style?: ViewStyle;
}) {
  const isInactive = disabled || loading;

  return (
    <Pressable
      onPress={onPress}
      disabled={isInactive}
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
      style={[styles.chip, selected ? styles.chipSelected : styles.chipIdle]}
    >
      <Text style={[styles.chipText, selected && styles.chipTextSelected]}>{label}</Text>
    </Pressable>
  );
}

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
        style={styles.input}
        {...inputProps}
      />
      {hint ? <Text style={styles.fieldHint}>{hint}</Text> : null}
    </View>
  );
}

export function SectionTitle({ children }: { children: React.ReactNode }) {
  return <Text style={styles.sectionTitle}>{children}</Text>;
}

export function ErrorBanner({ message }: { message: string | null }) {
  if (!message) return null;

  return (
    <View style={styles.errorBanner}>
      <Text style={styles.errorText}>{message}</Text>
    </View>
  );
}

export function EmptyState({ title, description }: { title: string; description: string }) {
  return (
    <View style={styles.empty}>
      <Text style={styles.emptyTitle}>{title}</Text>
      <Text style={styles.emptyDescription}>{description}</Text>
    </View>
  );
}

export function Loader({ label }: { label?: string }) {
  return (
    <View style={styles.loader}>
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
