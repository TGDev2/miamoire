import { useRouter } from 'expo-router';
import React, { useEffect, useState } from 'react';
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  Share,
  StyleSheet,
  Text,
  View,
} from 'react-native';

import { ApiError, api } from '../src/api/client';
import { ServerAddressField } from '../src/components/ServerAddressField';
import { Button, Card, ErrorBanner, Field, Loader, SectionTitle } from '../src/components/ui';
import { useSession } from '../src/session/SessionContext';
import { colors, radius, spacing } from '../src/theme';

/**
 * Mise en route : ouvrir un foyer, puis dire qui l'on est.
 *
 * Le foyer est une porte dont chacun a la clé. On la crée — le code s'affiche
 * alors une seule et unique fois — ou on la reçoit d'un membre du foyer.
 * Aucun compte, aucun mot de passe : le prénom sert seulement à signer les
 * mouvements de stock.
 */
export default function SetupScreen() {
  const router = useRouter();
  const { household, member, accessKey, enterHousehold, selectMember, refresh } = useSession();

  const [mode, setMode] = useState<'join' | 'create'>('join');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showServer, setShowServer] = useState(false);

  const [code, setCode] = useState('');
  const [newHousehold, setNewHousehold] = useState('');
  const [newHouseholdOwner, setNewHouseholdOwner] = useState('');
  const [newMember, setNewMember] = useState('');
  /** Affiché juste après une création : c'est la seule fois où le code est lisible. */
  const [issuedKey, setIssuedKey] = useState<string | null>(null);

  // Dès qu'un foyer est ouvert et une personne choisie, on entre dans l'application.
  useEffect(() => {
    if (household && member && !issuedKey) router.replace('/(tabs)');
  }, [household, member, issuedKey, router]);

  async function run(action: () => Promise<unknown>) {
    setBusy(true);
    setError(null);
    try {
      await action();
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Opération impossible.');
      if (exception instanceof ApiError && exception.status === 0) setShowServer(true);
    } finally {
      setBusy(false);
    }
  }

  function createHousehold() {
    if (!newHousehold.trim() || !newHouseholdOwner.trim()) {
      setError('Indiquez le nom du foyer et votre prénom.');
      return;
    }

    run(async () => {
      const created = await api.createHousehold(newHousehold.trim(), newHouseholdOwner.trim());
      await enterHousehold(created, created.accessKey);
      if (created.members[0]) await selectMember(created.members[0]);
      setIssuedKey(created.accessKey);
    });
  }

  function joinHousehold() {
    if (!code.trim()) {
      setError('Saisissez le code du foyer.');
      return;
    }

    run(async () => {
      const joined = await api.joinHousehold(code.trim());
      await enterHousehold(joined, code.trim());
    });
  }

  function addMember() {
    if (!household || !newMember.trim()) {
      setError('Indiquez votre prénom.');
      return;
    }

    run(async () => {
      const created = await api.addMember(household.id, newMember.trim());
      setNewMember('');
      await selectMember(created);
      await refresh();
    });
  }

  // Le code vient d'être délivré : rien d'autre ne doit détourner l'attention.
  if (issuedKey) {
    return (
      <ScrollView contentContainerStyle={styles.container}>
        <Text style={styles.logo}>Food</Text>
        <SectionTitle>Le code de votre foyer</SectionTitle>
        <Text style={styles.help}>
          Notez-le maintenant. Le serveur n'en conserve qu'une empreinte : il ne pourra
          plus jamais vous le réafficher. C'est ce code, et lui seul, qui donne accès à
          votre stock — transmettez-le aux membres de votre foyer, à personne d'autre.
        </Text>

        <View style={styles.codeBox}>
          <Text selectable style={styles.codeText}>
            {issuedKey}
          </Text>
        </View>

        <Button
          label="Partager le code"
          variant="secondary"
          onPress={() =>
            Share.share({
              message: `Rejoins notre foyer « ${household?.name ?? ''} » dans l'application Food avec ce code : ${issuedKey}`,
            })
          }
        />
        <Button
          label="J'ai noté le code, continuer"
          onPress={() => setIssuedKey(null)}
          style={styles.spaced}
        />
      </ScrollView>
    );
  }

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
              Chaque foyer a son propre stock, fermé par un code. Rien n'est partagé
              entre deux foyers.
            </Text>

            <View style={styles.tabs}>
              <Pressable
                onPress={() => setMode('join')}
                style={[styles.tab, mode === 'join' && styles.tabActive]}
              >
                <Text style={[styles.tabText, mode === 'join' && styles.tabTextActive]}>
                  J'ai un code
                </Text>
              </Pressable>
              <Pressable
                onPress={() => setMode('create')}
                style={[styles.tab, mode === 'create' && styles.tabActive]}
              >
                <Text style={[styles.tabText, mode === 'create' && styles.tabTextActive]}>
                  Créer un foyer
                </Text>
              </Pressable>
            </View>

            {mode === 'join' ? (
              <Card>
                <Field
                  label="Code du foyer"
                  value={code}
                  onChangeText={setCode}
                  autoCapitalize="characters"
                  autoCorrect={false}
                  placeholder="K7M2-9QXR-4TVB-8NHD-3JPC"
                  hint="Demandez-le à quelqu'un du foyer. Les tirets et la casse n'ont pas d'importance."
                  onSubmitEditing={joinHousehold}
                  returnKeyType="done"
                />
                <Button label="Rejoindre ce foyer" onPress={joinHousehold} loading={busy} />
              </Card>
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

            {accessKey ? (
              <Text style={styles.codeReminder}>Code de ce foyer : {accessKey}</Text>
            ) : null}
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
  tabs: { flexDirection: 'row', gap: spacing.sm, marginBottom: spacing.md },
  tab: {
    flex: 1,
    paddingVertical: spacing.md,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    alignItems: 'center',
  },
  tabActive: { backgroundColor: colors.primary, borderColor: colors.primary },
  tabText: { fontSize: 14, fontWeight: '600', color: colors.ink },
  tabTextActive: { color: '#FFFFFF' },
  codeBox: {
    backgroundColor: colors.primarySoft,
    borderRadius: radius.lg,
    paddingVertical: spacing.xl,
    paddingHorizontal: spacing.md,
    marginBottom: spacing.lg,
    alignItems: 'center',
  },
  codeText: {
    fontSize: 22,
    fontWeight: '800',
    color: colors.primary,
    letterSpacing: 1,
    textAlign: 'center',
  },
  codeReminder: {
    marginTop: spacing.lg,
    fontSize: 12,
    color: colors.inkMuted,
    textAlign: 'center',
  },
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
