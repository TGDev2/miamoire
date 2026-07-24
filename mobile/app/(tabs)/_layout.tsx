import { Redirect, Tabs } from 'expo-router';
import React from 'react';
import { Text } from 'react-native';

import { useAuth } from '../../src/auth/AuthContext';
import { Loader } from '../../src/components/ui';
import { colors } from '../../src/theme';

function TabIcon({ glyph, focused }: { glyph: string; focused: boolean }) {
  return <Text style={{ fontSize: 20, opacity: focused ? 1 : 0.45 }}>{glyph}</Text>;
}

export default function TabsLayout() {
  const { token, isLoading } = useAuth();

  if (isLoading) return <Loader />;
  if (!token) return <Redirect href="/login" />;

  return (
    <Tabs
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
