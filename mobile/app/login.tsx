import { Link, useRouter } from 'expo-router';
import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { ServerAddressField } from '../src/components/ServerAddressField';
import { Button, ErrorBanner, Field } from '../src/components/ui';
import { colors, spacing } from '../src/theme';

export default function LoginScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const { signIn } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [showServer, setShowServer] = useState(false);

  async function submit() {
    setError(null);
    setBusy(true);
    try {
      await signIn(email, password);
      router.replace('/(tabs)');
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Connexion impossible.');
      // Serveur injoignable : l'adresse est probablement en cause, on l'ouvre.
      if (exception instanceof ApiError && exception.status === 0) {
        setShowServer(true);
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <KeyboardAvoidingView
      style={styles.flex}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <ScrollView
        contentContainerStyle={[
          styles.container,
          // Sans cette marge, le lien de configuration passe sous la barre de navigation.
          { paddingTop: insets.top + spacing.xxl, paddingBottom: insets.bottom + spacing.xl },
        ]}
        keyboardShouldPersistTaps="handled"
      >
        <View style={styles.header}>
          <Text style={styles.logo}>Food</Text>
          <Text style={styles.tagline}>
            Ce que vous avez, ce qui va périmer, ce que vous pouvez cuisiner.
          </Text>
        </View>

        <ErrorBanner message={error} />

        <Field
          label="Adresse e-mail"
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          keyboardType="email-address"
          autoComplete="email"
          placeholder="vous@exemple.fr"
        />
        <Field
          label="Mot de passe"
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          placeholder="••••••••"
        />

        <Button label="Se connecter" onPress={submit} loading={busy} />

        <Link href="/register" style={styles.link}>
          <Text style={styles.linkText}>Créer un compte et un foyer</Text>
        </Link>

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
  container: { paddingHorizontal: spacing.xl, flexGrow: 1 },
  header: { marginBottom: spacing.xxl },
  logo: { fontSize: 40, fontWeight: '800', color: colors.primary, letterSpacing: -1 },
  tagline: { fontSize: 15, color: colors.inkMuted, marginTop: spacing.sm, lineHeight: 22 },
  link: { marginTop: spacing.xl, alignSelf: 'center' },
  linkText: { color: colors.primary, fontSize: 15, fontWeight: '600' },
  serverBlock: { marginTop: spacing.xxl },
  server: {
    marginTop: 'auto',
    paddingTop: spacing.xl,
    fontSize: 13,
    color: colors.inkMuted,
    textAlign: 'center',
    textDecorationLine: 'underline',
  },
});
