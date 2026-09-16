"use client";

// ============================================================
// CINAF v2 — Liste des séries du studio (studio)
// Miroir simplifié de /studio/films.
// ============================================================

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  studioSeries,
  type ContentStatus,
  type StudioSerie,
} from "@/lib/api";
import Pagination from "@/components/Pagination";
import StatusBadge from "@/components/studio/StatusBadge";
import WithdrawalDialog from "@/components/studio/WithdrawalDialog";

const PAGE_LIMIT = 20;

const STATUS_OPTIONS: Array<{ value: "" | ContentStatus; label: string }> = [
  { value: "", label: "Tous les statuts" },
  { value: "DRAFT", label: "Brouillon" },
  { value: "PUBLISHED", label: "Publié" },
  { value: "WITHDRAWN", label: "Retiré" },
];

/**
 * Page de gestion et de listing des séries du studio connecté.
 * 
 * Fonctionnalités :
 * - Liste paginée avec filtre par statut (`DRAFT`, `PUBLISHED`, `WITHDRAWN`).
 * - Affiche le nombre de saisons configurées.
 * - Actions de gestion :
 *   - Édition complète (`/studio/series/[id]/edit`).
 *   - Publication directe (`studioSeries.publish`).
 *   - Suppression de brouillon (`studioSeries.remove`).
 *   - Demande de retrait motivée (`WithdrawalDialog`).
 * 
 * @returns Le composant de listing des séries du studio.
 */
export default function StudioSeriesListPage() {
  const router = useRouter();
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<"" | ContentStatus>("");
  const [items, setItems] = useState<StudioSerie[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [pendingId, setPendingId] = useState<string | null>(null);
  const [withdrawTarget, setWithdrawTarget] = useState<StudioSerie | null>(null);

  const reload = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await studioSeries.list({
        page,
        limit: PAGE_LIMIT,
        status: status || undefined,
      });
      setItems(res.data);
      setTotal(res.total);
    } catch (err: unknown) {
      setError((err as { message?: string })?.message ?? "Erreur de chargement.");
      setItems([]);
      setTotal(0);
    } finally {
      setLoading(false);
    }
  }, [page, status]);

  useEffect(() => {
    reload();
  }, [reload]);

  async function handlePublish(serie: StudioSerie) {
    if (!confirm(`Publier « ${serie.title} » ?`)) return;
    setPendingId(serie.id);
    try {
      await studioSeries.publish(serie.id);
      await reload();
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Publication impossible.");
    } finally {
      setPendingId(null);
    }
  }

  async function handleDelete(serie: StudioSerie) {
    if (!confirm(`Supprimer le brouillon « ${serie.title} » ?`)) return;
    setPendingId(serie.id);
    try {
      await studioSeries.remove(serie.id);
      await reload();
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Suppression impossible.");
    } finally {
      setPendingId(null);
    }
  }

  async function submitWithdrawal(reason: string) {
    if (!withdrawTarget) return;
    await studioSeries.withdraw(withdrawTarget.id, reason);
    setWithdrawTarget(null);
    await reload();
  }

  return (
    <div>
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
          <h1 className="mb-0" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
            <i className="bi bi-collection-play me-2" style={{ color: "var(--cinaf-gold)" }} />
            Mes séries
          </h1>
          <p className="mb-0 small" style={{ color: "var(--cinaf-text-muted)" }}>
            {total} série{total > 1 ? "s" : ""} dans votre catalogue.
          </p>
        </div>
        <div className="d-flex gap-2">
          <select
            value={status}
            onChange={(e) => {
              setPage(1);
              setStatus(e.target.value as "" | ContentStatus);
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
          <button
            type="button"
            onClick={() => router.push("/studio/series/new")}
            className="btn"
            style={{ background: "var(--cinaf-gold)", color: "#000", fontWeight: 600 }}
          >
            <i className="bi bi-plus-lg me-1" />
            Nouvelle série
          </button>
        </div>
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
      ) : items.length === 0 ? (
        <div className="text-center py-5" style={{ color: "var(--cinaf-text-muted)" }}>
          <i className="bi bi-collection d-block mb-2" style={{ fontSize: "3rem" }} />
          Aucune série pour ce filtre.
        </div>
      ) : (
        <div className="table-responsive">
          <table
            className="table table-dark table-hover align-middle"
            style={{ background: "var(--cinaf-surface)" }}
          >
            <thead>
              <tr>
                <th style={{ width: 70 }}>Affiche</th>
                <th>Titre</th>
                <th>Année</th>
                <th>Saisons</th>
                <th>Statut</th>
                <th className="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              {items.map((serie) => (
                <tr key={serie.id}>
                  <td>
                    {serie.poster ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        src={serie.poster}
                        alt={serie.title}
                        style={{
                          width: 48,
                          height: 64,
                          objectFit: "cover",
                          borderRadius: 4,
                          background: "var(--cinaf-surface-2)",
                        }}
                      />
                    ) : (
                      <div
                        style={{
                          width: 48,
                          height: 64,
                          background: "var(--cinaf-surface-2)",
                          borderRadius: 4,
                          display: "flex",
                          alignItems: "center",
                          justifyContent: "center",
                          color: "var(--cinaf-text-muted)",
                        }}
                      >
                        <i className="bi bi-image" />
                      </div>
                    )}
                  </td>
                  <td>
                    <Link
                      href={`/studio/series/${serie.id}/edit`}
                      style={{ color: "var(--cinaf-text)", textDecoration: "none", fontWeight: 600 }}
                    >
                      {serie.title}
                    </Link>
                  </td>
                  <td style={{ color: "var(--cinaf-text-muted)" }}>{serie.year}</td>
                  <td style={{ color: "var(--cinaf-text-muted)" }}>{serie.nbSeasons ?? 0}</td>
                  <td>
                    <StatusBadge status={serie.status} />
                  </td>
                  <td className="text-end">
                    <div className="d-inline-flex gap-1">
                      <Link
                        href={`/studio/series/${serie.id}/edit`}
                        className="btn btn-sm btn-cinaf-outline"
                        title="Éditer"
                      >
                        <i className="bi bi-pencil" />
                      </Link>
                      {serie.status === "DRAFT" && (
                        <>
                          <button
                            type="button"
                            className="btn btn-sm"
                            disabled={pendingId === serie.id}
                            onClick={() => handlePublish(serie)}
                            title="Publier"
                            style={{ background: "#1f6337", color: "#fff", border: "none" }}
                          >
                            <i className="bi bi-broadcast" />
                          </button>
                          <button
                            type="button"
                            className="btn btn-sm"
                            disabled={pendingId === serie.id}
                            onClick={() => handleDelete(serie)}
                            title="Supprimer"
                            style={{ background: "#3a1414", color: "#ff8a8a", border: "none" }}
                          >
                            <i className="bi bi-trash" />
                          </button>
                        </>
                      )}
                      {serie.status === "PUBLISHED" && (
                        <button
                          type="button"
                          className="btn btn-sm"
                          disabled={pendingId === serie.id}
                          onClick={() => setWithdrawTarget(serie)}
                          title="Demander un retrait"
                          style={{ background: "#5a3520", color: "#f0c080", border: "none" }}
                        >
                          <i className="bi bi-shield-slash" />
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
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

      <WithdrawalDialog
        open={withdrawTarget !== null}
        title={withdrawTarget ? `Retrait de « ${withdrawTarget.title} »` : undefined}
        onClose={() => setWithdrawTarget(null)}
        onSubmit={submitWithdrawal}
      />
    </div>
  );
}
