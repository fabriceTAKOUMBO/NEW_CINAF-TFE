"use client";

// ============================================================
// CINAF v2 — Layout studio partagé
// Garde de rôle ROLE_CREATEUR + barre de navigation latérale.
// Calque admin/layout.tsx (pattern de redirection identique).
// ============================================================

import { useEffect } from "react";
import { useRouter, usePathname } from "next/navigation";
import Link from "next/link";
import { useAuth } from "@/lib/auth";
import { isStudio } from "@/lib/auth-helpers";

const NAV: Array<{ href: string; label: string; icon: string }> = [
  { href: "/studio", label: "Tableau de bord", icon: "bi-speedometer2" },
  { href: "/studio/films", label: "Films", icon: "bi-film" },
  { href: "/studio/series", label: "Séries", icon: "bi-collection-play" },
  { href: "/studio/profil", label: "Modifier le profil", icon: "bi-pencil-square" },
];

export default function StudioLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const { user, isAuthenticated, isLoading } = useAuth();

  useEffect(() => {
    if (isLoading) return;
    if (!isAuthenticated) {
      router.replace("/login?next=/studio");
      return;
    }
    if (!isStudio(user)) {
      router.replace("/");
    }
  }, [isLoading, isAuthenticated, user, router]);

  if (isLoading || !isAuthenticated || !isStudio(user)) {
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

  return (
    <div className="container-fluid py-4">
      <div className="row g-3">
        <aside className="col-12 col-md-3 col-lg-2">
          <div
            className="p-3 d-flex flex-column"
            style={{
              background: "var(--cinaf-surface)",
              border: "1px solid var(--cinaf-border)",
              borderRadius: 10,
              position: "sticky",
              top: 16,
              maxHeight: "calc(100vh - 32px)",
              overflowY: "auto",
            }}
          >
            <div
              className="mb-3 pb-3"
              style={{ borderBottom: "1px solid var(--cinaf-border)" }}
            >
              <div
                className="small"
                style={{ color: "var(--cinaf-text-muted)", letterSpacing: "0.05em" }}
              >
                ESPACE STUDIO
              </div>
              <div style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
                {user?.firstName} {user?.lastName}
              </div>
            </div>
            <nav className="nav flex-column gap-1">
              {NAV.map((item) => {
                const active =
                  item.href === "/studio"
                    ? pathname === "/studio"
                    : pathname?.startsWith(item.href);
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    className="nav-link d-flex align-items-center gap-2"
                    style={{
                      color: active ? "var(--cinaf-gold)" : "var(--cinaf-text)",
                      background: active
                        ? "rgba(200,168,75,0.10)"
                        : "transparent",
                      borderRadius: 8,
                      padding: "0.5rem 0.75rem",
                      fontWeight: active ? 600 : 500,
                    }}
                  >
                    <i className={`bi ${item.icon}`} />
                    {item.label}
                  </Link>
                );
              })}
            </nav>
            <div
              className="mt-auto pt-3"
              style={{ borderTop: "1px solid var(--cinaf-border)" }}
            >
              <Link
                href="/"
                className="nav-link d-flex align-items-center gap-2"
                style={{
                  color: "var(--cinaf-text-muted)",
                  borderRadius: 8,
                  padding: "0.5rem 0.75rem",
                  fontWeight: 500,
                }}
              >
                <i className="bi bi-box-arrow-left" />
                Retour à la plateforme
              </Link>
            </div>
          </div>
        </aside>
        <main className="col-12 col-md-9 col-lg-10">{children}</main>
      </div>
    </div>
  );
}
