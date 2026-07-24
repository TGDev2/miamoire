import { useRouter } from 'expo-router';
import React, { useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';

import { api } from '../../src/api/client';
import { useAuth, useToken } from '../../src/auth/AuthContext';
import { ServerAddressField } from '../../src/components/ServerAddressField';
import {
  Button,
  Card,
  Chip,
  ErrorBanner,
  Field,
  Loader,
  SectionTitle,
} from '../../src/components/ui';
import { colors, spacing } from '../../src/theme';

export default function ProfileScreen() {
  const token = useToken();
  const router = useRouter();
  const { user, signOut, refreshUser } = useAuth();

  const [householdName, setHouseholdName] = useState(user?.household.name ?? '');
  const [exclusion, setExclusion] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (!user) return <Loader />;

  async function save(body: Record<string, unknown>) {
    setError(null);
    setBusy(true);
    try {
      await api.updateProfile(token, body);
      await refreshUser();
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Enregistrement impossible.');
    } finally {
      setBusy(false);
    }
  }

  function addExclusion() {
    const value = exclusion.trim();
    if (!value) return;

    setExclusion('');
    save({ dietaryExclusions: [...user!.dietaryExclusions, value] });
  }

  return (
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ErrorBanner message={error} />

      <Card>
        <Text style={styles.name}>{user.name}</Text>
        <Text style={styles.email}>{user.email}</Text>
      </Card>

      <SectionTitle>Foyer</SectionTitle>
      <Field label="Nom du foyer" value={householdName} onChangeText={setHouseholdName} />
      <Button
        label="Enregistrer"
        variant="secondary"
        onPress={() => save({ householdName })}
        loading={busy}
      />

      <Text style={styles.members}>
        {user.household.members.length} membre(s) :{' '}
        {user.household.members.map((member) => member.name).join(', ')}
      </Text>

      <SectionTitle>Exclusions alimentaires</SectionTitle>
      <Text style={styles.help}>
        Ces aliments ne seront jamais proposés dans les recettes générées.
      </Text>

      <View style={styles.chips}>
        {user.dietaryExclusions.map((entry) => (
          <Chip
            key={entry}
            label={`${entry}  ✕`}
            selected
            onPress={() =>
              save({
                dietaryExclusions: user.dietaryExclusions.filter((value) => value !== entry),
              })
            }
          />
        ))}
        {user.dietaryExclusions.length === 0 ? (
          <Text style={styles.help}>Aucune exclusion enregistrée.</Text>
        ) : null}
      </View>

      <Field
        label="Ajouter une exclusion"
        value={exclusion}
        onChangeText={setExclusion}
        placeholder="arachide, lactose, porc…"
        onSubmitEditing={addExclusion}
        returnKeyType="done"
      />
      <Button label="Ajouter" variant="secondary" onPress={addExclusion} loading={busy} />

      <SectionTitle>Emplacements</SectionTitle>
      <Card>
        <Text style={styles.help}>
          Placard, réfrigérateur et congélateur sont disponibles à l'ajout de chaque aliment.
        </Text>
      </Card>

      <SectionTitle>Serveur</SectionTitle>
      <ServerAddressField />

      <SectionTitle>Session</SectionTitle>
      <Button
        label="Se déconnecter"
        variant="danger"
        onPress={async () => {
          await signOut();
          router.replace('/login');
        }}
      />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2 },
  name: { fontSize: 20, fontWeight: '800', color: colors.ink },
  email: { fontSize: 14, color: colors.inkMuted, marginTop: 2 },
  members: { fontSize: 13, color: colors.inkMuted, marginTop: spacing.md },
  help: { fontSize: 13, color: colors.inkMuted, lineHeight: 19, marginBottom: spacing.md },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  server: {
    marginTop: spacing.xxl,
    fontSize: 11,
    color: colors.inkMuted,
    textAlign: 'center',
  },
});
