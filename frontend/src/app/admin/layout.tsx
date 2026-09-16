"use client";

// ============================================================
// CINAF v2 — Layout admin partagé
// Centralise la garde ROLE_ADMIN pour toutes les pages /admin/**.
// ============================================================

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { isAdmin } from "@/lib/auth-helpers";

/**
 * Layout racine du panneau d'administration CINAF (/admin/*).
 * 
 * Sécurité et Rôles :
 * - Protège l'accès à toute l'arborescence d'administration.
 * - Vérifie que l'utilisateur possède le rôle `ROLE_ADMIN` (`isAdmin(user)`).
 * - Redirige vers `/login?next=/admin` si non authentifié, ou vers `/` si non autorisé.
 * 
 * @param props.children - Les pages et sous-modules d'administration.
 * @returns Le conteneur sécurisé du back-office administrateur.
 */
export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { user, isAuthenticated, isLoading } = useAuth();

  useEffect(() => {
    if (isLoading) return;
    if (!isAuthenticated) {
      router.push("/login?next=/admin");
      return;
    }
    if (!isAdmin(user)) {
      router.push("/");
    }
  }, [isLoading, isAuthenticated, user, router]);

  if (isLoading || !isAuthenticated || !isAdmin(user)) {
    return (
      <div
        className="d-flex align-items-center justify-content-center"
        style={{ minHeight: "60vh" }}
      >
        <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} role="status">
          <span className="visually-hidden">Vérification des permissions…</span>
        </div>
      </div>
    );
  }

  return <>{children}</>;
}
