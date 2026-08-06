import MaterialCommunityIcons from '@expo/vector-icons/MaterialCommunityIcons';
import DateTimePicker from '@react-native-community/datetimepicker';
import React, { useState } from 'react';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Switch,
  type StyleProp,
  Text,
  TextInput,
  type TextInputProps,
  View,
  type ViewStyle,
} from 'react-native';

import {
  colors,
  deadlineSentence,
  font,
  radius,
  spacing,
  stampLabel,
  text,
  thermal,
  thermalFor,
  type ThermalLevel,
} from '../theme';

/**
 * Les cinq onglets n'ont pas de barre native : rien ne les tient donc sous la
 * barre d'état, et leur premier titre passait dessous. Chacun réserve la marge
 * lui-même, sur son conteneur défilant et non sur son contenu — sinon le texte
 * repasserait sous l'heure dès qu'on fait défiler.
 */
export function useTopInset(): { paddingTop: number } {
  return { paddingTop: useSafeAreaInsets().top };
}

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

export function formatDisplayDate(iso: string): string {
  return fromIsoDate(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
}

/** Sans l'année : dans une liste de jours à venir, elle n'apporte rien. */
export function formatShortDate(iso: string): string {
  return fromIsoDate(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' });
}

export type IconName = React.ComponentProps<typeof MaterialCommunityIcons>['name'];

/**
 * Le trait des icônes est le même partout : filaire, taille 20, encre atténuée.
 * Elles font partie du mobilier — elles ne prennent jamais de couleur thermique,
 * qui est réservée aux échéances.
 */
export function Icon({
  name,
  size = 20,
  color = colors.inkMuted,
}: {
  name: IconName;
  size?: number;
  color?: string;
}) {
  return <MaterialCommunityIcons name={name} size={size} color={color} />;
}

/**
 * Le cachet : la signature de l'application.
 *
 * `J−2`, `JOUR J`, `J+3`, `~J−5` — le décompte imprimé, dans la voix machine et
 * la couleur de son palier thermique. Il accompagne chaque lot périssable,
 * partout où il apparaît, pour qu'une liste se lise sans être lue.
 *
 * Il ne porte jamais l'information seul : la phrase complète reste dans le
 * libellé d'accessibilité, et les appelants qui l'énoncent déjà dans le leur
 * passent `decorative`.
 */
export function Stamp({
  days,
  date = null,
  estimated = false,
  decorative = false,
}: {
  days: number | null;
  date?: string | null;
  estimated?: boolean;
  decorative?: boolean;
}) {
  const palette = thermal[thermalFor(days)];

  return (
    <View
      style={[styles.stamp, { backgroundColor: palette.wash }]}
      importantForAccessibility={decorative ? 'no-hide-descendants' : 'yes'}
      accessible={!decorative}
      accessibilityLabel={decorative ? undefined : deadlineSentence(days, estimated)}
    >
      <Text style={[text.stamp, { color: palette.line }]} numberOfLines={1}>
        {stampLabel(days, date, estimated)}
      </Text>
    </View>
  );
}

/** Le même décompte, en tête de fiche : plus de pastille, le chiffre seul. */
export function StampHero({
  days,
  date = null,
  estimated = false,
}: {
  days: number | null;
  date?: string | null;
  estimated?: boolean;
}) {
  const palette = thermal[thermalFor(days)];

  return (
    <View accessible accessibilityLabel={deadlineSentence(days, estimated)}>
      <Text style={[text.stampLarge, { color: palette.line }]}>{stampLabel(days, date, estimated)}</Text>
      <Text style={[text.small, styles.stampHeroCaption]}>{deadlineSentence(days, estimated)}</Text>
    </View>
  );
}

/**
 * Une surface blanche sur le givre. Aucune ombre : le relief vient du contraste
 * et du filet, pas d'un flou.
 *
 * `level` allume le rail thermique de 3 px sur le bord gauche — c'est lui qui
 * rend une colonne de cartes lisible d'un seul regard.
 */
export function Card({
  children,
  level,
  style,
}: {
  children: React.ReactNode;
  level?: ThermalLevel;
  style?: StyleProp<ViewStyle>;
}) {
  return (
    <View style={[styles.card, level ? styles.cardRailed : null, style]}>
      {children}
      {/* Rendu après les enfants : une zone pressée qui repeint son fond
          effacerait sinon le rail sur ses trois pixels. */}
      {level ? <View style={[styles.rail, { backgroundColor: thermal[level].line }]} /> : null}
    </View>
  );
}

/** Un état, une nature, un motif — toujours dans une teinte de l'échelle thermique. */
export function Badge({ label, level = 'calm' }: { label: string; level?: ThermalLevel }) {
  const palette = thermal[level];

  return (
    <View style={[styles.badge, { backgroundColor: palette.wash }]} accessible accessibilityLabel={label}>
      <Text style={[styles.badgeText, { color: palette.line }]}>{label}</Text>
    </View>
  );
}

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';

/**
 * Le libellé reste affiché pendant le chargement, à côté de l'indicateur.
 *
 * Il était remplacé par lui. Sur « Proposer 3 recettes », dont la réponse
 * demande quarante à cinquante secondes, l'écran devenait alors littéralement
 * muet : plus un mot pendant une minute, pour la plus longue attente de
 * l'application. Un bouton qui travaille doit continuer à dire ce qu'il fait.
 */
export function Button({
  label,
  onPress,
  variant = 'primary',
  disabled = false,
  loading = false,
  icon,
  hint,
  style,
}: {
  label: string;
  onPress: () => void;
  variant?: ButtonVariant;
  disabled?: boolean;
  loading?: boolean;
  icon?: IconName;
  /** Ce que l'action va faire, quand le libellé seul ne suffit pas à le dire. */
  hint?: string;
  /** `StyleProp` et non `ViewStyle` : un état conditionnel se compose en tableau. */
  style?: StyleProp<ViewStyle>;
}) {
  const isInactive = disabled || loading;
  const skin = buttonVariants[variant];

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
        skin.container,
        pressed && !isInactive && skin.pressed,
        isInactive && styles.buttonDisabled,
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={skin.text.color} size="small" />
      ) : icon ? (
        <Icon name={icon} size={18} color={skin.text.color} />
      ) : null}
      <Text style={[text.button, skin.text]} numberOfLines={1}>
        {label}
      </Text>
    </Pressable>
  );
}

/**
 * Une action secondaire réduite à son icône — toujours accompagnée de son
 * libellé d'accessibilité, jamais devinable au dessin seul.
 */
export function IconButton({
  icon,
  label,
  onPress,
  tone = 'neutral',
  disabled = false,
}: {
  icon: IconName;
  label: string;
  onPress: () => void;
  tone?: 'neutral' | 'critical';
  disabled?: boolean;
}) {
  const tint = 'critical' === tone ? thermal.critical.line : colors.inkMuted;

  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      hitSlop={6}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled }}
      style={({ pressed }) => [
        styles.iconButton,
        pressed && !disabled && styles.iconButtonPressed,
        disabled && styles.buttonDisabled,
      ]}
    >
      <Icon name={icon} size={18} color={tint} />
    </Pressable>
  );
}

/**
 * La sélection n'est portée que par la couleur : sans `selected`, elle est
 * invisible.
 *
 * C'est la cible la plus fréquente de l'application — unités, emplacements,
 * traits, découpes, équipements, filtres du stock. Elle mesurait 36 px de haut,
 * sous les 44 pt d'iOS comme les 48 dp d'Android, et se manipule au pouce dans
 * une rangée qui défile horizontalement : un tap manqué déclenchait un défilement.
 */
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
      hitSlop={4}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ selected }}
      style={({ pressed }) => [
        styles.chip,
        selected ? styles.chipSelected : styles.chipIdle,
        pressed && !selected && styles.chipPressed,
      ]}
    >
      <Text style={[styles.chipText, selected && styles.chipTextSelected]}>{label}</Text>
    </Pressable>
  );
}

/**
 * « Combien ? » quand la réponse est un nombre d'objets entiers.
 *
 * Trois courgettes se saisissent en touchant deux fois « + », pas en tapant un
 * poids qu'on ne connaît pas. Le clavier reste accessible pour les grandes
 * quantités — on ne touche pas quinze fois « + » pour quinze clémentines.
 */
export function Stepper({
  label,
  value,
  unitLabel,
  onChange,
  min = 1,
  max = Number.MAX_SAFE_INTEGER,
  hint,
}: {
  /** Omis quand la rubrique juste au-dessus dit déjà ce qu'on compte. */
  label?: string;
  value: number;
  /** « courgettes », « portions » — ce que compte le nombre affiché. */
  unitLabel: string;
  onChange: (value: number) => void;
  min?: number;
  max?: number;
  hint?: string;
}) {
  return (
    <View style={styles.field}>
      {label ? <Text style={styles.fieldLabel}>{label}</Text> : null}
      <View style={styles.stepper}>
        <Pressable
          onPress={() => onChange(Math.max(min, value - 1))}
          disabled={value <= min}
          hitSlop={8}
          accessibilityRole="button"
          accessibilityLabel="Retirer un"
          accessibilityState={{ disabled: value <= min }}
          style={({ pressed }) => [
            styles.stepperButton,
            pressed && styles.stepperButtonPressed,
            value <= min && styles.stepperButtonDisabled,
          ]}
        >
          <Icon name="minus" size={20} color={colors.ink} />
        </Pressable>

        <TextInput
          value={String(value)}
          onChangeText={(entry) => {
            const parsed = Number(entry.replace(/[^0-9]/g, ''));
            onChange(Number.isFinite(parsed) && parsed >= min ? Math.min(parsed, max) : min);
          }}
          keyboardType="number-pad"
          selectTextOnFocus
          accessibilityLabel={`${label ?? unitLabel} : ${value} ${unitLabel}`}
          style={styles.stepperValue}
        />

        <Pressable
          onPress={() => onChange(Math.min(max, value + 1))}
          disabled={value >= max}
          hitSlop={8}
          accessibilityRole="button"
          accessibilityLabel="Ajouter un"
          accessibilityState={{ disabled: value >= max }}
          style={({ pressed }) => [
            styles.stepperButton,
            pressed && styles.stepperButtonPressed,
            value >= max && styles.stepperButtonDisabled,
          ]}
        >
          <Icon name="plus" size={20} color={colors.ink} />
        </Pressable>

        <Text style={styles.stepperUnit} numberOfLines={1}>
          {unitLabel}
        </Text>
      </View>
      {hint ? <Text style={styles.fieldHint}>{hint}</Text> : null}
    </View>
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
  hideLabel = false,
  containerStyle,
  ...inputProps
}: TextInputProps & {
  label: string;
  hint?: string;
  /** La rubrique juste au-dessus dit déjà la même chose : on la garde pour les
   *  lecteurs d'écran, on ne l'imprime pas deux fois. */
  hideLabel?: boolean;
  containerStyle?: ViewStyle;
}) {
  return (
    <View style={[styles.field, containerStyle]}>
      {hideLabel ? null : <Text style={styles.fieldLabel}>{label}</Text>}
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
        style={[styles.input, styles.inputRow]}
        accessibilityRole="button"
        accessibilityLabel={label}
        accessibilityHint={value ? formatDisplayDate(value) : placeholder}
      >
        <Text style={[text.body, !value && { color: colors.inkMuted }]}>
          {value ? formatDisplayDate(value) : placeholder}
        </Text>
        <Icon name="calendar-blank-outline" size={18} />
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

/**
 * L'ouverture d'un onglet : la rubrique en voix machine, puis le titre en grand.
 *
 * Les cinq onglets portent leur titre dans le flux plutôt que dans une barre
 * native — c'est le seul endroit où l'application se permet de la place.
 */
export function ScreenHeader({
  eyebrow,
  title,
  description,
  action,
}: {
  eyebrow: string;
  title: string;
  description?: string;
  action?: React.ReactNode;
}) {
  return (
    <View style={styles.screenHeader}>
      <View style={styles.screenHeaderText}>
        <Text style={text.label}>{eyebrow}</Text>
        <Text style={[text.display, styles.screenHeaderTitle]} accessibilityRole="header">
          {title}
        </Text>
        {description ? <Text style={[text.small, styles.screenHeaderDescription]}>{description}</Text> : null}
      </View>
      {action}
    </View>
  );
}

/**
 * Le début d'une rubrique : le libellé en voix machine, prolongé d'un filet
 * jusqu'au bord. Le filet n'est pas décoratif — c'est lui qui dit où commence
 * la section quand la carte suivante est blanche sur blanc.
 */
export function SectionTitle({ children }: { children: React.ReactNode }) {
  return (
    <View style={styles.sectionTitle}>
      {/* En encre, là où un libellé de champ reste en acier : c'est la seule
          chose qui distingue les deux niveaux, tous deux en capitales. */}
      <Text style={[text.label, styles.sectionTitleText]} accessibilityRole="header">
        {children}
      </Text>
      <View style={styles.sectionRule} />
    </View>
  );
}

/** Une ligne touchable : libellé, sous-titre facultatif, chevron — jamais un bouton plein. */
export function Row({
  label,
  subtitle,
  icon,
  onPress,
  disabled = false,
  last = false,
  trailing,
}: {
  label: string;
  subtitle?: string;
  icon?: IconName;
  onPress: () => void;
  disabled?: boolean;
  last?: boolean;
  trailing?: React.ReactNode;
}) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityHint={subtitle}
      accessibilityState={{ disabled }}
      style={({ pressed }) => [
        styles.row,
        !last && styles.rowDivider,
        pressed && !disabled && styles.rowPressed,
        disabled && styles.rowDisabled,
      ]}
    >
      {icon ? <Icon name={icon} size={20} color={disabled ? colors.inkMuted : colors.ink} /> : null}
      <View style={styles.rowText}>
        <Text style={text.subheading}>{label}</Text>
        {subtitle ? <Text style={[text.small, styles.rowSubtitle]}>{subtitle}</Text> : null}
      </View>
      {trailing ?? <Icon name="chevron-right" size={20} color={colors.border} />}
    </Pressable>
  );
}

/** Un réglage à deux états, avec ce qu'il change écrit sous son libellé. */
export function ToggleRow({
  label,
  hint,
  value,
  onValueChange,
}: {
  label: string;
  hint?: string;
  value: boolean;
  onValueChange: (value: boolean) => void;
}) {
  return (
    <View style={styles.toggleRow}>
      <View style={styles.rowText}>
        <Text style={text.subheading}>{label}</Text>
        {hint ? <Text style={[text.small, styles.rowSubtitle]}>{hint}</Text> : null}
      </View>
      <Switch
        value={value}
        onValueChange={onValueChange}
        accessibilityLabel={label}
        accessibilityHint={hint}
        thumbColor={colors.surface}
        trackColor={{ true: colors.ink, false: colors.borderStrong }}
      />
    </View>
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
      <Icon name="alert-outline" size={18} color={thermal.critical.line} />
      <Text style={styles.errorText}>{message}</Text>
    </View>
  );
}

/** Un écran vide est une invitation à agir, jamais le constat d'un manque. */
export function EmptyState({
  title,
  description,
  icon = 'inbox-outline',
  action,
}: {
  title: string;
  description: string;
  icon?: IconName;
  action?: React.ReactNode;
}) {
  return (
    <View style={styles.empty}>
      <View style={styles.emptyIcon} accessible accessibilityLabel={`${title}. ${description}`}>
        <Icon name={icon} size={22} color={colors.inkMuted} />
      </View>
      <Text style={[text.heading, styles.emptyTitle]}>{title}</Text>
      <Text style={[text.small, styles.emptyDescription]}>{description}</Text>
      {action ? <View style={styles.emptyAction}>{action}</View> : null}
    </View>
  );
}

/**
 * L'attente d'une liste, à sa place et à sa forme.
 *
 * Un `Loader` plein écran remplaçait tout — titre, recherche et filtres compris —
 * puis rendait la main d'un coup : la mise en page sautait entièrement à chaque
 * ouverture du stock. Le gabarit reprend celui d'une ligne de lot, rail compris,
 * pour que rien ne bouge quand les vraies données arrivent.
 *
 * Il ne respire pas. Une pulsation d'opacité sur cinq blocs gris est du bruit :
 * ce qu'on veut savoir, c'est où les choses vont se poser.
 */
export function Skeleton({ count = 4 }: { count?: number }) {
  return (
    <View accessible accessibilityRole="progressbar" accessibilityLabel="Chargement en cours">
      {Array.from({ length: count }, (unused, index) => (
        <View key={index} style={styles.skeletonRow}>
          <View style={styles.skeletonRail} />
          <View style={styles.skeletonBody}>
            <View style={[styles.skeletonBar, styles.skeletonTitle]} />
            <View style={[styles.skeletonBar, styles.skeletonMeta]} />
          </View>
          <View style={styles.skeletonStamp} />
        </View>
      ))}
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
      <ActivityIndicator color={colors.ink} />
      {label ? <Text style={[text.label, styles.loaderLabel]}>{label}</Text> : null}
    </View>
  );
}

const buttonVariants: Record<
  ButtonVariant,
  { container: ViewStyle; pressed: ViewStyle; text: { color: string } }
> = {
  primary: {
    container: { backgroundColor: colors.ink },
    pressed: { backgroundColor: '#28353D' },
    text: { color: colors.onInk },
  },
  secondary: {
    container: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
    pressed: { backgroundColor: colors.surfaceSunk },
    text: { color: colors.ink },
  },
  ghost: {
    container: { backgroundColor: 'transparent', paddingHorizontal: spacing.sm },
    pressed: { backgroundColor: colors.surfaceSunk },
    text: { color: colors.ink },
  },
  danger: {
    container: { backgroundColor: thermal.critical.wash },
    pressed: { backgroundColor: '#F3D5D2' },
    text: { color: thermal.critical.line },
  },
};

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
    borderWidth: 1,
    borderColor: colors.border,
    overflow: 'hidden',
  },
  cardRailed: { paddingLeft: spacing.lg + spacing.xs },
  rail: { position: 'absolute', left: 0, top: 0, bottom: 0, width: 3 },

  stamp: {
    paddingHorizontal: spacing.sm,
    paddingVertical: 4,
    borderRadius: radius.sm,
    alignSelf: 'flex-start',
  },
  stampHeroCaption: { marginTop: spacing.xs },

  badge: {
    paddingHorizontal: spacing.sm,
    paddingVertical: 4,
    borderRadius: radius.sm,
    alignSelf: 'flex-start',
  },
  badgeText: { fontFamily: font.sansMedium, fontSize: 12, lineHeight: 16 },

  button: {
    flexDirection: 'row',
    gap: spacing.sm,
    paddingVertical: 13,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.md,
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 48,
  },
  buttonDisabled: { opacity: 0.4 },

  iconButton: {
    width: 40,
    height: 40,
    borderRadius: radius.sm,
    alignItems: 'center',
    justifyContent: 'center',
  },
  iconButtonPressed: { backgroundColor: colors.surfaceSunk },

  chip: {
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderRadius: radius.sm,
    borderWidth: 1,
    marginRight: spacing.sm,
    marginBottom: spacing.sm,
    minHeight: 44,
    justifyContent: 'center',
  },
  chipIdle: { backgroundColor: colors.surface, borderColor: colors.border },
  chipPressed: { backgroundColor: colors.surfaceSunk },
  chipSelected: { backgroundColor: colors.ink, borderColor: colors.ink },
  chipText: { fontFamily: font.sans, fontSize: 14, lineHeight: 18, color: colors.ink },
  chipTextSelected: { fontFamily: font.sansMedium, color: colors.onInk },

  field: { marginBottom: spacing.lg },
  fieldLabel: { ...text.label, marginBottom: spacing.sm },
  fieldHint: { ...text.small, marginTop: spacing.xs },
  input: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
    minHeight: 48,
    fontFamily: font.sans,
    fontSize: 15,
    color: colors.ink,
  },
  inputRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },

  stepper: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  stepperButton: {
    width: 48,
    height: 48,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  stepperButtonPressed: { backgroundColor: colors.surfaceSunk },
  stepperButtonDisabled: { opacity: 0.4 },
  stepperValue: {
    minWidth: 64,
    height: 48,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    textAlign: 'center',
    fontFamily: font.monoMedium,
    fontSize: 17,
    color: colors.ink,
    paddingVertical: 0,
  },
  stepperUnit: { ...text.small, flexShrink: 1 },

  screenHeader: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.md,
    paddingTop: spacing.sm,
    paddingBottom: spacing.lg,
  },
  screenHeaderText: { flex: 1 },
  screenHeaderTitle: { marginTop: spacing.sm },
  screenHeaderDescription: { marginTop: spacing.sm, maxWidth: 320 },

  sectionTitle: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    marginTop: spacing.xl,
    marginBottom: spacing.md,
  },
  sectionTitleText: { color: colors.ink },
  sectionRule: { flex: 1, height: 1, backgroundColor: colors.border },

  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md + 2,
    minHeight: 56,
  },
  rowDivider: { borderBottomWidth: 1, borderBottomColor: colors.border },
  rowPressed: { backgroundColor: colors.surfaceSunk },
  rowDisabled: { opacity: 0.45 },
  rowText: { flex: 1 },
  rowSubtitle: { marginTop: 1 },

  toggleRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
    minHeight: 56,
  },

  errorBanner: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    backgroundColor: thermal.critical.wash,
    borderRadius: radius.md,
    padding: spacing.md,
    marginBottom: spacing.lg,
  },
  errorText: { ...text.small, flex: 1, color: thermal.critical.line },

  empty: { alignItems: 'center', paddingVertical: spacing.xxl, paddingHorizontal: spacing.lg },
  emptyIcon: {
    width: 48,
    height: 48,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  emptyTitle: { marginTop: spacing.lg, textAlign: 'center' },
  emptyDescription: { marginTop: spacing.sm, textAlign: 'center', maxWidth: 300 },
  emptyAction: { marginTop: spacing.lg, alignSelf: 'stretch', paddingHorizontal: spacing.xl },

  loader: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl, gap: spacing.md },
  loaderLabel: { textAlign: 'center' },

  skeletonRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    marginBottom: spacing.sm,
    paddingRight: spacing.lg,
    paddingVertical: spacing.lg,
    overflow: 'hidden',
  },
  skeletonRail: { width: 3, alignSelf: 'stretch', backgroundColor: colors.border },
  skeletonBody: { flex: 1, gap: spacing.sm, paddingLeft: spacing.xs },
  skeletonBar: { height: 12, borderRadius: radius.sm, backgroundColor: colors.surfaceSunk },
  skeletonTitle: { width: '62%' },
  skeletonMeta: { width: '38%', height: 10 },
  skeletonStamp: { width: 52, height: 22, borderRadius: radius.sm, backgroundColor: colors.surfaceSunk },
});
