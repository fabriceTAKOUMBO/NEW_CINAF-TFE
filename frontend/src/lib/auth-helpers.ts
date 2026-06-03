// ============================================================
// CINAF v2 — Helpers d'authentification (rôles)
// Centralise les vérifications de rôles utilisées à travers
// les layouts et les pages protégées (admin / studio).
// ============================================================

import type { User } from "./api";

/**
 * Constantes des rôles utilisés dans le projet, alignées sur le backend Symfony.
 */
export const ROLES = {
  USER: "ROLE_USER",
  ABONNE: "ROLE_ABONNE",
  CREATEUR: "ROLE_CREATEUR",
  MODERATEUR: "ROLE_MODERATEUR",
  ADMIN: "ROLE_ADMIN",
} as const;

export type RoleName = (typeof ROLES)[keyof typeof ROLES];

/**
 * Vérifie si l'utilisateur possède le rôle donné.
 * Retourne `false` pour user null/undefined ou roles indéfinis.
 */
export function hasRole(
  user: User | null | undefined,
  role: string,
): boolean {
  return Array.isArray(user?.roles) && user!.roles.includes(role);
}

/**
 * Vrai si l'utilisateur a le rôle ROLE_ADMIN.
 */
export function isAdmin(user: User | null | undefined): boolean {
  return hasRole(user, ROLES.ADMIN);
}

/**
 * Vrai si l'utilisateur a le rôle ROLE_CREATEUR (studio).
 */
export function isStudio(user: User | null | undefined): boolean {
  return hasRole(user, ROLES.CREATEUR);
}

/**
 * Vrai si l'utilisateur a le rôle ROLE_MODERATEUR.
 */
export function isModerator(user: User | null | undefined): boolean {
  return hasRole(user, ROLES.MODERATEUR);
}
