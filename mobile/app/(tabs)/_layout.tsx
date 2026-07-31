import { Redirect, Tabs } from 'expo-router';
import React from 'react';
import { Text } from 'react-native';

import { Loader } from '../../src/components/ui';
import { useSession } from '../../src/session/SessionContext';
import { colors } from '../../src/theme';

function TabIcon({ glyph, focused }: { glyph: string; focused: boolean }) {
  return <Text style={{ fontSize: 20, opacity: focused ? 1 : 0.45 }}>{glyph}</Text>;
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
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.inkMuted,
        tabBarStyle: { backgroundColor: colors.surface, borderTopColor: colors.border },
        headerStyle: { backgroundColor: colors.background },
        headerTitleStyle: { color: colors.ink, fontWeight: '700' },
        sceneStyle: { backgroundColor: colors.background },
      }}
    >
      <Tabs.Screen
        name="today"
        options={{
          title: 'Aujourd\'hui',
          tabBarIcon: ({ focused }) => <TabIcon glyph="⏳" focused={focused} />,
        }}
      />
      <Tabs.Screen
        name="index"
        options={{
          title: 'Mon stock',
          tabBarIcon: ({ focused }) => <TabIcon glyph="🧺" focused={focused} />,
        }}
      />
      <Tabs.Screen
        name="scan"
        options={{
          title: 'Scanner',
          tabBarIcon: ({ focused }) => <TabIcon glyph="📷" focused={focused} />,
        }}
      />
      <Tabs.Screen
        name="cook"
        options={{
          title: 'Cuisiner',
          tabBarIcon: ({ focused }) => <TabIcon glyph="🍳" focused={focused} />,
        }}
      />
      <Tabs.Screen
        name="profile"
        options={{
          title: 'Profil',
          tabBarIcon: ({ focused }) => <TabIcon glyph="👤" focused={focused} />,
        }}
      />
    </Tabs>
  );
}
