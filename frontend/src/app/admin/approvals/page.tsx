"use client";

// ============================================================
// CINAF v2 — Admin / File d'approbation des premiers contenus
// Liste paginée des films + séries en PENDING_APPROVAL. Actions :
// approuver (passe en PUBLISHED + valide le studio) ou rejeter
// (retour DRAFT, studio non validé). Pas de modale dédiée : le
// rejet accepte un texte libre via prompt navigateur (volume
// attendu très faible, on garde la page simple).
// ============================================================

import { useCallback, useEffect, useState } from "react";
import {
  adminApprovals,
  type AdminApprovalItem,
  type ApiError,
} from "@/lib/api";
import Pagination from "@/components/Pagination";

const PAGE_LIMIT = 20;

/**
 * File d'approbation et de modération des premières productions de studios.
 * 
 * Règle métier CINAF :
 * - Tout nouveau studio créé en self-service doit faire approuver son premier contenu par un admin.
 * - L'approbation passe le contenu en `PUBLISHED` et valide définitivement le studio (`isValidated=true`).
 * - Le refus renvoie le contenu en `DRAFT` avec un motif facultatif, sans valider le studio.
 * 
 * @returns La page de modération des approbations en attente.
 */
export default function AdminApprovalsPage() {
  const [items, setItems] = useState<AdminApprovalItem[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);

  const reload = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await adminApprovals.list({ page, limit: PAGE_LIMIT });
      setItems(res.data);
      setTotal(res.total);
    } catch (err) {
      const apiErr = err as ApiError;
      setError(apiErr?.message ?? "Erreur de chargement.");
      setItems([]);
      setTotal(0);
    } finally {
      setLoading(false);
    }
  }, [page]);

  useEffect(() => {
    reload();
  }, [reload]);

  async function handleApprove(item: AdminApprovalItem) {
    setBusyId(item.id);
    try {
      if (item.kind === "film") {
        await adminApprovals.approveFilm(item.id);
      } else {
        await adminApprovals.approveSerie(item.id);
      }
      await reload();
    } catch (err) {
      const apiErr = err as ApiError;
      alert(apiErr?.message ?? "Échec de l'approbation.");
    } finally {
      setBusyId(null);
    }
  }

  async function handleReject(item: AdminApprovalItem) {
    const reason = window.prompt(
      "Motif du refus (optionnel) :",
      "",
    );
    if (reason === null) {
      return; // Annulation utilisateur
    }
    setBusyId(item.id);
    try {
      if (item.kind === "film") {
        await adminApprovals.rejectFilm(item.id, reason || undefined);
      } else {
        await adminApprovals.rejectSerie(item.id, reason || undefined);
      }
      await reload();
    } catch (err) {
      const apiErr = err as ApiError;
      alert(apiErr?.message ?? "Échec du refus.");
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="container py-4">
      <div className="mb-4">
        <h1 style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
          <i
            className="bi bi-patch-check-fill me-2"
            style={{ color: "var(--cinaf-gold)" }}
          />
          Approbations en attente
        </h1>
        <p style={{ color: "var(--cinaf-text-muted)" }}>
          Premier contenu publié par les studios créés en self-service. Une
          fois approuvé, le studio est marqué comme validé et ses contenus
          suivants partent directement en ligne.
        </p>
      </div>

      {error && (
        <div
          className="alert"
          style={{
            background: "#2a1414",
            color: "#ff8a8a",
            border: "1px solid #5a2020",
          }}
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
        <div
          className="text-center py-5"
          style={{
            background: "var(--cinaf-surface)",
            border: "1px solid var(--cinaf-border)",
            borderRadius: 10,
            color: "var(--cinaf-text-muted)",
          }}
        >
          <i
            className="bi bi-inbox"
            style={{ fontSize: "2rem", display: "block", marginBottom: 12 }}
          />
          Aucun contenu en attente d&apos;approbation.
        </div>
      ) : (
        <div
          style={{
            background: "var(--cinaf-surface)",
            border: "1px solid var(--cinaf-border)",
            borderRadius: 10,
            overflow: "hidden",
          }}
        >
          <table
            className="table mb-0"
            style={{ color: "var(--cinaf-text)" }}
          >
            <thead>
              <tr style={{ background: "var(--cinaf-surface-2)" }}>
                <th>Type</th>
                <th>Titre</th>
                <th>Studio</th>
                <th>Année</th>
                <th>Créé le</th>
                <th className="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => {
                const isBusy = busyId === item.id;
                return (
                  <tr key={`${item.kind}-${item.id}`}>
                    <td>
                      <span
                        className="badge"
                        style={{
                          background: "rgba(200,168,75,0.18)",
                          color: "var(--cinaf-gold)",
                          border: "1px solid rgba(200,168,75,0.4)",
                          textTransform: "uppercase",
                          fontSize: "0.7rem",
                        }}
                      >
                        {item.kind === "film" ? "Film" : "Série"}
                      </span>
                    </td>
                    <td>
                      <div style={{ fontWeight: 600 }}>{item.title}</div>
                      <div
                        className="small"
                        style={{ color: "var(--cinaf-text-muted)" }}
                      >
                        {item.slug}
                      </div>
                    </td>
                    <td>
                      {item.studio ? (
                        <div>
                          <div>{item.studio.name}</div>
                          <div
                            className="small"
                            style={{ color: "var(--cinaf-text-muted)" }}
                          >
                            {item.studio.slug}
                          </div>
                        </div>
                      ) : (
                        <span style={{ color: "var(--cinaf-text-muted)" }}>—</span>
                      )}
                    </td>
                    <td>{item.year}</td>
                    <td>
                      <span
                        className="small"
                        style={{ color: "var(--cinaf-text-muted)" }}
                      >
                        {new Date(item.createdAt).toLocaleDateString("fr-FR")}
                      </span>
                    </td>
                    <td className="text-end">
                      <div className="d-flex gap-2 justify-content-end">
                        <button
                          type="button"
                          className="btn btn-sm"
                          style={{
                            background: "rgba(31,99,55,0.25)",
                            color: "#8fd68f",
                            border: "1px solid rgba(143,214,143,0.4)",
                            fontWeight: 600,
                          }}
                          onClick={() => handleApprove(item)}
                          disabled={isBusy}
                        >
                          <i className="bi bi-check-lg me-1" />
                          Approuver
                        </button>
                        <button
                          type="button"
                          className="btn btn-sm"
                          style={{
                            background: "rgba(122,32,32,0.25)",
                            color: "#ff8a8a",
                            border: "1px solid rgba(255,138,138,0.4)",
                            fontWeight: 600,
                          }}
                          onClick={() => handleReject(item)}
                          disabled={isBusy}
                        >
                          <i className="bi bi-x-lg me-1" />
                          Refuser
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {total > PAGE_LIMIT && (
        <div className="mt-3">
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
