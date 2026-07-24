import { Redirect } from 'expo-router';
import React from 'react';

import { useAuth } from '../src/auth/AuthContext';
import { Loader } from '../src/components/ui';

export default function Entry() {
  const { token, isLoading } = useAuth();

  if (isLoading) {
    return <Loader label="Chargement de votre stock…" />;
  }

  return <Redirect href={token ? '/(tabs)' : '/login'} />;
}
