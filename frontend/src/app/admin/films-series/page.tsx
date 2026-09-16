"use client";

// ============================================================
// CINAF v2 — Admin / Liste globale Films & Séries
// 2 onglets (Films / Séries) avec filtres status + studio +
// recherche par titre. Actions : Éditer, Supprimer.
// ============================================================

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import {
  adminFilms,
  adminSeries,
  adminStudios,
  adminWithdrawals,
  type AdminFilm,
  type AdminSerie,
  type ContentStatus,
  type Studio,
} from "@/lib/api";
import Pagination from "@/components/Pagination";
import StatusBadge from "@/components/studio/StatusBadge";

const PAGE_LIMIT = 20;

const STATUS_OPTIONS: Array<{ value: "" | ContentStatus; label: string }> = [
  { value: "", label: "Tous les statuts" },
  { value: "DRAFT", label: "Brouillon" },
  { value: "PUBLISHED", label: "Publié" },
  { value: "WITHDRAWN", label: "Retiré" },
];

type Tab = "films" | "series";

/**
 * Page de modération et de supervision globale de tous les films et séries de la plateforme.
 * 
 * Fonctionnalités administrateur :
 * - Double onglet Films / Séries.
 * - Filtres multicritères : statut (Brouillon, Publié, Retiré), studio producteur et recherche textuelle.
 * - Affichage du badge de demandes de retrait en attente.
 * - Actions de suppression et d'édition pour les administrateurs.
 * 
 * @returns La page de modération globale du catalogue.
 */
export default function AdminFilmsSeriesPage() {
  const [tab, setTab] = useState<Tab>("films");
  const [studios, setStudios] = useState<Studio[]>([]);
  const [pendingCount, setPendingCount] = useState<number>(0);

  // Filtres communs
  const [status, setStatus] = useState<"" | ContentStatus>("");
  const [studioId, setStudioId] = useState<string>("");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);

  // Données
  const [films, setFilms] = useState<AdminFilm[]>([]);
  const [series, setSeries] = useState<AdminSerie[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [pendingId, setPendingId] = useState<string | null>(null);

  // Charger studios + count retraits une seule fois
  useEffect(() => {
    (async () => {
      try {
        const [s, w] = await Promise.all([
          adminStudios.list(),
          adminWithdrawals.list({ status: "PENDING", page: 1, limit: 1 }),
        ]);
        setStudios(s.data ?? []);
        setPendingCount(w.total ?? 0);
      } catch {
        // tolérer l'échec — UI dégradée mais fonctionnelle
      }
    })();
  }, []);

  const reload = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params = {
        page,
        limit: PAGE_LIMIT,
        status: status || undefined,
        studioId: studioId || undefined,
        search: search || undefined,
      };
      if (tab === "films") {
        const res = await adminFilms.list(params);
        setFilms(res.data);
        setSeries([]);
        setTotal(res.total);
      } else {
        const res = await adminSeries.list(params);
        setSeries(res.data);
        setFilms([]);
        setTotal(res.total);
      }
    } catch (err: unknown) {
      setError((err as { message?: string })?.message ?? "Erreur de chargement.");
      setFilms([]);
      setSeries([]);
      setTotal(0);
    } finally {
      setLoading(false);
    }
  }, [page, status, studioId, search, tab]);

  useEffect(() => {
    reload();
  }, [reload]);

  function submitSearch(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setSearch(searchInput.trim());
    setPage(1);
  }

  async function deleteFilm(film: AdminFilm) {
    if (!confirm(`Supprimer définitivement « ${film.title} » ?`)) return;
    setPendingId(film.id);
    try {
      await adminFilms.remove(film.id);
      await reload();
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Suppression impossible.");
    } finally {
      setPendingId(null);
    }
  }

  async function deleteSerie(serie: AdminSerie) {
    if (!confirm(`Supprimer définitivement « ${serie.title} » ?`)) return;
    setPendingId(serie.id);
    try {
      await adminSeries.remove(serie.id);
      await reload();
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Suppression impossible.");
    } finally {
      setPendingId(null);
    }
  }

  function changeTab(t: Tab) {
    setTab(t);
    setPage(1);
  }

  return (
    <div className="container py-4">
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <div>
          <Link
            href="/admin"
            className="d-inline-block mb-2"
            style={{ color: "var(--cinaf-text-muted)", textDecoration: "none" }}
          >
            <i className="bi bi-arrow-left me-1" />
            Tableau de bord
          </Link>
          <h1 style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
            <i className="bi bi-film me-2" style={{ color: "var(--cinaf-gold)" }} />
            Films &amp; Séries
          </h1>
          <p className="mb-0" style={{ color: "var(--cinaf-text-muted)" }}>
            {total} {tab === "films" ? "film" : "série"}
            {total > 1 ? "s" : ""} au total.
          </p>
        </div>
        <Link
          href="/admin/films-series/withdrawals"
          className="btn"
          style={{
            background: pendingCount > 0 ? "#5a3520" : "var(--cinaf-surface-2)",
            color: pendingCount > 0 ? "#f0c080" : "var(--cinaf-text)",
            border: "1px solid var(--cinaf-border)",
            fontWeight: 600,
          }}
        >
          <i className="bi bi-shield-slash me-1" />
          Demandes de retrait
          {pendingCount > 0 && (
            <span
              className="badge ms-2"
              style={{ background: "var(--cinaf-gold)", color: "#000" }}
            >
              {pendingCount}
            </span>
          )}
        </Link>
      </div>

      {/* Onglets */}
      <ul
        className="nav nav-tabs mb-3"
        style={{ borderBottomColor: "var(--cinaf-border)" }}
      >
        <li className="nav-item">
          <button
            type="button"
            className={`nav-link ${tab === "films" ? "active" : ""}`}
            onClick={() => changeTab("films")}
            style={
              tab === "films"
                ? {
                    background: "var(--cinaf-surface)",
                    color: "var(--cinaf-gold)",
                    borderColor: "var(--cinaf-border) var(--cinaf-border) var(--cinaf-surface)",
                    fontWeight: 600,
                  }
                : { color: "var(--cinaf-text)" }
            }
          >
            <i className="bi bi-film me-1" />
            Films
          </button>
        </li>
        <li className="nav-item">
          <button
            type="button"
            className={`nav-link ${tab === "series" ? "active" : ""}`}
            onClick={() => changeTab("series")}
            style={
              tab === "series"
                ? {
                    background: "var(--cinaf-surface)",
                    color: "var(--cinaf-gold)",
                    borderColor: "var(--cinaf-border) var(--cinaf-border) var(--cinaf-surface)",
                    fontWeight: 600,
                  }
                : { color: "var(--cinaf-text)" }
            }
          >
            <i className="bi bi-collection-play me-1" />
            Séries
          </button>
        </li>
      </ul>

      {/* Filtres */}
      <div className="d-flex flex-wrap gap-2 align-items-end mb-3">
        <form onSubmit={submitSearch} className="d-flex" style={{ flex: "1 1 280px", maxWidth: 360 }}>
          <input
            type="text"
            className="form-control"
            placeholder="Rechercher par titre…"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            style={{
              background: "var(--cinaf-surface)",
              color: "var(--cinaf-text)",
              border: "1px solid var(--cinaf-border)",
            }}
          />
          <button
            type="submit"
            className="btn ms-2"
            style={{ background: "var(--cinaf-gold)", color: "#000", fontWeight: 600 }}
          >
            <i className="bi bi-search" />
          </button>
        </form>
        <select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value as "" | ContentStatus);
            setPage(1);
          }}
          className="form-select"
          style={{
            background: "var(--cinaf-surface)",
            color: "var(--cinaf-text)",
            border: "1px solid var(--cinaf-border)",
            maxWidth: 200,
          }}
        >
          {STATUS_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
        <select
          value={studioId}
          onChange={(e) => {
            setStudioId(e.target.value);
            setPage(1);
          }}
          className="form-select"
          style={{
            background: "var(--cinaf-surface)",
            color: "var(--cinaf-text)",
            border: "1px solid var(--cinaf-border)",
            maxWidth: 240,
          }}
        >
          <option value="">Tous les studios</option>
          {studios.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </select>
        {(status || studioId || search) && (
          <button
            type="button"
            onClick={() => {
              setStatus("");
              setStudioId("");
              setSearch("");
              setSearchInput("");
              setPage(1);
            }}
            className="btn btn-link btn-sm"
            style={{ color: "var(--cinaf-text-muted)" }}
          >
            Réinitialiser
          </button>
        )}
      </div>

      {error && (
        <div
          className="alert"
          style={{ background: "#2a1414", color: "#ff8a8a", border: "1px solid #5a2020" }}
        >
          <i className="bi bi-exclamation-triangle me-2" />
          {error}
        </div>
      )}

      {loading ? (
        <div className="text-center py-5">
          <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} />
        </div>
      ) : tab === "films" ? (
        <FilmsTable items={films} onDelete={deleteFilm} pendingId={pendingId} />
      ) : (
        <SeriesTable items={series} onDelete={deleteSerie} pendingId={pendingId} />
      )}

      {!loading && total > PAGE_LIMIT && (
        <div className="mt-3 d-flex justify-content-center">
          <Pagination
            currentPage={page}
            totalItems={total}
            itemsPerPage={PAGE_LIMIT}
            onPageChange={setPage}
          />
        </div>
      )}
    </div>
  );
}

function FilmsTable({
  items,
  onDelete,
  pendingId,
}: {
  items: AdminFilm[];
  onDelete: (f: AdminFilm) => void;
  pendingId: string | null;
}) {
  if (items.length === 0) {
    return (
      <div className="text-center py-5" style={{ color: "var(--cinaf-text-muted)" }}>
        <i className="bi bi-film d-block mb-2" style={{ fontSize: "3rem" }} />
        Aucun film ne correspond aux filtres.
      </div>
    );
  }
  return (
    <div className="table-responsive">
      <table
        className="table table-dark table-hover align-middle"
        style={{ background: "var(--cinaf-surface)" }}
      >
        <thead>
          <tr>
            <th>Titre</th>
            <th>Studio</th>
            <th>Année</th>
            <th>Statut</th>
            <th>Créé le</th>
            <th className="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          {items.map((f) => (
            <tr key={f.id}>
              <td>
                <span style={{ color: "var(--cinaf-text)", fontWeight: 600 }}>{f.title}</span>
              </td>
              <td style={{ color: "var(--cinaf-text-muted)" }}>
                {f.studio?.name ?? "—"}
              </td>
              <td style={{ color: "var(--cinaf-text-muted)" }}>{f.year}</td>
              <td>
                <StatusBadge status={f.status} />
              </td>
              <td style={{ color: "var(--cinaf-text-muted)", fontSize: "0.85rem" }}>
                {new Date(f.createdAt).toLocaleDateString("fr-FR")}
              </td>
              <td className="text-end">
                <button
                  type="button"
                  className="btn btn-sm"
                  onClick={() => onDelete(f)}
                  disabled={pendingId === f.id}
                  style={{ background: "#3a1414", color: "#ff8a8a", border: "none" }}
                >
                  <i className="bi bi-trash" />
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function SeriesTable({
  items,
  onDelete,
  pendingId,
}: {
  items: AdminSerie[];
  onDelete: (s: AdminSerie) => void;
  pendingId: string | null;
}) {
  if (items.length === 0) {
    return (
      <div className="text-center py-5" style={{ color: "var(--cinaf-text-muted)" }}>
        <i className="bi bi-collection-play d-block mb-2" style={{ fontSize: "3rem" }} />
        Aucune série ne correspond aux filtres.
      </div>
    );
  }
  return (
    <div className="table-responsive">
      <table
        className="table table-dark table-hover align-middle"
        style={{ background: "var(--cinaf-surface)" }}
      >
        <thead>
          <tr>
            <th>Titre</th>
            <th>Studio</th>
            <th>Année</th>
            <th>Saisons</th>
            <th>Statut</th>
            <th>Créée le</th>
            <th className="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          {items.map((s) => (
            <tr key={s.id}>
              <td>
                <span style={{ color: "var(--cinaf-text)", fontWeight: 600 }}>{s.title}</span>
              </td>
              <td style={{ color: "var(--cinaf-text-muted)" }}>
                {s.studio?.name ?? "—"}
              </td>
              <td style={{ color: "var(--cinaf-text-muted)" }}>{s.year}</td>
              <td style={{ color: "var(--cinaf-text-muted)" }}>{s.nbSeasons ?? 0}</td>
              <td>
                <StatusBadge status={s.status} />
              </td>
              <td style={{ color: "var(--cinaf-text-muted)", fontSize: "0.85rem" }}>
                {new Date(s.createdAt).toLocaleDateString("fr-FR")}
              </td>
              <td className="text-end">
                <button
                  type="button"
                  className="btn btn-sm"
                  onClick={() => onDelete(s)}
                  disabled={pendingId === s.id}
                  style={{ background: "#3a1414", color: "#ff8a8a", border: "none" }}
                >
                  <i className="bi bi-trash" />
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
