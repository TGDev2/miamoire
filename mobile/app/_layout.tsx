import * as Notifications from 'expo-notifications';
import { Stack, useRouter } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import React, { useEffect } from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { useExpiryAlerts } from '../src/notifications/useExpiryAlerts';
import { RecipeProvider } from '../src/recipes/RecipeContext';
import { SessionProvider, useSession } from '../src/session/SessionContext';
import { colors } from '../src/theme';

// Une alerte de péremption doit s'afficher même si l'application est ouverte :
// c'est souvent à ce moment-là qu'on décide du repas.
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldPlaySound: false,
    shouldSetBadge: false,
    shouldShowBanner: true,
    shouldShowList: true,
  }),
});

export default function RootLayout() {
  const router = useRouter();

  // Toucher l'alerte doit mener à l'aliment concerné, pas à l'écran d'accueil.
  useEffect(() => {
    const subscription = Notifications.addNotificationResponseReceivedListener((response) => {
      const { stockItemId } = response.notification.request.content.data as {
        stockItemId?: string;
      };

      if (stockItemId) router.push(`/item/${stockItemId}`);
    });

    return () => subscription.remove();
  }, [router]);

  return (
    <SafeAreaProvider>
      <SessionProvider>
        <ExpiryAlerts />
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
            <Stack.Screen name="saved/index" options={{ title: 'Mes recettes' }} />
            <Stack.Screen name="saved/[id]" options={{ title: 'Recette enregistrée' }} />
          </Stack>
        </RecipeProvider>
      </SessionProvider>
    </SafeAreaProvider>
  );
}

/**
 * Les alertes se resynchronisent au niveau de l'application, pas d'un écran.
 *
 * Le hook a besoin de la session : il doit donc vivre SOUS `SessionProvider`, ce
 * qui demande ce composant sans rendu plutôt qu'un appel dans `RootLayout`.
 * Il n'affiche rien, il écoute le retour au premier plan.
 */
function ExpiryAlerts() {
  const { household } = useSession();

  useExpiryAlerts(household !== null);

  return null;
}
