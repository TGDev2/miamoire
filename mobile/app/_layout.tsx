import {
  Archivo_400Regular,
  Archivo_500Medium,
  Archivo_600SemiBold,
  Archivo_700Bold,
} from '@expo-google-fonts/archivo';
import {
  MartianMono_400Regular,
  MartianMono_500Medium,
  MartianMono_600SemiBold,
} from '@expo-google-fonts/martian-mono';
import { useFonts } from 'expo-font';
import * as Notifications from 'expo-notifications';
import { Stack, useRouter } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import React, { useEffect } from 'react';
import { View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { FeedbackProvider } from '../src/components/Feedback';
import { useExpiryAlerts } from '../src/notifications/useExpiryAlerts';
import { RecipeProvider } from '../src/recipes/RecipeContext';
import { SessionProvider, useSession } from '../src/session/SessionContext';
import { ShoppingSessionProvider } from '../src/shopping/ShoppingSessionContext';
import { colors, font } from '../src/theme';

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

  // Les deux familles portent toute l'identité de l'interface : rendre avant
  // qu'elles soient prêtes ferait clignoter chaque écran de la police système
  // vers la sienne. On retient le rendu sur le givre, le temps du chargement —
  // il se compte en dizaines de millisecondes, les fichiers sont embarqués.
  const [fontsLoaded, fontError] = useFonts({
    [font.sans]: Archivo_400Regular,
    [font.sansMedium]: Archivo_500Medium,
    [font.sansSemiBold]: Archivo_600SemiBold,
    [font.sansBold]: Archivo_700Bold,
    [font.mono]: MartianMono_400Regular,
    [font.monoMedium]: MartianMono_500Medium,
    [font.monoSemiBold]: MartianMono_600SemiBold,
  });

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

  // Une police introuvable ne doit pas bloquer l'application : on rend alors
  // avec les polices système plutôt que de laisser un écran vide définitif.
  if (!fontsLoaded && !fontError) {
    return <View style={{ flex: 1, backgroundColor: colors.ground }} />;
  }

  return (
    <SafeAreaProvider>
      {/* La pastille de succès vit au-dessus de la pile de navigation, et non
          dans un écran : une action engagée sur « Aujourd'hui » doit pouvoir
          s'annuler depuis le stock, et partir quoi qu'il arrive ensuite. */}
      <FeedbackProvider>
        <SessionProvider>
          <ExpiryAlerts />
          <RecipeProvider>
            <ShoppingSessionProvider>
              <StatusBar style="dark" />
              <Stack
                screenOptions={{
                  headerStyle: { backgroundColor: colors.ground },
                  headerTitleStyle: { color: colors.ink, fontFamily: font.sansSemiBold, fontSize: 17 },
                  headerTintColor: colors.ink,
                  headerShadowVisible: false,
                  contentStyle: { backgroundColor: colors.ground },
                }}
              >
                <Stack.Screen name="index" options={{ headerShown: false }} />
                <Stack.Screen name="setup" options={{ headerShown: false }} />
                <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
                <Stack.Screen name="add" options={{ title: 'Ajouter au stock' }} />
                <Stack.Screen name="review" options={{ title: 'Vérifier mon stock' }} />
                <Stack.Screen name="cuts" options={{ title: 'Préciser les formes' }} />
                <Stack.Screen name="quick-consume" options={{ title: "J'ai utilisé quelque chose" }} />
                <Stack.Screen name="meal-plan" options={{ title: 'Mon plan de repas' }} />
                <Stack.Screen name="meal-plan-entry/[id]" options={{ title: 'Repas planifié' }} />
                <Stack.Screen name="shopping-review" options={{ title: 'Retour de courses' }} />
                <Stack.Screen name="shopping-list" options={{ title: 'Liste de courses' }} />
                <Stack.Screen name="shopping-advisor" options={{ title: 'Avant les courses' }} />
                <Stack.Screen name="shopping-check" options={{ title: 'Vérifier un produit', headerShown: false }} />
                <Stack.Screen name="item/[id]" options={{ title: 'Fiche aliment' }} />
                <Stack.Screen name="recipes" options={{ title: 'Recettes proposées' }} />
                <Stack.Screen name="recipe/[index]" options={{ title: 'Recette' }} />
                <Stack.Screen name="saved/index" options={{ title: 'Mes recettes' }} />
                <Stack.Screen name="saved/[id]" options={{ title: 'Recette enregistrée' }} />
                <Stack.Screen name="recipe-import" options={{ title: 'Importer une recette' }} />
              </Stack>
            </ShoppingSessionProvider>
          </RecipeProvider>
        </SessionProvider>
      </FeedbackProvider>
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
