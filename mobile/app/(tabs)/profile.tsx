import { useRouter } from 'expo-router';
import React, { useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { api } from '../../src/api/client';
import { ServerAddressField } from '../../src/components/ServerAddressField';
import { Button, Card, Chip, ErrorBanner, Field, Loader, SectionTitle } from '../../src/components/ui';
import { clearExpiryNotifications, ensureExpiryAlerts } from '../../src/notifications/expiry';
import { useSession } from '../../src/session/SessionContext';
import { colors, radius, spacing } from '../../src/theme';

export default function ProfileScreen() {
  const router = useRouter();
  const { household, member, selectMember, refresh, leave } = useSession();

  const [householdName, setHouseholdName] = useState(household?.name ?? '');
  const [exclusion, setExclusion] = useState('');
  const [newMember, setNewMember] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [alertStatus, setAlertStatus] = useState('Autorisation non vérifiée sur ce téléphone.');

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
        Ces aliments ne seront jamais proposés dans les recettes générées pour ce foyer.
      </Text>

      <View style={styles.chips}>
        {household.dietaryExclusions.map((entry) => (
          <Chip
            key={entry}
            label={`${entry}  ✕`}
            selected
            onPress={() =>
              run(() =>
                api.updateHousehold(household.id, {
                  dietaryExclusions: household.dietaryExclusions.filter((v) => v !== entry),
                }),
              )
            }
          />
        ))}
        {household.dietaryExclusions.length === 0 ? (
          <Text style={styles.help}>Aucune exclusion enregistrée.</Text>
        ) : null}
      </View>

      <Field
        label="Ajouter une exclusion"
        value={exclusion}
        onChangeText={setExclusion}
        placeholder="arachide, lactose, porc…"
        returnKeyType="done"
        onSubmitEditing={() => {
          if (!exclusion.trim()) return;
          run(() =>
            api.updateHousehold(household.id, {
              dietaryExclusions: [...household.dietaryExclusions, exclusion.trim()],
            }),
          );
          setExclusion('');
        }}
      />

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
});
