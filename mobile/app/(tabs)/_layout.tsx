import { Redirect, Tabs } from 'expo-router';
import React from 'react';
import { StyleSheet, Text } from 'react-native';

import { Icon, Loader, type IconName } from '../../src/components/ui';
import { useSession } from '../../src/session/SessionContext';
import { colors, font, spacing } from '../../src/theme';

function TabIcon({ name, focused }: { name: IconName; focused: boolean }) {
  return <Icon name={name} size={22} color={focused ? colors.ink : colors.inkMuted} />;
}

/**
 * Le libellé de l'onglet est rendu à la main : « Aujourd'hui » est plus large
 * qu'un cinquième d'écran en monospace, et le libellé standard le tronquait en
 * « AUJOURD'H… ». `adjustsFontSizeToFit` le resserre au lieu de l'amputer.
 */
function TabLabel({ children, focused }: { children: React.ReactNode; focused: boolean }) {
  return (
    <Text
      numberOfLines={1}
      adjustsFontSizeToFit
      style={[styles.tabLabel, { color: focused ? colors.ink : colors.inkMuted }]}
    >
      {children}
    </Text>
  );
}

export default function TabsLayout() {
  const { isReady, isLoading } = useSession();

  if (isLoading) return <Loader />;
  if (!isReady) return <Redirect href="/setup" />;

  return (
    <Tabs
      // « Aujourd'hui » est l'écran d'ouverture : c'est lui qui répond à
      // « que dois-je faire maintenant ? », avant même de consulter le stock.
      initialRouteName="today"
      screenOptions={{
        // Chaque onglet porte son titre dans le flux, en grand : la barre
        // native le répéterait en petit, deux fois la même chose sur 56 px.
        headerShown: false,
        tabBarActiveTintColor: colors.ink,
        tabBarInactiveTintColor: colors.inkMuted,
        // Ni hauteur fixe ni ombre : la barre calcule la sienne à partir de la
        // zone de gestes du téléphone — figée à 62, le trait de navigation
        // Android passait par-dessus le libellé « Scanner ».
        tabBarStyle: {
          backgroundColor: colors.surface,
          borderTopColor: colors.border,
          borderTopWidth: 1,
          elevation: 0,
          paddingTop: spacing.sm,
          paddingBottom: spacing.sm,
        },
        sceneStyle: { backgroundColor: colors.ground },
      }}
    >
      <Tabs.Screen
        name="today"
        options={{
          title: 'Aujourd\'hui',
          tabBarIcon: ({ focused }) => <TabIcon name="clock-outline" focused={focused} />,
          tabBarLabel: ({ focused }) => <TabLabel focused={focused}>Aujourd'hui</TabLabel>,
        }}
      />
      <Tabs.Screen
        name="index"
        options={{
          title: 'Mon stock',
          tabBarIcon: ({ focused }) => <TabIcon name="basket-outline" focused={focused} />,
          tabBarLabel: ({ focused }) => <TabLabel focused={focused}>Stock</TabLabel>,
        }}
      />
      <Tabs.Screen
        name="scan"
        options={{
          title: 'Scanner',
          tabBarIcon: ({ focused }) => <TabIcon name="barcode-scan" focused={focused} />,
          tabBarLabel: ({ focused }) => <TabLabel focused={focused}>Scanner</TabLabel>,
        }}
      />
      <Tabs.Screen
        name="cook"
        options={{
          title: 'Cuisiner',
          tabBarIcon: ({ focused }) => <TabIcon name="pot-steam-outline" focused={focused} />,
          tabBarLabel: ({ focused }) => <TabLabel focused={focused}>Cuisiner</TabLabel>,
        }}
      />
      <Tabs.Screen
        name="profile"
        options={{
          title: 'Mon foyer',
          tabBarIcon: ({ focused }) => <TabIcon name="account-outline" focused={focused} />,
          tabBarLabel: ({ focused }) => <TabLabel focused={focused}>Foyer</TabLabel>,
        }}
      />
    </Tabs>
  );
}

const styles = StyleSheet.create({
  // La voix machine jusque dans la navigation : capitales monospacées, très
  // petites, très espacées — un cartouche, pas une étiquette.
  // 8,5 px et un interlettrage court : « Aujourd'hui » est le libellé le plus
  // long des cinq, et il doit tenir en entier dans un cinquième d'écran.
  tabLabel: {
    fontFamily: font.monoMedium,
    fontSize: 8.5,
    lineHeight: 12,
    letterSpacing: 0.2,
    textTransform: 'uppercase',
    textAlign: 'center',
    marginTop: 3,
  },
});
