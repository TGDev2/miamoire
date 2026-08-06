import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';

import { ApiError, api } from '../api/client';
import type { ConfirmationResponse, SuggestionResponse } from '../api/types';

/** Ce qu'on demande au générateur — le corps de `api.suggestRecipes`. */
export type GenerationRequest = Parameters<typeof api.suggestRecipes>[0];

/**
 * L'état d'une génération, qui vit ici et non dans l'écran.
 *
 * `error` conserve la demande : c'est ce qui permet de réessayer sans redescendre
 * les dix rubriques du formulaire.
 */
export type GenerationState =
  | { status: 'idle' }
  | { status: 'running' }
  | { status: 'ready' }
  | { status: 'error'; message: string; request: GenerationRequest };

interface RecipeState {
  suggestion: SuggestionResponse | null;
  setSuggestion: (value: SuggestionResponse | null) => void;
  /** La clé d'idempotence de cette recette, stable tant que la génération l'est. */
  operationKeyFor: (index: number) => string;
  /** La confirmation déjà appliquée, s'il y en a une. */
  confirmationFor: (index: number) => ConfirmationResponse | undefined;
  rememberConfirmation: (index: number, result: ConfirmationResponse) => void;
  /** L'enregistrement de cette proposition, s'il a eu lieu. */
  savedIdFor: (index: number) => string | null;
  rememberSaved: (index: number, id: string | null) => void;

  generation: GenerationState;
  /** Secondes écoulées depuis le début de la génération en cours. */
  elapsed: number;
  /**
   * La dernière demande envoyée, pour rejouer les mêmes réglages.
   *
   * Sans elle, ne pas aimer les trois propositions imposait de revenir sur
   * « Cuisiner » et d'en redescendre les dix rubriques — pour ne rien y changer.
   */
  lastRequest: GenerationRequest | null;
  generate: (request: GenerationRequest) => void;
  cancelGeneration: () => void;
  /** Le résultat a été vu, ou l'erreur lue : on repart de zéro. */
  acknowledgeGeneration: () => void;
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
 *
 * ## Pourquoi la génération elle-même a fini par y vivre
 *
 * Elle était un `async` de l'écran « Cuisiner ». Quarante à cinquante secondes
 * plus tard, la promesse se résolvait dans le vide si l'on avait changé
 * d'onglet entre-temps : une minute d'attente et un appel au modèle — facturé —
 * perdus, sans un mot. Portée par le contexte, elle survit à la sortie de
 * l'écran, s'annule vraiment, et son résultat attend au retour.
 */
export function RecipeProvider({ children }: { children: React.ReactNode }) {
  const [suggestion, setSuggestionState] = useState<SuggestionResponse | null>(null);
  const [confirmations, setConfirmations] = useState<Record<number, ConfirmationResponse>>({});
  const [savedIds, setSavedIds] = useState<Record<number, string>>({});
  const sessionId = useRef<string>(newSessionId());

  const [generation, setGeneration] = useState<GenerationState>({ status: 'idle' });
  const [elapsed, setElapsed] = useState(0);
  const [lastRequest, setLastRequest] = useState<GenerationRequest | null>(null);
  const controller = useRef<AbortController | null>(null);

  const setSuggestion = useCallback((value: SuggestionResponse | null) => {
    // Nouvelle génération : nouvelles clés, et plus aucune confirmation en mémoire.
    sessionId.current = newSessionId();
    setConfirmations({});
    setSavedIds({});
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

  const savedIdFor = useCallback((index: number) => savedIds[index] ?? null, [savedIds]);

  // Même raison que pour les confirmations : sans cette mémoire, revenir sur la
  // recette proposerait de l'enregistrer une seconde fois.
  const rememberSaved = useCallback((index: number, id: string | null) => {
    setSavedIds((current) => {
      if (id === null) {
        const { [index]: _removed, ...rest } = current;
        return rest;
      }

      return { ...current, [index]: id };
    });
  }, []);

  const generate = useCallback(
    (request: GenerationRequest) => {
      controller.current?.abort();
      const own = new AbortController();
      controller.current = own;

      setElapsed(0);
      setLastRequest(request);
      setGeneration({ status: 'running' });

      void api
        .suggestRecipes(request, own.signal)
        .then((result) => {
          if (own.signal.aborted) return;
          setSuggestion(result);
          setGeneration({ status: 'ready' });
        })
        .catch((exception) => {
          // Une annulation est un geste, pas une panne : elle a déjà remis
          // l'état à `idle` et n'a donc rien à afficher.
          if (own.signal.aborted || (exception instanceof ApiError && exception.isAborted)) return;

          setGeneration({
            status: 'error',
            message: exception instanceof Error ? exception.message : 'Génération impossible.',
            request,
          });
        });
    },
    [setSuggestion],
  );

  const cancelGeneration = useCallback(() => {
    controller.current?.abort();
    controller.current = null;
    setGeneration({ status: 'idle' });
  }, []);

  const acknowledgeGeneration = useCallback(() => setGeneration({ status: 'idle' }), []);

  // Le temps écoulé se compte ici, et non dans l'écran : c'est la seule façon
  // qu'il reste juste quand on quitte « Cuisiner » pendant l'attente.
  useEffect(() => {
    if ('running' !== generation.status) return;

    const tick = setInterval(() => setElapsed((value) => value + 1), 1000);

    return () => clearInterval(tick);
  }, [generation.status]);

  // L'application se ferme : la requête n'a plus personne à qui répondre.
  useEffect(() => () => controller.current?.abort(), []);

  const value = useMemo(
    () => ({
      suggestion,
      setSuggestion,
      operationKeyFor,
      confirmationFor,
      rememberConfirmation,
      savedIdFor,
      rememberSaved,
      generation,
      elapsed,
      lastRequest,
      generate,
      cancelGeneration,
      acknowledgeGeneration,
    }),
    [
      suggestion,
      setSuggestion,
      operationKeyFor,
      confirmationFor,
      rememberConfirmation,
      savedIdFor,
      rememberSaved,
      generation,
      elapsed,
      lastRequest,
      generate,
      cancelGeneration,
      acknowledgeGeneration,
    ],
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
