import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { DEFAULT_API_BASE_URL, getApiBaseUrl, setApiBaseUrl } from '../api/config';
import { colors, spacing } from '../theme';
import { Button, Field } from './ui';

/**
 * Adresse du serveur, modifiable depuis l'application.
 *
 * Selon le contexte, le téléphone joint l'API par l'IP du PC sur le Wi-Fi, ou par
 * `adb reverse tcp:8000 tcp:8000` en USB quand le pare-feu bloque le port. Un seul
 * APK couvre les deux cas, sans recompilation.
 */
export function ServerAddressField() {
  const [value, setValue] = useState(getApiBaseUrl());
  const [saved, setSaved] = useState<string | null>(null);

  async function apply(next: string | null) {
    const applied = await setApiBaseUrl(next);
    setValue(applied);
    setSaved(applied);
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
        placeholder="192.168.1.61:8000"
        hint="IP du PC en Wi-Fi, ou localhost:8000 avec « adb reverse » en USB."
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

      {saved ? <Text style={styles.confirmation}>Adresse enregistrée : {saved}</Text> : null}
      <Text style={styles.hint}>Adresse par défaut de cette version : {DEFAULT_API_BASE_URL}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', gap: spacing.sm, alignItems: 'center' },
  grow: { flex: 1 },
  confirmation: { fontSize: 13, color: colors.primary, marginTop: spacing.sm },
  hint: { fontSize: 11, color: colors.inkMuted, marginTop: spacing.xs },
});
