import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import React from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { AuthProvider } from '../src/auth/AuthContext';
import { RecipeProvider } from '../src/recipes/RecipeContext';
import { colors } from '../src/theme';

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
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
            <Stack.Screen name="login" options={{ headerShown: false }} />
            <Stack.Screen name="register" options={{ title: 'Créer un compte' }} />
            <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
            <Stack.Screen name="add" options={{ title: 'Ajouter au stock' }} />
            <Stack.Screen name="item/[id]" options={{ title: 'Fiche aliment' }} />
            <Stack.Screen name="recipes" options={{ title: 'Propositions' }} />
            <Stack.Screen name="recipe/[index]" options={{ title: 'Recette' }} />
          </Stack>
        </RecipeProvider>
      </AuthProvider>
    </SafeAreaProvider>
  );
}
