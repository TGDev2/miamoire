import React, { createContext, useContext, useMemo, useState } from 'react';

import type { SuggestionResponse } from '../api/types';

interface RecipeState {
  suggestion: SuggestionResponse | null;
  setSuggestion: (value: SuggestionResponse | null) => void;
}

const RecipeContext = createContext<RecipeState | null>(null);

/**
 * Conserve la dernière génération de recettes entre l'écran « Cuisiner »,
 * la liste des propositions et le détail, sans la redemander au serveur
 * (chaque appel au modèle a un coût).
 */
export function RecipeProvider({ children }: { children: React.ReactNode }) {
  const [suggestion, setSuggestion] = useState<SuggestionResponse | null>(null);
  const value = useMemo(() => ({ suggestion, setSuggestion }), [suggestion]);

  return <RecipeContext.Provider value={value}>{children}</RecipeContext.Provider>;
}

export function useRecipes(): RecipeState {
  const context = useContext(RecipeContext);
  if (!context) {
    throw new Error('useRecipes doit être utilisé à l\'intérieur d\'un RecipeProvider.');
  }
  return context;
}
