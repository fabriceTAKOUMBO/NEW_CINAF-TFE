"use client";

// ============================================================
// CINAF v2 — Page publique /studios : grille paginée
// ============================================================

import { useEffect, useState } from "react";
import { studios, type StudioPublic } from "@/lib/api";
import StudiosGrid from "@/components/StudiosGrid";
import Pagination from "@/components/Pagination";

// 24 studios par page — cohérent avec les autres listes (films, séries).
const STUDIOS_PER_PAGE = 24;

/**
 * Page d'index publique des studios CINAF (vue "annuaire").
 * Charge la page courante depuis `studios.list()` et délègue le rendu
 * à `StudiosGrid` + `Pagination`.
 */
export default function StudiosIndexPage() {
  const [items, setItems] = useState<StudioPublic[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Récupère la page de studios à chaque changement de `page`.
  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    studios
      .list({ page, itemsPerPage: STUDIOS_PER_PAGE })
      .then((res) => {
        if (cancelled) return;
        setItems(res.data);
        setTotal(res.total);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const msg = (err as { message?: string })?.message ?? "Erreur inconnue";
        setError(msg);
        setItems([]);
        setTotal(0);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [page]);

  return (
    <div className="container py-4">
      {/* En-tête de page */}
      <div className="mb-4">
        <h1 style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
          <i
            className="bi bi-collection-fill me-2"
            style={{ color: "var(--cinaf-gold)" }}
          />
          Studios
        </h1>
        <p style={{ color: "var(--cinaf-text-muted)" }}>
          {total} studio{total > 1 ? "s" : ""} producteur
          {total > 1 ? "s" : ""} sur la plateforme.
        </p>
      </div>

      {/* Spinner initial (premier chargement) */}
      {loading && items.length === 0 && <StudiosGrid studios={[]} loading />}

      {/* Erreur réseau / serveur */}
      {error && !loading && (
        <div
          className="alert"
          style={{
            background: "#2a1414",
            color: "#ff8a8a",
            border: "1px solid #5a2020",
          }}
        >
          <i className="bi bi-exclamation-triangle me-2" />
          Studios indisponibles : {error}
        </div>
      )}

      {/* Empty state : aucun studio public sur la plateforme */}
      {!loading && !error && items.length === 0 && (
        <div
          className="text-center py-5"
          style={{ color: "var(--cinaf-text-muted)" }}
        >
          <i
            className="bi bi-collection-fill d-block mb-2"
            style={{ fontSize: "3rem" }}
          />
          Aucun studio public pour le moment.
        </div>
      )}

      {/* Grille + pagination */}
      {!error && items.length > 0 && (
        <>
          <StudiosGrid studios={items} loading={loading} />
          {total > STUDIOS_PER_PAGE && (
            <Pagination
              currentPage={page}
              totalItems={total}
              itemsPerPage={STUDIOS_PER_PAGE}
              onPageChange={(p) => setPage(p)}
            />
          )}
        </>
      )}
    </div>
  );
}
