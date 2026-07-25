import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import React from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { RecipeProvider } from '../src/recipes/RecipeContext';
import { SessionProvider } from '../src/session/SessionContext';
import { colors } from '../src/theme';

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <SessionProvider>
        <RecipeProvider>
          <StatusBar style="dark" />
          <Stack
            screenOptions={{
              headerStyle: { backgroundColor: colors.background },
              headerTitleStyle: { color: colors.ink, fontWeight: '700' },
              headerTintColor: colors.primary,
              contentStyle: { backgroundColor: colors.background },
            }}
          >
            <Stack.Screen name="index" options={{ headerShown: false }} />
            <Stack.Screen name="setup" options={{ headerShown: false }} />
            <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
            <Stack.Screen name="add" options={{ title: 'Ajouter au stock' }} />
            <Stack.Screen name="item/[id]" options={{ title: 'Fiche aliment' }} />
            <Stack.Screen name="recipes" options={{ title: 'Propositions' }} />
            <Stack.Screen name="recipe/[index]" options={{ title: 'Recette' }} />
          </Stack>
        </RecipeProvider>
      </SessionProvider>
    </SafeAreaProvider>
  );
}
