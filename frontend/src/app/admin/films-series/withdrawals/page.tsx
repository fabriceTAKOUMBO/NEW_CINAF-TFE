"use client";

// ============================================================
// CINAF v2 — Admin / File des demandes de retrait
// Onglets PENDING / APPROVED / REJECTED. Pour chaque demande
// l'admin peut approuver ou rejeter via une modale review.
// ============================================================

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import {
  adminFilms,
  adminSeries,
  adminWithdrawals,
  type WithdrawalRequest,
  type WithdrawalStatus,
} from "@/lib/api";
import Pagination from "@/components/Pagination";
import StatusBadge from "@/components/studio/StatusBadge";
import WithdrawalReviewModal from "@/components/admin/WithdrawalReviewModal";

const PAGE_LIMIT = 20;
const TABS: WithdrawalStatus[] = ["PENDING", "APPROVED", "REJECTED"];

const TAB_LABELS: Record<WithdrawalStatus, string> = {
  PENDING: "En attente",
  APPROVED: "Approuvées",
  REJECTED: "Rejetées",
};

export default function AdminWithdrawalsPage() {
  const [tab, setTab] = useState<WithdrawalStatus>("PENDING");
  const [items, setItems] = useState<WithdrawalRequest[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  // Cache titres des cibles (key = `${type}:${id}`)
  const [titles, setTitles] = useState<Record<string, string>>({});
  // Modale
  const [review, setReview] = useState<{
    id: string;
    action: "approve" | "reject";
  } | null>(null);

  const reload = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await adminWithdrawals.list({
        status: tab,
        page,
        limit: PAGE_LIMIT,
      });
      setItems(res.data);
      setTotal(res.total);

      // Charger les titres des cibles pour cet écran
      const newTitles: Record<string, string> = { ...titles };
      await Promise.all(
        res.data.map(async (w) => {
          const key = `${w.targetType}:${w.targetId}`;
          if (newTitles[key]) return;
          try {
            if (w.targetType === "film") {
              const f = await adminFilms.get(w.targetId);
              newTitles[key] = f.title;
            } else {
              const s = await adminSeries.get(w.targetId);
              newTitles[key] = s.title;
            }
          } catch {
            newTitles[key] = "(contenu introuvable)";
          }
        }),
      );
      setTitles(newTitles);
    } catch (err: unknown) {
      setError((err as { message?: string })?.message ?? "Erreur de chargement.");
      setItems([]);
      setTotal(0);
    } finally {
      setLoading(false);
    }
    // titles intentionnellement omis pour éviter une boucle ; on s'appuie sur le merge.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, page]);

  useEffect(() => {
    reload();
  }, [reload]);

  function changeTab(t: WithdrawalStatus) {
    setTab(t);
    setPage(1);
  }

  async function handleConfirm(reviewNote: string | undefined) {
    if (!review) return;
    if (review.action === "approve") {
      await adminWithdrawals.approve(review.id, reviewNote);
    } else {
      await adminWithdrawals.reject(review.id, reviewNote);
    }
    setReview(null);
    await reload();
  }

  return (
    <div className="container py-4">
      <div className="mb-4">
        <Link
          href="/admin/films-series"
          className="d-inline-block mb-2"
          style={{ color: "var(--cinaf-text-muted)", textDecoration: "none" }}
        >
          <i className="bi bi-arrow-left me-1" />
          Films &amp; Séries
        </Link>
        <h1 style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
          <i className="bi bi-shield-slash me-2" style={{ color: "var(--cinaf-gold)" }} />
          Demandes de retrait
        </h1>
        <p className="mb-0" style={{ color: "var(--cinaf-text-muted)" }}>
          Examinez les demandes des studios et approuvez ou rejetez chaque retrait.
        </p>
      </div>

      <ul className="nav nav-tabs mb-3" style={{ borderBottomColor: "var(--cinaf-border)" }}>
        {TABS.map((t) => (
          <li key={t} className="nav-item">
            <button
              type="button"
              className={`nav-link ${tab === t ? "active" : ""}`}
              onClick={() => changeTab(t)}
              style={
                tab === t
                  ? {
                      background: "var(--cinaf-surface)",
                      color: "var(--cinaf-gold)",
                      borderColor: "var(--cinaf-border) var(--cinaf-border) var(--cinaf-surface)",
                      fontWeight: 600,
                    }
                  : { color: "var(--cinaf-text)" }
              }
            >
              {TAB_LABELS[t]}
            </button>
          </li>
        ))}
      </ul>

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
          <i className="bi bi-inbox d-block mb-2" style={{ fontSize: "3rem" }} />
          Aucune demande dans cet état.
        </div>
      ) : (
        <div className="row g-3">
          {items.map((w) => {
            const key = `${w.targetType}:${w.targetId}`;
            return (
              <div key={w.id} className="col-12 col-lg-6">
                <div
                  className="p-3 h-100"
                  style={{
                    background: "var(--cinaf-surface)",
                    border: "1px solid var(--cinaf-border)",
                    borderRadius: 10,
                  }}
                >
                  <div className="d-flex justify-content-between align-items-start mb-2">
                    <div>
                      <div
                        style={{
                          color: "var(--cinaf-text)",
                          fontWeight: 700,
                          fontSize: "1.05rem",
                        }}
                      >
                        {titles[key] ?? "Chargement…"}
                      </div>
                      <div className="small" style={{ color: "var(--cinaf-text-muted)" }}>
                        {w.targetType === "film" ? "Film" : "Série"} ·{" "}
                        {w.studio?.name ?? "studio inconnu"}
                      </div>
                    </div>
                    <StatusBadge status={w.status} />
                  </div>
                  <div className="small mb-2" style={{ color: "var(--cinaf-text-muted)" }}>
                    Demandé le{" "}
                    {new Date(w.createdAt).toLocaleString("fr-FR", {
                      dateStyle: "medium",
                      timeStyle: "short",
                    })}
                    {w.requestedBy && (
                      <>
                        {" "}
                        par{" "}
                        <span style={{ color: "var(--cinaf-text)" }}>
                          {w.requestedBy.firstName} {w.requestedBy.lastName}
                        </span>{" "}
                        ({w.requestedBy.email})
                      </>
                    )}
                  </div>
                  <div
                    className="p-2 small"
                    style={{
                      background: "var(--cinaf-surface-2)",
                      borderLeft: "3px solid var(--cinaf-gold)",
                      borderRadius: 4,
                      color: "var(--cinaf-text)",
                      whiteSpace: "pre-wrap",
                    }}
                  >
                    <div
                      className="mb-1"
                      style={{ color: "var(--cinaf-text-muted)", fontWeight: 600 }}
                    >
                      Motif :
                    </div>
                    {w.reason}
                  </div>
                  {w.reviewNote && (
                    <div
                      className="mt-2 p-2 small"
                      style={{
                        background: "var(--cinaf-surface-2)",
                        borderRadius: 4,
                        color: "var(--cinaf-text)",
                      }}
                    >
                      <div
                        className="mb-1"
                        style={{ color: "var(--cinaf-text-muted)", fontWeight: 600 }}
                      >
                        Note de l&apos;admin :
                      </div>
                      {w.reviewNote}
                    </div>
                  )}
                  {w.status === "PENDING" && (
                    <div className="d-flex gap-2 mt-3">
                      <button
                        type="button"
                        className="btn btn-sm flex-grow-1"
                        onClick={() => setReview({ id: w.id, action: "approve" })}
                        style={{ background: "#1f6337", color: "#fff", border: "none" }}
                      >
                        <i className="bi bi-check2-circle me-1" />
                        Approuver
                      </button>
                      <button
                        type="button"
                        className="btn btn-sm flex-grow-1"
                        onClick={() => setReview({ id: w.id, action: "reject" })}
                        style={{ background: "#7a2020", color: "#fff", border: "none" }}
                      >
                        <i className="bi bi-x-octagon me-1" />
                        Rejeter
                      </button>
                    </div>
                  )}
                </div>
              </div>
            );
          })}
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

      <WithdrawalReviewModal
        open={review !== null}
        action={review?.action ?? null}
        onClose={() => setReview(null)}
        onConfirm={handleConfirm}
      />
    </div>
  );
}
