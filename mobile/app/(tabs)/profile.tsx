import { useRouter } from 'expo-router';
import React, { useEffect, useRef, useState } from 'react';
import { Alert, ScrollView, Share, StyleSheet, Text, View } from 'react-native';

import { ApiError, api, generateOperationKey } from '../../src/api/client';
import type { ExclusionSeverity, Reference, ResetScope } from '../../src/api/types';
import { ServerAddressField } from '../../src/components/ServerAddressField';
import {
  Button,
  Card,
  Chip,
  ErrorBanner,
  Field,
  IconButton,
  Loader,
  Row,
  ScreenHeader,
  SectionTitle,
  useTopInset,
} from '../../src/components/ui';
import { clearExpiryNotifications, ensureExpiryAlerts } from '../../src/notifications/expiry';
import { resyncExpiryAlerts } from '../../src/notifications/useExpiryAlerts';
import { useRecipes } from '../../src/recipes/RecipeContext';
import { useSession } from '../../src/session/SessionContext';
import { colors, font, radius, spacing, text } from '../../src/theme';

/**
 * Toutes les formes dont les phrases d'une remise à zéro ont besoin, par portée.
 * Écrites à la main plutôt que composées : « le réfrigérateur », « du
 * réfrigérateur » dépendent du genre et de l'initiale du mot. Le type exige une
 * entrée par emplacement — en ajouter un sans ses phrases ne compile pas.
 * L'ordre des clés est celui des puces : le cas général d'abord.
 */
const RESET_SCOPES: Record<
  ResetScope,
  { chip: string; the: string; The: string; of: string; where: string; done: string }
> = {
  all: { chip: 'Tout le stock', the: 'le stock', The: 'Le stock', of: 'en stock', where: 'chez vous', done: 'Stock vidé' },
  pantry: { chip: 'Placard', the: 'le placard', The: 'Le placard', of: 'du placard', where: 'dans le placard', done: 'Placard vidé' },
  fridge: {
    chip: 'Réfrigérateur',
    the: 'le réfrigérateur',
    The: 'Le réfrigérateur',
    of: 'du réfrigérateur',
    where: 'dans le réfrigérateur',
    done: 'Réfrigérateur vidé',
  },
  freezer: {
    chip: 'Congélateur',
    the: 'le congélateur',
    The: 'Le congélateur',
    of: 'du congélateur',
    where: 'dans le congélateur',
    done: 'Congélateur vidé',
  },
};

function severityLabel(severity: ExclusionSeverity): string {
  return severity === 'allergy' ? 'Allergie stricte' : severity === 'diet' ? 'Régime' : 'Préférence';
}

/**
 * Demande la sévérité plutôt que la faire deviner par un tapotement répété :
 * reclasser une allergie en simple préférence mérite un choix explicite, pas
 * un cycle silencieux qu'un doigt maladroit pourrait déclencher.
 */
function pickSeverity(label: string, onPick: (severity: ExclusionSeverity) => void): void {
  Alert.alert(
    `Type d'exclusion pour « ${label} »`,
    'Une allergie stricte signale « sécurité non vérifiable » quand un produit n\'a pas de fiche allergènes claire, plutôt que de le présenter comme sûr.',
    [
      { text: 'Allergie stricte', onPress: () => onPick('allergy') },
      { text: 'Régime', onPress: () => onPick('diet') },
      { text: 'Préférence', onPress: () => onPick('preference') },
      { text: 'Annuler', style: 'cancel' },
    ],
  );
}

export default function ProfileScreen() {
  const router = useRouter();
  const { household, member, accessKey, updateAccessKey, selectMember, refresh, leave } =
    useSession();
  const topInset = useTopInset();

  const [householdName, setHouseholdName] = useState(household?.name ?? '');
  const [exclusion, setExclusion] = useState('');
  const [newMember, setNewMember] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [alertStatus, setAlertStatus] = useState('Autorisation non vérifiée sur ce téléphone.');
  /** Code fraîchement régénéré : affiché immédiatement, avant tout rechargement. */
  const [issuedKey, setIssuedKey] = useState<string | null>(null);
  const [reference, setReference] = useState<Reference | null>(null);
  const { setSuggestion } = useRecipes();
  const [resetting, setResetting] = useState(false);
  const [resetScope, setResetScope] = useState<ResetScope>('all');
  /**
   * Gardée seulement après une panne réseau, et pour la même portée : le geste
   * se rejoue sans se refaire. Pour une autre portée, c'est un autre geste.
   */
  const resetKey = useRef<{ key: string; scope: ResetScope } | null>(null);

  // La liste des allergènes reconnus vient du serveur : elle et la table de
  // détection ne doivent jamais diverger.
  useEffect(() => {
    let cancelled = false;

    api
      .reference()
      .then((value) => {
        if (!cancelled) setReference(value);
      })
      .catch(() => {
        // Sans la liste, la saisie libre reste possible : ce n'est pas bloquant.
      });

    return () => {
      cancelled = true;
    };
  }, []);

  if (!household) return <Loader />;

  async function run(action: () => Promise<unknown>) {
    setError(null);
    setBusy(true);
    try {
      await action();
      await refresh();
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Enregistrement impossible.');
    } finally {
      setBusy(false);
    }
  }

  /** Régénérer déconnecte tous les autres téléphones : cela se confirme. */
  function confirmRotateKey() {
    Alert.alert(
      'Régénérer le code du foyer ?',
      'L\'ancien code cessera immédiatement de fonctionner. Chaque autre téléphone devra saisir le nouveau. Le stock, lui, n\'est pas touché.',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Régénérer',
          style: 'destructive',
          onPress: () =>
            run(async () => {
              const updated = await api.rotateHouseholdKey(household!.id);
              await updateAccessKey(updated.accessKey);
              setIssuedKey(updated.accessKey);
            }),
        },
      ],
    );
  }

  function confirmRemoveMember(id: string, firstName: string) {
    Alert.alert(
      `Retirer ${firstName} du foyer ?`,
      'Les produits déjà enregistrés restent en stock ; seule l\'attribution disparaît.',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Retirer',
          style: 'destructive',
          onPress: () =>
            run(async () => {
              await api.removeMember(household!.id, id);
              if (member?.id === id) await selectMember(null);
            }),
        },
      ],
    );
  }

  /**
   * Refaire l'inventaire. On compte d'abord : la confirmation dit combien de
   * lots partent, et un stock déjà vide n'a rien à confirmer. Les erreurs
   * passent par une alerte — la bannière est tout en haut d'un long écran,
   * loin de ce bouton.
   */
  async function confirmArchiveAll() {
    // La portée est figée à l'ouverture : la confirmation parle de celle-là, et
    // le geste ne vide rien d'autre, même si une puce change entre-temps.
    const scope = resetScope;
    const words = RESET_SCOPES[scope];
    setResetting(true);

    try {
      const { items } = await api.stockItems('all' === scope ? {} : { location: scope });

      if (0 === items.length) {
        Alert.alert(
          `${words.The} est déjà vide`,
          `Il n'y a aucun lot à ranger. Ajoutez directement ce qui se trouve ${words.where}.`,
        );
        return;
      }

      const subject =
        1 === items.length ? `Le seul lot ${words.of} passe` : `Les ${items.length} lots ${words.of} passent`;
      const rest = 'all' === scope ? '' : ' Le reste du stock n\'est pas touché.';

      Alert.alert(
        `Vider ${words.the} ?`,
        `${subject} aux archives, pour tout le foyer.${rest} Vous ajouterez ensuite ce qui se trouve réellement ${words.where}.\n\nRien n'est effacé : chaque lot reste dans « Archives », d'où il se restaure un par un, et l'historique de consommation continue de servir aux suggestions de courses.`,
        [
          { text: 'Annuler', style: 'cancel' },
          { text: `Vider ${words.the}`, style: 'destructive', onPress: () => archiveAll(scope) },
        ],
      );
    } catch (exception) {
      Alert.alert('Stock injoignable', exception instanceof Error ? exception.message : 'Lecture du stock impossible.');
    } finally {
      setResetting(false);
    }
  }

  async function archiveAll(scope: ResetScope) {
    const words = RESET_SCOPES[scope];
    const pending =
      resetKey.current?.scope === scope ? resetKey.current : { key: generateOperationKey(), scope };
    resetKey.current = pending;
    setResetting(true);

    try {
      const { archivedCount } = await api.archiveAllStockItems(pending.key, scope);
      resetKey.current = null;

      // La dernière suggestion de recettes puisait dans des lots qui viennent d'être rangés.
      setSuggestion(null);
      await resyncExpiryAlerts({ fresh: true });

      Alert.alert(
        words.done,
        `${archivedCount} ${archivedCount > 1 ? 'lots rangés' : 'lot rangé'} dans les archives. Ajoutez maintenant ce qui se trouve réellement ${words.where}.`,
        [
          { text: 'Plus tard', style: 'cancel' },
          { text: 'Ajouter un aliment', onPress: () => router.push('/add') },
        ],
      );
    } catch (exception) {
      // Panne réseau : le serveur a peut-être agi. La même clé rejouera sa
      // réponse au lieu de refaire le geste.
      if (!(exception instanceof ApiError) || exception.status !== 0) resetKey.current = null;
      Alert.alert(
        `${words.The} n'a pas été vidé`,
        exception instanceof Error ? exception.message : 'Action impossible.',
      );
    } finally {
      setResetting(false);
    }
  }

  function setExclusions(next: Array<{ label: string; severity: ExclusionSeverity }>) {
    return run(() => api.updateHousehold(household!.id, { dietaryExclusions: next }));
  }

  return (
    <ScrollView style={topInset} contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ScreenHeader
        eyebrow={`${household.members.length} membre${household.members.length > 1 ? 's' : ''}`}
        title={household.name}
        description={
          member
            ? `Vos ajouts et vos consommations sont signés « ${member.firstName} ».`
            : 'Aucun prénom sélectionné : vos ajouts ne sont signés de personne.'
        }
      />

      <ErrorBanner message={error} />

      <SectionTitle>Qui utilise l'application</SectionTitle>
      <Card style={styles.rowCard}>
        {household.members.map((entry, index) => (
          <Row
            key={entry.id}
            icon={member?.id === entry.id ? 'check' : 'account-outline'}
            label={entry.firstName}
            subtitle={member?.id === entry.id ? 'Signe ce que vous enregistrez' : undefined}
            onPress={() => selectMember(entry)}
            last={index === household.members.length - 1}
            trailing={
              <IconButton
                icon="close"
                label={`Retirer ${entry.firstName} du foyer`}
                tone="critical"
                onPress={() => confirmRemoveMember(entry.id, entry.firstName)}
              />
            }
          />
        ))}
      </Card>

      <View style={styles.inlineForm}>
        <Field
          label="Ajouter une personne"
          value={newMember}
          onChangeText={setNewMember}
          placeholder="Prénom"
          returnKeyType="done"
          containerStyle={styles.inlineField}
          onSubmitEditing={() => {
            if (newMember.trim()) {
              run(() => api.addMember(household.id, newMember.trim()));
              setNewMember('');
            }
          }}
        />
        <Button
          label="Ajouter"
          variant="secondary"
          loading={busy}
          disabled={!newMember.trim()}
          onPress={() => {
            if (!newMember.trim()) return;
            run(() => api.addMember(household.id, newMember.trim()));
            setNewMember('');
          }}
          style={styles.inlineButton}
        />
      </View>

      <SectionTitle>Nom du foyer</SectionTitle>
      <Field label="Nom du foyer" hideLabel value={householdName} onChangeText={setHouseholdName} />
      <Button
        label="Enregistrer le nom"
        variant="secondary"
        loading={busy}
        onPress={() => run(() => api.updateHousehold(household.id, { name: householdName }))}
      />

      <SectionTitle>Exclusions alimentaires</SectionTitle>
      <Text style={[text.small, styles.help]}>
        Ces aliments sont retirés de l'inventaire envoyé au générateur, et toute recette qui les
        nomme — jusque dans ses étapes — est écartée avant de vous être montrée. Le contrôle est
        automatique : il ne dépend pas de la bonne volonté du modèle.
      </Text>

      {/* Choisir « Arachides » dans cette liste couvre aussi « beurre de cacahuète »
          et « satay ». Un texte libre ne cherche que le mot écrit. */}
      <Text style={[text.label, styles.groupLabel]}>Allergènes reconnus, dérivés compris</Text>
      <View style={styles.chips}>
        {(reference?.allergens ?? []).map((allergen) => {
          const active = household.dietaryExclusions.some((entry) => entry.label === allergen.label);

          return (
            <Chip
              key={allergen.value}
              label={allergen.label}
              selected={active}
              onPress={() =>
                setExclusions(
                  active
                    ? household.dietaryExclusions.filter((entry) => entry.label !== allergen.label)
                    : [
                        ...household.dietaryExclusions,
                        { label: allergen.label, severity: allergen.defaultSeverity },
                      ],
                )
              }
            />
          );
        })}
      </View>

      <Text style={[text.label, styles.groupLabel]}>Exclusions actives</Text>
      {household.dietaryExclusions.length > 0 ? (
        <Card style={styles.rowCard}>
          {household.dietaryExclusions.map((entry, index) => (
            <Row
              key={entry.label}
              label={entry.label}
              subtitle={`${severityLabel(entry.severity)} · toucher pour changer`}
              onPress={() =>
                pickSeverity(entry.label, (severity) =>
                  setExclusions(
                    household.dietaryExclusions.map((current) =>
                      current.label === entry.label ? { ...current, severity } : current,
                    ),
                  ),
                )
              }
              last={index === household.dietaryExclusions.length - 1}
              trailing={
                <IconButton
                  icon="close"
                  label={`Retirer ${entry.label} de mes exclusions`}
                  tone="critical"
                  onPress={() =>
                    setExclusions(
                      household.dietaryExclusions.filter((current) => current.label !== entry.label),
                    )
                  }
                />
              }
            />
          ))}
        </Card>
      ) : (
        <Text style={[text.small, styles.help]}>
          Aucune exclusion enregistrée. Une allergie stricte déclenche en plus le contrôle
          « sécurité non vérifiable » sur les produits dont la composition est inconnue.
        </Text>
      )}

      <View style={styles.spaced}>
        <Field
          label="Ajouter une autre exclusion"
          value={exclusion}
          onChangeText={setExclusion}
          placeholder="brocoli, coriandre…"
          hint="Un terme libre est cherché tel quel, en allergie stricte par défaut. Ajustez son type ensuite."
          returnKeyType="done"
          onSubmitEditing={() => {
            if (!exclusion.trim()) return;
            setExclusions([
              ...household.dietaryExclusions,
              { label: exclusion.trim(), severity: 'allergy' },
            ]);
            setExclusion('');
          }}
        />
      </View>

      <SectionTitle>Code du foyer</SectionTitle>
      <Text style={[text.small, styles.help]}>
        Ce code, et lui seul, donne accès au stock du foyer. Transmettez-le aux personnes qui y
        vivent, à personne d'autre.
      </Text>

      {accessKey ? (
        <>
          <View style={styles.codeBox}>
            <Text selectable numberOfLines={1} adjustsFontSizeToFit style={styles.codeText}>
              {issuedKey ?? accessKey}
            </Text>
          </View>
          <Button
            label="Partager le code"
            variant="secondary"
            icon="share-variant-outline"
            onPress={() =>
              Share.share({
                message: `Rejoins notre foyer « ${household!.name} » dans l'application Food avec ce code : ${issuedKey ?? accessKey}`,
              })
            }
          />
          <Button label="Régénérer le code" variant="ghost" onPress={confirmRotateKey} style={styles.spaced} />
        </>
      ) : (
        <Text style={[text.small, styles.help]}>Aucun code enregistré sur ce téléphone.</Text>
      )}

      <SectionTitle>Alertes de péremption</SectionTitle>
      <Text style={[text.small, styles.help]}>
        Trois jours avant la date limite, à 9 h, ce téléphone vous prévient de ce qu'il faut
        consommer. Les alertes sont programmées sur l'appareil : chaque membre autorise les siennes.
      </Text>
      <Card style={styles.statusCard}>
        {/* Une phrase, donc la voix humaine : le monospace ne porte jamais de
            phrase, seulement des chiffres et des rubriques. */}
        <Text style={text.body}>{alertStatus}</Text>
      </Card>
      <Button
        label="Vérifier l'autorisation"
        variant="secondary"
        icon="bell-ring-outline"
        onPress={async () => {
          const granted = await ensureExpiryAlerts();
          setAlertStatus(
            granted
              ? 'Alertes autorisées sur ce téléphone.'
              : 'Alertes refusées. Autorisez les notifications de Food dans les réglages Android.',
          );
        }}
      />

      <SectionTitle>Serveur</SectionTitle>
      <ServerAddressField />

      <SectionTitle>Refaire l'inventaire</SectionTitle>
      <Text style={[text.small, styles.help]}>
        {member
          ? 'Après une absence, le stock enregistré ne ressemble plus à ce qu\'il y a chez vous ? Videz-le d\'un geste — en entier, ou un seul emplacement —, puis ajoutez ce qui s\'y trouve réellement.'
          : 'Choisissez d\'abord votre prénom, plus haut : vider le stock est un geste signé.'}
      </Text>
      <View style={styles.chips}>
        {(Object.keys(RESET_SCOPES) as ResetScope[]).map((scope) => (
          <Chip
            key={scope}
            label={RESET_SCOPES[scope].chip}
            selected={resetScope === scope}
            onPress={() => setResetScope(scope)}
          />
        ))}
      </View>
      <Button
        label={`Vider ${RESET_SCOPES[resetScope].the}`}
        variant="danger"
        hint={`Range tous les lots ${RESET_SCOPES[resetScope].of} dans les archives, après confirmation`}
        loading={resetting}
        disabled={busy || !member}
        onPress={confirmArchiveAll}
      />

      <SectionTitle>Changer de foyer</SectionTitle>
      <Text style={[text.small, styles.help]}>
        Le stock de chaque foyer reste strictement séparé. Changer de foyer ne supprime rien.
      </Text>
      <Button
        label="Choisir un autre foyer"
        variant="danger"
        onPress={async () => {
          // Les alertes portent sur le stock du foyer quitté : elles n'ont plus lieu d'être.
          await clearExpiryNotifications();
          await leave();
          router.replace('/setup');
        }}
      />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2 },
  rowCard: { padding: 0 },
  help: { marginBottom: spacing.md },
  groupLabel: { marginTop: spacing.lg, marginBottom: spacing.sm },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  inlineForm: { flexDirection: 'row', alignItems: 'flex-end', gap: spacing.sm, marginTop: spacing.lg },
  inlineField: { flex: 1, marginBottom: 0 },
  inlineButton: { minWidth: 104 },
  spaced: { marginTop: spacing.md },
  statusCard: { paddingVertical: spacing.md, marginBottom: spacing.md },

  /**
   * Le code est une donnée machine : il se lit caractère par caractère, sans
   * ambiguïté entre un zéro et un O. C'est exactement ce que la voix monospacée
   * est là pour faire.
   */
  codeBox: {
    backgroundColor: colors.surfaceSunk,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingVertical: spacing.lg,
    paddingHorizontal: spacing.md,
    marginBottom: spacing.md,
    alignItems: 'center',
  },
  codeText: {
    fontFamily: font.monoSemiBold,
    fontSize: 16,
    color: colors.ink,
    letterSpacing: 1,
    textAlign: 'center',
  },
});
