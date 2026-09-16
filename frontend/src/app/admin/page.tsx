"use client";

// ============================================================
// CINAF v2 — Dashboard administrateur
// Liste des sections de gestion accessibles à ROLE_ADMIN.
// ============================================================

import { useEffect, useState } from "react";
import Link from "next/link";
import { useAuth } from "@/lib/auth";
import { adminApprovals } from "@/lib/api";

interface AdminTile {
  title: string;
  description: string;
  icon: string;
  href: string;
  status: "active" | "soon";
  /** Optionnel : badge numérique (ex: compteur des approbations en attente). */
  badgeCount?: number;
}

/**
 * Tableau de bord d'accueil de l'administration CINAF (/admin).
 * 
 * Rôles et fonctionnalités :
 * - Présente les différentes tuiles d'accès rapide :
 *   - Gestion des utilisateurs (/admin/utilisateurs).
 *   - Stockage et réplication Bunny (/admin/bunny).
 *   - Modération des Films & Séries (/admin/films-series).
 *   - Approbations des premiers contenus de studios (/admin/approvals).
 *   - Demandes de retrait (/admin/films-series/withdrawals).
 * - Affiche des compteurs en temps réel (ex: nombre d'approbations de contenu en attente).
 * 
 * @returns La page d'accueil d'administration.
 */
export default function AdminDashboardPage() {
  const { user } = useAuth();
  const [pendingApprovals, setPendingApprovals] = useState<number>(0);

  // Compteur de la tuile "Approbations" — un simple GET au chargement.
  useEffect(() => {
    let cancelled = false;
    adminApprovals
      .list({ page: 1, limit: 1 })
      .then((res) => {
        if (!cancelled) setPendingApprovals(res.total);
      })
      .catch(() => {
        /* silencieux : la tuile s'affiche sans badge si l'appel échoue. */
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const tiles: AdminTile[] = [
    {
      title: "Utilisateurs",
      description: "Voir, filtrer, modifier ou supprimer les comptes inscrits.",
      icon: "bi-people-fill",
      href: "/admin/utilisateurs",
      status: "active",
    },
    {
      title: "Stockage Bunny",
      description: "Explorer les Storage Zones, parcourir images et vidéos par zone.",
      icon: "bi-hdd-network-fill",
      href: "/admin/bunny",
      status: "active",
    },
    {
      title: "Films & Séries",
      description: "Gérer tout le catalogue, modérer les contenus et les demandes de retrait.",
      icon: "bi-film",
      href: "/admin/films-series",
      status: "active",
    },
    {
      title: "Approbations",
      description:
        "Approuver ou refuser le premier contenu publié par un studio créé en self-service.",
      icon: "bi-patch-check-fill",
      href: "/admin/approvals",
      status: "active",
      badgeCount: pendingApprovals,
    },
    {
      title: "Demandes de retrait",
      description: "Approuver ou rejeter les demandes de retrait envoyées par les studios.",
      icon: "bi-shield-slash",
      href: "/admin/films-series/withdrawals",
      status: "active",
    },
    {
      title: "Statistiques",
      description: "Audience, vues, abonnements actifs, top contenus.",
      icon: "bi-graph-up-arrow",
      href: "#",
      status: "soon",
    },
  ];

  return (
    <div className="container py-4">
      <div className="mb-4">
        <h1 style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
          <i
            className="bi bi-shield-lock-fill me-2"
            style={{ color: "var(--cinaf-gold)" }}
          />
          Administration CINAF
        </h1>
        <p style={{ color: "var(--cinaf-text-muted)" }}>
          Bienvenue {user?.firstName ?? user?.email}. Choisissez une section à administrer.
        </p>
      </div>

      <div className="row g-3">
        {tiles.map((tile) => {
          const isActive = tile.status === "active";
          const card = (
            <div
              className="h-100 p-4 d-flex flex-column"
              style={{
                background: "var(--cinaf-surface)",
                border: "1px solid var(--cinaf-border)",
                borderRadius: 10,
                opacity: isActive ? 1 : 0.5,
                cursor: isActive ? "pointer" : "not-allowed",
                transition: "transform 0.15s ease, border-color 0.15s ease",
              }}
            >
              <div
                style={{
                  width: 48,
                  height: 48,
                  borderRadius: 10,
                  background: "linear-gradient(135deg, rgba(200,168,75,0.25), rgba(200,168,75,0.05))",
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  marginBottom: 12,
                }}
              >
                <i
                  className={`bi ${tile.icon}`}
                  style={{ color: "var(--cinaf-gold)", fontSize: "1.5rem" }}
                />
              </div>
              <h5
                className="mb-2"
                style={{ color: "var(--cinaf-text)", fontWeight: 700 }}
              >
                {tile.title}
                {!isActive && (
                  <span
                    className="badge ms-2"
                    style={{
                      background: "var(--cinaf-surface-2)",
                      color: "var(--cinaf-text-muted)",
                      fontSize: "0.65rem",
                      fontWeight: 600,
                      letterSpacing: "0.05em",
                    }}
                  >
                    BIENTÔT
                  </span>
                )}
                {isActive && tile.badgeCount !== undefined && tile.badgeCount > 0 && (
                  <span
                    className="badge ms-2"
                    style={{
                      background: "var(--cinaf-gold)",
                      color: "#000",
                      fontSize: "0.7rem",
                      fontWeight: 700,
                    }}
                  >
                    {tile.badgeCount}
                  </span>
                )}
              </h5>
              <p
                className="mb-0 small"
                style={{ color: "var(--cinaf-text-muted)", flex: 1 }}
              >
                {tile.description}
              </p>
              {isActive && (
                <div
                  className="mt-3 small"
                  style={{ color: "var(--cinaf-gold)", fontWeight: 600 }}
                >
                  Ouvrir <i className="bi bi-arrow-right ms-1" />
                </div>
              )}
            </div>
          );
          return (
            <div key={tile.title} className="col-12 col-md-6 col-lg-3">
              {isActive ? (
                <Link
                  href={tile.href}
                  className="text-decoration-none"
                  style={{ color: "inherit" }}
                >
                  {card}
                </Link>
              ) : (
                card
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}
