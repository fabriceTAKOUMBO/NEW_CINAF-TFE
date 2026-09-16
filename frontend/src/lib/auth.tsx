"use client";

/**
 * ============================================================
 * CINAF v2 — Contexte d'authentification React (AuthContext & useAuth)
 * ============================================================
 * Ce module gère l'état global d'authentification de l'utilisateur sur l'application Next.js.
 * 
 * Principes de sécurité et persistance :
 * - `access_token` : Jeton JWT à courte durée de vie stocké dans `localStorage` pour
 *   autoriser les requêtes HTTP via l'en-tête `Authorization: Bearer <token>`.
 * - `refresh_token` : Jeton à longue durée de vie stocké dans un cookie (SameSite=Strict)
 *   pour permettre le renouvellement automatique de session ou la déconnexion révocatoire.
 * - Récupération du profil : Réalisée de manière sécurisée via l'endpoint `/api/auth/me`.
 *   Le JWT n'est JAMAIS décodé de façon non fiable côté client afin d'éviter les désynchronisations
 *   de privilèges ou d'états d'abonnement.
 */

import React, {
  createContext,
  useContext,
  useState,
  useEffect,
  useCallback,
} from "react";
import {
  auth as authApi,
  type User,
  type LoginData,
  type RegisterData,
} from "@/lib/api";

// ─── Types du contexte ────────────────────────────────────────

/**
 * Interface définissant les propriétés et méthodes exposées par le hook `useAuth`.
 */
interface AuthContextValue {
  /** Objet utilisateur connecté, ou null si anonyme */
  user: User | null;
  /** Indicateur booléen dérivé indiquant si l'utilisateur est authentifié */
  isAuthenticated: boolean;
  /** Indicateur indiquant si la vérification de session initiale ou une opération d'authentification est en cours */
  isLoading: boolean;
  /** Déclenche l'authentification avec identifiants et met à jour l'état */
  login: (data: LoginData) => Promise<void>;
  /** Déconnecte l'utilisateur localement et informe le backend */
  logout: () => Promise<void>;
  /** Enregistre un nouvel utilisateur */
  register: (data: RegisterData) => Promise<{ message: string }>;
  /** Rafraîchit les informations du profil utilisateur depuis `/auth/me` */
  refresh: () => Promise<void>;
}

// ─── Helpers localStorage / cookie ────────────────────────────

/**
 * Enregistre les jetons d'authentification de manière persistante côté client.
 * 
 * @param accessToken - Le jeton JWT d'accès pour les requêtes API
 * @param refreshToken - Le jeton de rafraîchissement stocké en cookie
 */
function saveTokens(accessToken: string, refreshToken: string): void {
  if (typeof window === "undefined") return;
  // L'access_token est stocké en localStorage pour un accès rapide par le client HTTP
  localStorage.setItem("access_token", accessToken);
  // Le refresh_token est stocké dans un cookie sécurisé SameSite=Strict
  document.cookie = `refresh_token=${refreshToken}; path=/; max-age=${60 * 60 * 24 * 30}; SameSite=Strict`;
}

/**
 * Supprime toute trace de la session locale (localStorage et cookies).
 * Utilisé lors de la déconnexion volontaire ou de la réception d'un 401 Unauthorized.
 */
function clearTokens(): void {
  if (typeof window === "undefined") return;
  localStorage.removeItem("access_token");
  document.cookie = "refresh_token=; path=/; max-age=0";
}

/**
 * Récupère le jeton d'accès actuel depuis le stockage local.
 * 
 * @returns Le jeton sous forme de chaîne de caractères, ou null s'il n'existe pas ou en SSR.
 */
function getStoredAccessToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem("access_token");
}

// ─── Déclaration du Contexte React ────────────────────────────

const AuthContext = createContext<AuthContextValue | null>(null);

// ─── Provider Principal ───────────────────────────────────────

/**
 * Composant racine fournissant le contexte d'authentification à toute l'arborescence.
 * Doit impérativement envelopper les composants dans `src/app/layout.tsx`.
 * 
 * @param children - Les composants enfants à rendre
 */
export function AuthProvider({ children }: { children: React.ReactNode }) {
  // Stocke l'objet User complet récupéré depuis le backend
  const [user, setUser] = useState<User | null>(null);
  // Indique si la vérification de session initiale est terminée
  const [isLoading, setIsLoading] = useState<boolean>(true);

  /**
   * Effet d'initialisation au montage :
   * Vérifie si un jeton d'accès existe dans le navigateur.
   * Si oui, interroge `/api/auth/me` pour charger le profil et synchroniser les rôles/abonnements.
   */
  useEffect(() => {
    const token = getStoredAccessToken();
    if (!token) {
      setIsLoading(false);
      return;
    }

    // Récupération du profil via /api/auth/me. On ne décode JAMAIS le JWT côté
    // client (convention projet) : si le token est expiré ou invalide, le backend
    // répond 401 et le client HTTP nettoie la session → on bascule sur clearTokens().
    authApi
      .me()
      .then((profile) => {
        setUser(profile);
      })
      .catch(() => {
        clearTokens();
      })
      .finally(() => {
        setIsLoading(false);
      });
  }, []);

  // ── login ──────────────────────────────────────────────────

  /**
   * Authentifie l'utilisateur via ses identifiants (email, mot de passe).
   * Enregistre les jetons dans le navigateur et charge le profil utilisateur complet.
   * 
   * @param data - Identifiants de connexion (`email`, `password`)
   */
  const login = useCallback(async (data: LoginData): Promise<void> => {
    setIsLoading(true);
    try {
      const response = await authApi.login(data);
      saveTokens(response.access_token, response.refresh_token);

      // Si le backend renvoie déjà l'utilisateur, on met à jour l'état directement
      if (response.user) {
        setUser(response.user);
        return;
      }

      // Sinon, on récupère le profil complet via /auth/me
      const profile = await authApi.me();
      setUser(profile);
    } finally {
      setIsLoading(false);
    }
  }, []);

  // ── logout ─────────────────────────────────────────────────

  /**
   * Déconnexion complète : informe l'API pour révoquer le refresh_token serveur,
   * puis purge le stockage local et réinitialise l'état utilisateur à `null`.
   */
  const logout = useCallback(async (): Promise<void> => {
    try {
      // Appel au backend pour invalider la session et le refresh_token en base
      await authApi.logout();
    } catch {
      // Tolérance aux pannes réseau : on déconnecte localement même si le réseau échoue
    } finally {
      clearTokens();
      setUser(null);
    }
  }, []);

  // ── register ───────────────────────────────────────────────

  /**
   * Crée un nouveau compte utilisateur via l'API d'inscription.
   * 
   * @param data - Données d'inscription (email, mot de passe, nom, prénom, consentement RGPD)
   * @returns Un objet contenant le message de confirmation de l'API
   */
  const register = useCallback(
    async (data: RegisterData): Promise<{ message: string }> => {
      setIsLoading(true);
      try {
        const response = await authApi.register(data);
        return response;
      } finally {
        setIsLoading(false);
      }
    },
    []
  );

  // ── refresh ────────────────────────────────────────────────

  /**
   * Re-charge le profil utilisateur depuis `/auth/me`.
   * Utile après une souscription Stripe, une résiliation, ou une modification de profil,
   * afin de synchroniser instantanément les droits (ex: `hasActiveSubscription`, `roles`).
   */
  const refresh = useCallback(async (): Promise<void> => {
    if (!getStoredAccessToken()) return;
    try {
      const profile = await authApi.me();
      setUser(profile);
    } catch {
      // Ignorer silencieusement si la requête échoue
    }
  }, []);

  // Valeurs exposées aux composants descendants
  const value: AuthContextValue = {
    user,
    isAuthenticated: user !== null,
    isLoading,
    login,
    logout,
    register,
    refresh,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

// ─── Hook useAuth ─────────────────────────────────────────────

/**
 * Hook personnalisé permettant d'accéder au contexte d'authentification global.
 * 
 * @returns L'état d'authentification et les méthodes d'action (`user`, `login`, `logout`, `refresh`, etc.)
 * @throws {Error} Si le hook est appelé hors d'un composant enveloppé par `<AuthProvider>`
 */
export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error("useAuth doit être utilisé à l'intérieur d'un <AuthProvider>");
  }
  return ctx;
}
