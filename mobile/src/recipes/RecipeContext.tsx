import React, { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';

import type { ConfirmationResponse, SuggestionResponse } from '../api/types';

interface RecipeState {
  suggestion: SuggestionResponse | null;
  setSuggestion: (value: SuggestionResponse | null) => void;
  /** La clé d'idempotence de cette recette, stable tant que la génération l'est. */
  operationKeyFor: (index: number) => string;
  /** La confirmation déjà appliquée, s'il y en a une. */
  confirmationFor: (index: number) => ConfirmationResponse | undefined;
  rememberConfirmation: (index: number, result: ConfirmationResponse) => void;
}

const RecipeContext = createContext<RecipeState | null>(null);

/**
 * Un identifiant assez long pour ne pas se répéter, assez court pour la contrainte
 * du serveur (8 à 64 caractères alphanumériques).
 */
function newSessionId(): string {
  const random = () => Math.random().toString(36).slice(2, 10);
  return `${Date.now().toString(36)}${random()}${random()}`;
}

/**
 * Conserve la dernière génération de recettes entre l'écran « Cuisiner »,
 * la liste des propositions et le détail, sans la redemander au serveur
 * (chaque appel au modèle a un coût).
 *
 * Deux états y vivent, et le second corrige un vrai défaut : « Déjà retiré du
 * stock » reposait sur un `useState` de l'écran de détail. Quitter puis rouvrir
 * cet écran recréait cet état, et le bouton se réarmait alors qu'une déduction
 * avait déjà eu lieu.
 *
 * La clé d'opération est générée ICI, une fois par génération de recettes. C'est
 * ce qui permet à une seconde tentative — après une réponse réseau perdue — de
 * porter la même clé, donc de ne rien déduire de plus.
 */
export function RecipeProvider({ children }: { children: React.ReactNode }) {
  const [suggestion, setSuggestionState] = useState<SuggestionResponse | null>(null);
  const [confirmations, setConfirmations] = useState<Record<number, ConfirmationResponse>>({});
  const sessionId = useRef<string>(newSessionId());

  const setSuggestion = useCallback((value: SuggestionResponse | null) => {
    // Nouvelle génération : nouvelles clés, et plus aucune confirmation en mémoire.
    sessionId.current = newSessionId();
    setConfirmations({});
    setSuggestionState(value);
  }, []);

  const operationKeyFor = useCallback((index: number) => `${sessionId.current}-${index}`, []);

  const confirmationFor = useCallback(
    (index: number) => confirmations[index],
    [confirmations],
  );

  const rememberConfirmation = useCallback((index: number, result: ConfirmationResponse) => {
    setConfirmations((current) => ({ ...current, [index]: result }));
  }, []);

  const value = useMemo(
    () => ({ suggestion, setSuggestion, operationKeyFor, confirmationFor, rememberConfirmation }),
    [suggestion, setSuggestion, operationKeyFor, confirmationFor, rememberConfirmation],
  );

  return <RecipeContext.Provider value={value}>{children}</RecipeContext.Provider>;
}

export function useRecipes(): RecipeState {
  const context = useContext(RecipeContext);
  if (!context) {
    throw new Error('useRecipes doit être utilisé à l\'intérieur d\'un RecipeProvider.');
  }
  return context;
}
