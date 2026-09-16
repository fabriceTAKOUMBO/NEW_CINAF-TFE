/**
 * ============================================================
 * CINAF v2 — Helpers d'authentification et gestion des rôles
 * ============================================================
 * Ce module centralise les vérifications de privilèges et de rôles
 * applicatifs utilisés à travers les layouts, les middlewares et les
 * pages protégées de la plateforme CINAF (ex: Admin, Studio, Modération).
 * 
 * Les rôles sont strictement alignés sur le modèle de sécurité du backend Symfony.
 */

import type { User } from "./api";

/**
 * Constantes des rôles de sécurité utilisés dans l'écosystème CINAF.
 * Alignées sur la hiérarchie de sécurité Symfony Security (`security.yaml`).
 */
export const ROLES = {
  /** Utilisateur standard inscrit (droit de base) */
  USER: "ROLE_USER",
  /** Utilisateur disposant d'un abonnement actif payant */
  ABONNE: "ROLE_ABONNE",
  /** Producteur / Créateur possédant un studio pour téléverser des œuvres */
  CREATEUR: "ROLE_CREATEUR",
  /** Modérateur pouvant valider/rejeter les œuvres soumises */
  MODERATEUR: "ROLE_MODERATEUR",
  /** Administrateur système avec privilèges globaux */
  ADMIN: "ROLE_ADMIN",
} as const;

/**
 * Type TypeScript représentant l'une des chaînes de rôles valides.
 */
export type RoleName = (typeof ROLES)[keyof typeof ROLES];

/**
 * Vérifie de manière sécurisée si un utilisateur possède un rôle spécifique.
 * 
 * @param user - L'objet utilisateur courant (peut être null ou undefined si non connecté)
 * @param role - La chaîne de rôle recherchée (ex: 'ROLE_ADMIN')
 * @returns `true` si l'utilisateur possède le rôle spécifié dans son tableau `roles`, sinon `false`.
 */
export function hasRole(
  user: User | null | undefined,
  role: string,
): boolean {
  return Array.isArray(user?.roles) && user!.roles.includes(role);
}

/**
 * Vérifie si l'utilisateur possède les privilèges d'administrateur système (`ROLE_ADMIN`).
 * Utilisé pour conditionner l'accès au tableau de bord d'administration globale (`/admin`).
 * 
 * @param user - L'objet utilisateur courant
 * @returns `true` si l'utilisateur est administrateur, sinon `false`.
 */
export function isAdmin(user: User | null | undefined): boolean {
  return hasRole(user, ROLES.ADMIN);
}

/**
 * Vérifie si l'utilisateur possède le rôle de créateur / studio (`ROLE_CREATEUR`).
 * Utilisé pour autoriser l'accès à l'espace Studio (`/studio`) pour la gestion des films et séries.
 * 
 * @param user - L'objet utilisateur courant
 * @returns `true` si l'utilisateur est un producteur/studio, sinon `false`.
 */
export function isStudio(user: User | null | undefined): boolean {
  return hasRole(user, ROLES.CREATEUR);
}

/**
 * Vérifie si l'utilisateur possède le rôle de modérateur (`ROLE_MODERATEUR`).
 * 
 * @param user - L'objet utilisateur courant
 * @returns `true` si l'utilisateur est modérateur, sinon `false`.
 */
export function isModerator(user: User | null | undefined): boolean {
  return hasRole(user, ROLES.MODERATEUR);
}
