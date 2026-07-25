import { useRouter } from 'expo-router';
import React, { useCallback, useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError, api } from '../src/api/client';
import type { Household } from '../src/api/types';
import { ServerAddressField } from '../src/components/ServerAddressField';
import { Button, Card, ErrorBanner, Field, Loader, SectionTitle } from '../src/components/ui';
import { useSession } from '../src/session/SessionContext';
import { colors, radius, spacing } from '../src/theme';

/**
 * Mise en route en deux temps : choisir le foyer, puis dire qui l'on est.
 * Aucun mot de passe — le prénom sert uniquement à signer les mouvements de stock.
 */
export default function SetupScreen() {
  const router = useRouter();
  const { household, member, selectHousehold, selectMember, refresh } = useSession();

  const [households, setHouseholds] = useState<Household[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showServer, setShowServer] = useState(false);

  const [newHousehold, setNewHousehold] = useState('');
  const [newHouseholdOwner, setNewHouseholdOwner] = useState('');
  const [newMember, setNewMember] = useState('');
  const [creatingHousehold, setCreatingHousehold] = useState(false);

  const load = useCallback(async () => {
    setError(null);
    try {
      const { households: list } = await api.households();
      setHouseholds(list);
      setCreatingHousehold(list.length === 0);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
      if (exception instanceof ApiError && exception.status === 0) setShowServer(true);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!household) load();
    else setLoading(false);
  }, [household, load]);

  // Dès qu'un foyer et une personne sont choisis, on entre dans l'application.
  useEffect(() => {
    if (household && member) router.replace('/(tabs)');
  }, [household, member, router]);

  async function createHousehold() {
    if (!newHousehold.trim() || !newHouseholdOwner.trim()) {
      setError('Indiquez le nom du foyer et votre prénom.');
      return;
    }

    setBusy(true);
    setError(null);
    try {
      const created = await api.createHousehold(newHousehold.trim(), newHouseholdOwner.trim());
      await selectHousehold(created);
      if (created.members[0]) await selectMember(created.members[0]);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Création impossible.');
    } finally {
      setBusy(false);
    }
  }

  async function addMember() {
    if (!household || !newMember.trim()) {
      setError('Indiquez votre prénom.');
      return;
    }

    setBusy(true);
    setError(null);
    try {
      const created = await api.addMember(household.id, newMember.trim());
      setNewMember('');
      await selectMember(created);
      await refresh();
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Ajout impossible.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <Loader label="Recherche des foyers…" />;

  return (
    <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
        <Text style={styles.logo}>Food</Text>
        <Text style={styles.tagline}>
          Ce que vous avez, ce qui va périmer, ce que vous pouvez cuisiner.
        </Text>

        <ErrorBanner message={error} />

        {!household ? (
          <>
            <SectionTitle>Votre foyer</SectionTitle>
            <Text style={styles.help}>
              Chaque foyer a son propre stock. Rien n'est partagé entre deux foyers.
            </Text>

            {!creatingHousehold ? (
              <>
                {households.map((entry) => (
                  <Pressable
                    key={entry.id}
                    onPress={() => selectHousehold(entry)}
                    style={({ pressed }) => [styles.choice, pressed && styles.pressed]}
                  >
                    <Text style={styles.choiceName}>{entry.name}</Text>
                    <Text style={styles.choiceMeta}>
                      {entry.members.length > 0
                        ? entry.members.map((m) => m.firstName).join(', ')
                        : 'Aucun membre pour l\'instant'}
                    </Text>
                  </Pressable>
                ))}

                <Button
                  label="Créer un nouveau foyer"
                  variant="secondary"
                  onPress={() => setCreatingHousehold(true)}
                  style={styles.spaced}
                />
              </>
            ) : (
              <Card>
                <Field
                  label="Nom du foyer"
                  value={newHousehold}
                  onChangeText={setNewHousehold}
                  placeholder="Maison, Appartement, Chalet…"
                />
                <Field
                  label="Votre prénom"
                  value={newHouseholdOwner}
                  onChangeText={setNewHouseholdOwner}
                  placeholder="Théo"
                />
                <Button label="Créer ce foyer" onPress={createHousehold} loading={busy} />
                {households.length > 0 ? (
                  <Button
                    label="Revenir à la liste"
                    variant="ghost"
                    onPress={() => setCreatingHousehold(false)}
                    style={styles.spaced}
                  />
                ) : null}
              </Card>
            )}
          </>
        ) : (
          <>
            <SectionTitle>Qui êtes-vous ?</SectionTitle>
            <Text style={styles.help}>
              Foyer « {household.name} ». Votre prénom sert à savoir qui a enregistré
              ou consommé un produit.
            </Text>

            {household.members.map((entry) => (
              <Pressable
                key={entry.id}
                onPress={() => selectMember(entry)}
                style={({ pressed }) => [styles.choice, pressed && styles.pressed]}
              >
                <Text style={styles.choiceName}>{entry.firstName}</Text>
              </Pressable>
            ))}

            <Card style={styles.spaced}>
              <Field
                label="Ajouter quelqu'un"
                value={newMember}
                onChangeText={setNewMember}
                placeholder="Prénom"
                onSubmitEditing={addMember}
                returnKeyType="done"
              />
              <Button label="Ajouter et continuer" onPress={addMember} loading={busy} />
            </Card>

            <Button
              label="Changer de foyer"
              variant="ghost"
              onPress={load}
              style={styles.spaced}
            />
          </>
        )}

        {showServer ? (
          <View style={styles.serverBlock}>
            <ServerAddressField />
          </View>
        ) : (
          <Text style={styles.server} onPress={() => setShowServer(true)}>
            Configurer l'adresse du serveur
          </Text>
        )}
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, backgroundColor: colors.background },
  container: { padding: spacing.xl, paddingTop: spacing.xxl * 2, paddingBottom: spacing.xxl },
  logo: { fontSize: 40, fontWeight: '800', color: colors.primary, letterSpacing: -1 },
  tagline: { fontSize: 15, color: colors.inkMuted, marginTop: spacing.sm, lineHeight: 22 },
  help: { fontSize: 13, color: colors.inkMuted, lineHeight: 19, marginBottom: spacing.md },
  choice: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    padding: spacing.lg,
    marginBottom: spacing.sm,
  },
  pressed: { opacity: 0.7 },
  choiceName: { fontSize: 17, fontWeight: '700', color: colors.ink },
  choiceMeta: { fontSize: 13, color: colors.inkMuted, marginTop: 2 },
  spaced: { marginTop: spacing.md },
  serverBlock: { marginTop: spacing.xxl },
  server: {
    marginTop: spacing.xxl,
    fontSize: 13,
    color: colors.inkMuted,
    textAlign: 'center',
    textDecorationLine: 'underline',
  },
});
