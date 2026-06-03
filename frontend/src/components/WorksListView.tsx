"use client";

// ============================================================
// CINAF v2 — Vue liste paginée d'œuvres du catalogue Bunny.
// Mutualisée entre les pages /films, /series et /catalogue.
// ============================================================

import { useCallback, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { discover, type DiscoverKind, type DiscoverWorkSummary } from "@/lib/api";
import SearchBar from "./SearchBar";
import Pagination from "./Pagination";
import WorkCard from "./WorkCard";

const PAGE_LIMIT = 24;

interface WorksListViewProps {
  title: string;
  icon?: string;
  /** kind=null → catalogue mixte ; sinon filtre par type (film | serie). */
  kind: DiscoverKind | null;
  /** Texte du placeholder de recherche. */
  searchPlaceholder?: string;
  /** Permet de rendre des onglets Tous/Films/Séries au-dessus de la liste. */
  showKindTabs?: boolean;
  /** Préfixe de l'URL pour la pagination (ex: "/films"). */
  basePath: string;
}

export default function WorksListView({
  title,
  icon = "bi-collection-play",
  kind,
  searchPlaceholder = "Rechercher une œuvre…",
  showKindTabs = false,
  basePath,
}: WorksListViewProps) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const q = searchParams.get("q") || "";
  const page = Math.max(1, Number(searchParams.get("page")) || 1);
  const tabKind = (searchParams.get("kind") as DiscoverKind | null) || null;

  // En mode tabs, le kind effectif vient de l'URL (?kind=). Sinon, du prop.
  const effectiveKind = showKindTabs ? tabKind : kind;

  const [items, setItems] = useState<DiscoverWorkSummary[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    discover
      .list({ q: q || undefined, kind: effectiveKind ?? undefined, page, limit: PAGE_LIMIT })
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
  }, [q, effectiveKind, page]);

  const updateParams = useCallback(
    (next: Record<string, string | undefined>) => {
      const params = new URLSearchParams(searchParams.toString());
      Object.entries(next).forEach(([k, v]) => {
        if (v === undefined || v === "") params.delete(k);
        else params.set(k, v);
      });
      router.push(`${basePath}${params.toString() ? `?${params.toString()}` : ""}`);
    },
    [router, searchParams, basePath],
  );

  return (
    <div className="container py-4">
      <div className="mb-4">
        <h1 style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
          <i className={`bi ${icon} me-2`} style={{ color: "var(--cinaf-gold)" }} />
          {title}
        </h1>
        <p style={{ color: "var(--cinaf-text-muted)" }}>
          {total} œuvre{total > 1 ? "s" : ""} disponibles en streaming HD adaptatif.
        </p>
      </div>

      <div className="d-flex flex-wrap gap-3 align-items-center mb-4">
        <div style={{ flex: "1 1 280px", maxWidth: 480 }}>
          <SearchBar
            mode="inline"
            defaultValue={q}
            onChange={(value) => updateParams({ q: value || undefined, page: undefined })}
            placeholder={searchPlaceholder}
          />
        </div>

        {showKindTabs && (
          <ul className="nav nav-pills" role="tablist">
            {(
              [
                { id: null, label: "Tous", icon: "bi-grid" },
                { id: "film" as DiscoverKind, label: "Films", icon: "bi-film" },
                { id: "serie" as DiscoverKind, label: "Séries", icon: "bi-collection-play" },
              ] as { id: DiscoverKind | null; label: string; icon: string }[]
            ).map((t) => {
              const isActive = effectiveKind === t.id;
              return (
                <li key={t.label} className="nav-item">
                  <button
                    type="button"
                    className={`nav-link ${isActive ? "active" : ""}`}
                    style={{
                      color: isActive ? "#000" : "var(--cinaf-text)",
                      background: isActive ? "var(--cinaf-gold)" : "transparent",
                      border: "1px solid var(--cinaf-border)",
                    }}
                    onClick={() => updateParams({ kind: t.id ?? undefined, page: undefined })}
                  >
                    <i className={`bi ${t.icon} me-1`} />
                    {t.label}
                  </button>
                </li>
              );
            })}
          </ul>
        )}
      </div>

      {loading && (
        <div className="text-center py-5">
          <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} role="status" />
        </div>
      )}

      {error && !loading && (
        <div
          className="alert"
          style={{ background: "#2a1414", color: "#ff8a8a", border: "1px solid #5a2020" }}
        >
          <i className="bi bi-exclamation-triangle me-2" />
          Catalogue indisponible : {error}
        </div>
      )}

      {!loading && !error && items.length === 0 && (
        <div className="text-center py-5" style={{ color: "var(--cinaf-text-muted)" }}>
          <i className="bi bi-search d-block mb-2" style={{ fontSize: "3rem" }} />
          {q ? `Aucune œuvre ne correspond à « ${q} ».` : "Aucune œuvre disponible."}
        </div>
      )}

      {!loading && !error && items.length > 0 && (
        <>
          <div className="row g-3">
            {items.map((w) => (
              <div key={w.slug} className="col-6 col-md-4 col-lg-3">
                <WorkCard work={w} />
              </div>
            ))}
          </div>

          {total > PAGE_LIMIT && (
            <div className="mt-4 d-flex justify-content-center">
              <Pagination
                currentPage={page}
                totalItems={total}
                itemsPerPage={PAGE_LIMIT}
                onPageChange={(p) => updateParams({ page: p > 1 ? String(p) : undefined })}
              />
            </div>
          )}
        </>
      )}
    </div>
  );
}
