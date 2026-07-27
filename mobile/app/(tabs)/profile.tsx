import { useRouter } from 'expo-router';
import React, { useEffect, useState } from 'react';
import { Alert, Pressable, ScrollView, Share, StyleSheet, Text, View } from 'react-native';

import { api } from '../../src/api/client';
import type { ExclusionSeverity, Reference } from '../../src/api/types';
import { ServerAddressField } from '../../src/components/ServerAddressField';
import { Button, Card, Chip, ErrorBanner, Field, Loader, SectionTitle } from '../../src/components/ui';
import { clearExpiryNotifications, ensureExpiryAlerts } from '../../src/notifications/expiry';
import { useSession } from '../../src/session/SessionContext';
import { colors, radius, spacing } from '../../src/theme';

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

  const [householdName, setHouseholdName] = useState(household?.name ?? '');
  const [exclusion, setExclusion] = useState('');
  const [newMember, setNewMember] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [alertStatus, setAlertStatus] = useState('Autorisation non vérifiée sur ce téléphone.');
  /** Code fraîchement régénéré : affiché immédiatement, avant tout rechargement. */
  const [issuedKey, setIssuedKey] = useState<string | null>(null);
  const [reference, setReference] = useState<Reference | null>(null);

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

  return (
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ErrorBanner message={error} />

      <Card>
        <Text style={styles.label}>Foyer</Text>
        <Text style={styles.name}>{household.name}</Text>
        <Text style={styles.label}>Vous êtes</Text>
        <Text style={styles.name}>{member?.firstName ?? 'personne'}</Text>
      </Card>

      <SectionTitle>Qui utilise l'application ?</SectionTitle>
      <Text style={styles.help}>
        Le prénom sélectionné signe les produits que vous ajoutez et consommez.
      </Text>

      <View style={styles.chips}>
        {household.members.map((entry) => (
          <Chip
            key={entry.id}
            label={entry.firstName}
            selected={member?.id === entry.id}
            onPress={() => selectMember(entry)}
          />
        ))}
      </View>

      {household.members.map((entry) => (
        <Pressable
          key={`remove-${entry.id}`}
          onPress={() => confirmRemoveMember(entry.id, entry.firstName)}
          accessibilityRole="button"
          accessibilityLabel={`Retirer ${entry.firstName} du foyer`}
          style={styles.removeRow}
        >
          <Text style={styles.removeText}>Retirer {entry.firstName} du foyer</Text>
        </Pressable>
      ))}

      <Field
        label="Ajouter une personne"
        value={newMember}
        onChangeText={setNewMember}
        placeholder="Prénom"
        returnKeyType="done"
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
        onPress={() => {
          if (!newMember.trim()) return;
          run(() => api.addMember(household.id, newMember.trim()));
          setNewMember('');
        }}
      />

      <SectionTitle>Nom du foyer</SectionTitle>
      <Field label="Nom" value={householdName} onChangeText={setHouseholdName} />
      <Button
        label="Enregistrer"
        variant="secondary"
        loading={busy}
        onPress={() => run(() => api.updateHousehold(household.id, { name: householdName }))}
      />

      <SectionTitle>Exclusions alimentaires</SectionTitle>
      <Text style={styles.help}>
        Ces aliments sont retirés de l'inventaire envoyé au générateur, et toute recette
        qui les nomme — jusque dans ses étapes — est écartée avant de vous être montrée.
        Le contrôle est automatique : il ne dépend pas de la bonne volonté du modèle.
      </Text>
      <Text style={styles.help}>
        Pour une allergie stricte, un produit dont la fiche ne précise pas les allergènes
        est en plus signalé « sécurité non vérifiable » plutôt que présenté comme sûr — un
        régime ou une simple préférence ne déclenchent pas ce contrôle-là.
      </Text>

      {/* Choisir « Arachides » dans cette liste couvre aussi « beurre de cacahuète »
          et « satay ». Un texte libre ne cherche que le mot écrit. */}
      <Text style={styles.help}>Allergènes reconnus, avec tous leurs dérivés :</Text>
      <View style={styles.chips}>
        {(reference?.allergens ?? []).map((allergen) => {
          const active = household.dietaryExclusions.some((entry) => entry.label === allergen.label);

          return (
            <Chip
              key={allergen.value}
              label={allergen.label}
              selected={active}
              onPress={() =>
                run(() =>
                  api.updateHousehold(household.id, {
                    dietaryExclusions: active
                      ? household.dietaryExclusions.filter((entry) => entry.label !== allergen.label)
                      : [
                          ...household.dietaryExclusions,
                          { label: allergen.label, severity: allergen.defaultSeverity },
                        ],
                  }),
                )
              }
            />
          );
        })}
      </View>

      <Text style={styles.help}>
        Exclusions actives — toucher une exclusion change son type :
      </Text>
      <View style={styles.chips}>
        {household.dietaryExclusions.map((entry) => (
          <Chip
            key={entry.label}
            label={`${entry.label} · ${severityLabel(entry.severity)}`}
            selected
            onPress={() =>
              pickSeverity(entry.label, (severity) =>
                run(() =>
                  api.updateHousehold(household.id, {
                    dietaryExclusions: household.dietaryExclusions.map((e) =>
                      e.label === entry.label ? { ...e, severity } : e,
                    ),
                  }),
                ),
              )
            }
          />
        ))}
        {household.dietaryExclusions.length === 0 ? (
          <Text style={styles.help}>Aucune exclusion enregistrée.</Text>
        ) : null}
      </View>

      {household.dietaryExclusions.map((entry) => (
        <Pressable
          key={`remove-excl-${entry.label}`}
          onPress={() =>
            run(() =>
              api.updateHousehold(household.id, {
                dietaryExclusions: household.dietaryExclusions.filter((e) => e.label !== entry.label),
              }),
            )
          }
          accessibilityRole="button"
          accessibilityLabel={`Retirer ${entry.label} de mes exclusions`}
          style={styles.removeRow}
        >
          <Text style={styles.removeText}>Retirer {entry.label} de mes exclusions</Text>
        </Pressable>
      ))}

      <Field
        label="Ajouter une autre exclusion"
        value={exclusion}
        onChangeText={setExclusion}
        placeholder="brocoli, coriandre…"
        hint="Un terme libre est cherché tel quel, en allergie stricte par défaut : ajustez son type ensuite si besoin."
        returnKeyType="done"
        onSubmitEditing={() => {
          if (!exclusion.trim()) return;
          run(() =>
            api.updateHousehold(household.id, {
              dietaryExclusions: [
                ...household.dietaryExclusions,
                { label: exclusion.trim(), severity: 'allergy' },
              ],
            }),
          );
          setExclusion('');
        }}
      />

      <SectionTitle>Code du foyer</SectionTitle>
      <Text style={styles.help}>
        C'est ce code, et lui seul, qui donne accès au stock de ce foyer. Transmettez-le
        aux personnes qui y vivent, à personne d'autre.
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
            onPress={() =>
              Share.share({
                message: `Rejoins notre foyer « ${household!.name} » dans l'application Food avec ce code : ${issuedKey ?? accessKey}`,
              })
            }
          />
          <Button
            label="Régénérer le code"
            variant="ghost"
            onPress={confirmRotateKey}
            style={styles.spaced}
          />
        </>
      ) : (
        <Text style={styles.help}>Aucun code enregistré sur ce téléphone.</Text>
      )}

      <SectionTitle>Alertes de péremption</SectionTitle>
      <Text style={styles.help}>
        Trois jours avant la date limite, à 9 h, ce téléphone vous prévient de ce qu'il faut
        consommer. Les alertes sont programmées sur l'appareil : chaque membre du foyer
        autorise les siennes.
      </Text>
      <Text style={styles.help}>{alertStatus}</Text>
      <Button
        label="Vérifier l'autorisation"
        variant="secondary"
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

      <SectionTitle>Changer de foyer</SectionTitle>
      <Text style={styles.help}>
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
  label: {
    fontSize: 12,
    fontWeight: '600',
    color: colors.inkMuted,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    marginTop: spacing.sm,
  },
  name: { fontSize: 20, fontWeight: '800', color: colors.ink },
  help: { fontSize: 13, color: colors.inkMuted, lineHeight: 19, marginBottom: spacing.md },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  removeRow: { paddingVertical: spacing.xs },
  removeText: { fontSize: 13, color: colors.danger },
  spaced: { marginTop: spacing.sm },
  codeBox: {
    backgroundColor: colors.primarySoft,
    borderRadius: radius.lg,
    paddingVertical: spacing.lg,
    paddingHorizontal: spacing.md,
    marginBottom: spacing.md,
    alignItems: 'center',
  },
  codeText: {
    fontSize: 18,
    fontWeight: '800',
    color: colors.primary,
    letterSpacing: 1,
    textAlign: 'center',
  },
});
