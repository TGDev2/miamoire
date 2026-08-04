import { Redirect } from 'expo-router';
import React from 'react';

import { Loader } from '../src/components/ui';
import { useSession } from '../src/session/SessionContext';

export default function Entry() {
  const { isReady, isLoading } = useSession();

  if (isLoading) {
    return <Loader label="Chargement de votre stock" />;
  }

  return <Redirect href={isReady ? '/(tabs)' : '/setup'} />;
}
