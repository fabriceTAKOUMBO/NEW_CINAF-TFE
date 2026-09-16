"use client";

/**
 * ============================================================
 * CINAF v2 — Vue liste paginée d'œuvres (WorksListView)
 * ============================================================
 * Composant de catalogue mutualisé servant de base pour les pages :
 * - `/films` : catalogue exclusif des longs-métrages.
 * - `/series` : catalogue exclusif des séries et saisons.
 * - `/catalogue` : vue mixte proposant des onglets de filtrage (Tous / Films / Séries).
 * 
 * Fonctionnalités intégrées :
 * - Recherche en temps réel synchronisée avec l'URL (`?q=`).
 * - Pagination serveur avec mise à jour du paramètre d'URL (`?page=`).
 * - Onglets de filtrage par format (`?kind=film` ou `?kind=serie`).
 * - Gestion complète des états d'interface : chargement, erreur, aucun résultat trouvé, grille de résultats.
 */

import { useCallback, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { discover, type DiscoverKind, type DiscoverWorkSummary } from "@/lib/api";
import SearchBar from "./SearchBar";
import Pagination from "./Pagination";
import WorkCard from "./WorkCard";

/** Nombre d'œuvres affichées par page */
const PAGE_LIMIT = 24;

/**
 * Propriétés attendues par le composant `WorksListView`.
 */
interface WorksListViewProps {
  /** Titre affiché en haut de la page */
  title: string;
  /** Classe d'icône Bootstrap Icons (ex: "bi-collection-play") */
  icon?: string;
  /** Type d'œuvre filtré : null pour mixte, "film" ou "serie" */
  kind: DiscoverKind | null;
  /** Texte d'invite du champ de recherche */
  searchPlaceholder?: string;
  /** Active l'affichage des onglets Tous / Films / Séries */
  showKindTabs?: boolean;
  /** Chemin de base pour la navigation paginée (ex: "/films") */
  basePath: string;
}

/**
 * Vue paginée générique d'exploration du catalogue avec filtres et recherche.
 * 
 * @param props - Propriétés du catalogue
 * @returns L'arborescence complète de consultation avec barre d'outils et grille
 */
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

  // En mode onglets, le kind effectif vient du paramètre d'URL (?kind=), sinon du prop
  const effectiveKind = showKindTabs ? tabKind : kind;

  const [items, setItems] = useState<DiscoverWorkSummary[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Effet de chargement des données lors des changements de recherche, filtre ou page
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

  /**
   * Met à jour les paramètres de recherche dans l'URL tout en préservant l'historique du navigateur.
   */
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
      {/* En-tête de section */}
      <div className="mb-4">
        <h1 style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
          <i className={`bi ${icon} me-2`} style={{ color: "var(--cinaf-gold)" }} />
          {title}
        </h1>
        <p style={{ color: "var(--cinaf-text-muted)" }}>
          {total} œuvre{total > 1 ? "s" : ""} disponibles en streaming HD adaptatif.
        </p>
      </div>

      {/* Barre d'outils : Recherche et onglets de sélection */}
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

      {/* État de chargement */}
      {loading && (
        <div className="text-center py-5">
          <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} role="status" />
        </div>
      )}

      {/* Alerte d'erreur */}
      {error && !loading && (
        <div
          className="alert"
          style={{ background: "#2a1414", color: "#ff8a8a", border: "1px solid #5a2020" }}
        >
          <i className="bi bi-exclamation-triangle me-2" />
          Catalogue indisponible : {error}
        </div>
      )}

      {/* État vide */}
      {!loading && !error && items.length === 0 && (
        <div className="text-center py-5" style={{ color: "var(--cinaf-text-muted)" }}>
          <i className="bi bi-search d-block mb-2" style={{ fontSize: "3rem" }} />
          {q ? `Aucune œuvre ne correspond à « ${q} ».` : "Aucune œuvre disponible."}
        </div>
      )}

      {/* Grille des résultats et pagination */}
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
