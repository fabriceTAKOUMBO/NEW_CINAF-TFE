"use client";

// ============================================================
// CINAF v2 — Contexte d'authentification React
// Ce module gère l'état global de l'utilisateur (session).
// Persistance : access_token dans localStorage, refresh_token en cookie.
// ============================================================

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
 * Interface définissant les propriétés et méthodes exposées par le hook useAuth.
 */
interface AuthContextValue {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (data: LoginData) => Promise<void>;
  logout: () => Promise<void>;
  register: (data: RegisterData) => Promise<{ message: string }>;
  /** Re-fetch /auth/me et met à jour l'état user (utile après abonnement). */
  refresh: () => Promise<void>;
}

// ─── Helpers localStorage / cookie ────────────────────────────

/**
 * Enregistre les jetons d'authentification de manière persistante.
 */
function saveTokens(accessToken: string, refreshToken: string): void {
  if (typeof window === "undefined") return;
  // L'access_token est stocké en localStorage pour un accès rapide par le client HTTP
  localStorage.setItem("access_token", accessToken);
  // Le refresh_token est stocké dans un cookie (idéalement géré par le serveur en HttpOnly)
  document.cookie = `refresh_token=${refreshToken}; path=/; max-age=${60 * 60 * 24 * 30}; SameSite=Strict`;
}

/**
 * Supprime toute trace de la session locale.
 */
function clearTokens(): void {
  if (typeof window === "undefined") return;
  localStorage.removeItem("access_token");
  document.cookie = "refresh_token=; path=/; max-age=0";
}

/**
 * Récupère le jeton d'accès actuel depuis le stockage local.
 */
function getStoredAccessToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem("access_token");
}

// ─── Contexte ─────────────────────────────────────────────────

const AuthContext = createContext<AuthContextValue | null>(null);

// ─── Provider ─────────────────────────────────────────────────

/**
 * Composant racine de l'authentification.
 * Il doit envelopper l'application (dans layout.tsx) pour diffuser l'état de session.
 */
export function AuthProvider({ children }: { children: React.ReactNode }) {
  // Stocke l'objet User complet récupéré depuis le backend
  const [user, setUser] = useState<User | null>(null);
  // Indique si la vérification de session initiale est terminée
  const [isLoading, setIsLoading] = useState<boolean>(true);

  /**
   * Effet d'initialisation au montage : vérifie si une session existe déjà.
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
   * Authentifie l'utilisateur, stocke les jetons et charge son profil.
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

      // Sinon, on récupère le profil via /auth/me
      const profile = await authApi.me();
      setUser(profile);
    } finally {
      setIsLoading(false);
    }
  }, []);

  // ── logout ─────────────────────────────────────────────────

  /**
   * Déconnexion complète : informe le serveur et nettoie l'état local.
   */
  const logout = useCallback(async (): Promise<void> => {
    try {
      // Appel facultatif au backend pour invalider le refresh_token
      await authApi.logout();
    } catch {
      // Échec silencieux si réseau indisponible
    } finally {
      clearTokens();
      setUser(null);
    }
  }, []);

  // ── register ───────────────────────────────────────────────

  /**
   * Crée un nouveau compte utilisateur via l'API.
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
   * Re-charge le profil utilisateur depuis /auth/me.
   * Utilisé après une opération qui modifie l'état (souscription, annulation,
   * vérification email, etc.) pour mettre à jour les flags comme `hasActiveSubscription`.
   * Échec silencieux si non authentifié.
   */
  const refresh = useCallback(async (): Promise<void> => {
    if (!getStoredAccessToken()) return;
    try {
      const profile = await authApi.me();
      setUser(profile);
    } catch {
      // ignore
    }
  }, []);

  // Valeurs exposées aux composants enfants
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
 * Hook utilitaire pour accéder facilement aux fonctions et à l'état d'authentification.
 */
export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error("useAuth doit être utilisé à l'intérieur d'un <AuthProvider>");
  }
  return ctx;
}
