import { useRouter } from 'expo-router';
import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet } from 'react-native';

import { useAuth } from '../src/auth/AuthContext';
import { Button, ErrorBanner, Field } from '../src/components/ui';
import { colors, spacing } from '../src/theme';

export default function RegisterScreen() {
  const router = useRouter();
  const { signUp } = useAuth();
  const [name, setName] = useState('');
  const [householdName, setHouseholdName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setError(null);
    setBusy(true);
    try {
      await signUp({ email, password, name, householdName: householdName || undefined });
      router.replace('/(tabs)');
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Inscription impossible.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <KeyboardAvoidingView
      style={styles.flex}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
        <ErrorBanner message={error} />

        <Field label="Votre prénom" value={name} onChangeText={setName} placeholder="Théo" />
        <Field
          label="Nom du foyer"
          value={householdName}
          onChangeText={setHouseholdName}
          placeholder="Maison"
          hint="Facultatif : le stock est partagé entre les membres du foyer."
        />
        <Field
          label="Adresse e-mail"
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          keyboardType="email-address"
          placeholder="vous@exemple.fr"
        />
        <Field
          label="Mot de passe"
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          hint="8 caractères minimum."
        />

        <Button label="Créer mon compte" onPress={submit} loading={busy} />
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, backgroundColor: colors.background },
  container: { padding: spacing.xl },
});
