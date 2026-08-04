import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { api } from '../api/client';
import { DEFAULT_API_BASE_URL, getApiBaseUrl, setApiBaseUrl } from '../api/config';
import { font, spacing, text, thermal } from '../theme';
import { Button, Field } from './ui';

/**
 * Adresse du serveur, modifiable depuis l'application.
 *
 * En usage normal, elle n'a pas à être touchée : l'adresse publique de l'API est
 * intégrée au build. Le champ existe pour le développement — pointer vers l'API
 * qui tourne sur le PC, par son IP en Wi-Fi ou par `adb reverse` en USB — et pour
 * dépanner si l'adresse embarquée devient obsolète.
 *
 * Le test de connexion répond depuis le téléphone à la seule question qui compte
 * quand rien ne s'affiche : est-ce l'adresse qui est fausse, ou le serveur qui ne
 * répond pas ?
 */
export function ServerAddressField() {
  const [value, setValue] = useState(getApiBaseUrl());
  const [saved, setSaved] = useState<string | null>(null);
  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState<{ ok: boolean; message: string } | null>(null);

  async function apply(next: string | null) {
    const applied = await setApiBaseUrl(next);
    setValue(applied);
    setSaved(applied);
    setTestResult(null);
  }

  async function test() {
    setTesting(true);
    setTestResult(null);
    try {
      // L'adresse saisie est enregistrée d'abord : on teste ce qu'on utilisera.
      const applied = await setApiBaseUrl(value);
      setValue(applied);
      await api.health();
      setTestResult({ ok: true, message: `Serveur joignable à ${applied}` });
    } catch (exception) {
      setTestResult({
        ok: false,
        message: exception instanceof Error ? exception.message : 'Serveur injoignable.',
      });
    } finally {
      setTesting(false);
    }
  }

  return (
    <View>
      <Field
        label="Adresse du serveur"
        value={value}
        onChangeText={setValue}
        autoCapitalize="none"
        autoCorrect={false}
        keyboardType="url"
        placeholder="https://mon-api.exemple.dev"
        hint="Adresse publique de l'API. En développement : IP du PC en Wi-Fi, ou localhost:8000 en USB."
      />

      <View style={styles.row}>
        <Button
          label="Enregistrer"
          variant="secondary"
          onPress={() => apply(value)}
          style={styles.grow}
        />
        <Button label="Défaut" variant="ghost" onPress={() => apply(null)} />
      </View>

      <Button
        label="Tester la connexion"
        variant="secondary"
        loading={testing}
        onPress={test}
        style={styles.spaced}
      />

      {testResult ? (
        <Text style={[styles.confirmation, !testResult.ok && styles.failure]}>
          {testResult.message}
        </Text>
      ) : null}

      {saved ? <Text style={styles.confirmation}>Adresse enregistrée : {saved}</Text> : null}
      <Text style={styles.hint}>Adresse par défaut de cette version : {DEFAULT_API_BASE_URL}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', gap: spacing.sm, alignItems: 'center' },
  grow: { flex: 1 },
  spaced: { marginTop: spacing.sm },
  /** Une adresse est une donnée machine : elle se relit caractère par caractère. */
  confirmation: {
    fontFamily: font.mono,
    fontSize: 11,
    lineHeight: 16,
    color: thermal.safe.line,
    marginTop: spacing.sm,
  },
  failure: { color: thermal.critical.line },
  hint: { ...text.data, fontSize: 11, marginTop: spacing.xs },
});
