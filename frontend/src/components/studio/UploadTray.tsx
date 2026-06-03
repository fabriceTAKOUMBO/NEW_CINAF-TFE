"use client";

// ============================================================
// CINAF v2 — Tray globale d'uploads en arrière-plan
// Widget fixed bottom-right qui affiche tous les uploads en
// cours et terminés. Survit à la navigation interne (le contexte
// est monté au layout root). Permet annuler/dismisser.
// ============================================================

import { useState } from "react";
import { targetKind, useUploads, type UploadJob } from "@/lib/upload-context";

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} o`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} Ko`;
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`;
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} Go`;
}

function jobIcon(job: UploadJob): string {
  if (job.status === "uploading") {
    return targetKind(job.target) === "video" ? "bi-camera-reels" : "bi-image";
  }
  if (job.status === "done") return "bi-check-circle-fill";
  if (job.status === "error") return "bi-x-circle-fill";
  return "bi-slash-circle"; // canceled
}

function jobColor(job: UploadJob): string {
  if (job.status === "done") return "#8fd68f";
  if (job.status === "error") return "#ff8a8a";
  if (job.status === "canceled") return "var(--cinaf-text-muted)";
  return "var(--cinaf-gold)";
}

export default function UploadTray() {
  const { jobs, dismiss, cancel, clearFinished } = useUploads();
  const [collapsed, setCollapsed] = useState(false);

  if (jobs.length === 0) return null;

  const activeCount = jobs.filter((j) => j.status === "uploading").length;
  const doneCount = jobs.filter((j) => j.status === "done").length;
  const errorCount = jobs.filter((j) => j.status === "error").length;

  return (
    <div
      style={{
        position: "fixed",
        right: 16,
        bottom: 16,
        zIndex: 1080,
        width: collapsed ? 280 : 360,
        maxWidth: "calc(100vw - 32px)",
        background: "var(--cinaf-surface)",
        border: "1px solid var(--cinaf-border)",
        borderRadius: 10,
        boxShadow: "0 8px 24px rgba(0,0,0,0.4)",
        overflow: "hidden",
        transition: "width 0.15s",
      }}
    >
      {/* Header */}
      <div
        className="d-flex align-items-center justify-content-between px-3 py-2"
        style={{
          background: "var(--cinaf-surface-2)",
          borderBottom: collapsed ? "none" : "1px solid var(--cinaf-border)",
          cursor: "pointer",
        }}
        onClick={() => setCollapsed((c) => !c)}
        role="button"
        tabIndex={0}
        onKeyDown={(e) => {
          if (e.key === "Enter" || e.key === " ") setCollapsed((c) => !c);
        }}
      >
        <div className="d-flex align-items-center gap-2" style={{ color: "var(--cinaf-text)" }}>
          <i
            className={`bi ${activeCount > 0 ? "bi-cloud-arrow-up-fill" : "bi-cloud-check-fill"}`}
            style={{ color: "var(--cinaf-gold)" }}
          />
          <strong style={{ fontSize: "0.9rem" }}>
            {activeCount > 0 ? `${activeCount} upload${activeCount > 1 ? "s" : ""} en cours` : "Uploads"}
          </strong>
          {(doneCount > 0 || errorCount > 0) && (
            <span className="small" style={{ color: "var(--cinaf-text-muted)" }}>
              · {doneCount} ok{errorCount > 0 ? `, ${errorCount} ko` : ""}
            </span>
          )}
        </div>
        <button
          type="button"
          className="btn btn-sm p-0"
          style={{ color: "var(--cinaf-text-muted)", border: "none", background: "transparent" }}
          aria-label={collapsed ? "Déplier" : "Replier"}
          onClick={(e) => {
            e.stopPropagation();
            setCollapsed((c) => !c);
          }}
        >
          <i className={`bi ${collapsed ? "bi-chevron-up" : "bi-chevron-down"}`} />
        </button>
      </div>

      {/* Liste des jobs */}
      {!collapsed && (
        <>
          <div style={{ maxHeight: 340, overflowY: "auto" }}>
            {jobs.map((job) => (
              <div
                key={job.id}
                className="px-3 py-2"
                style={{ borderBottom: "1px solid var(--cinaf-border)" }}
              >
                <div className="d-flex align-items-start gap-2">
                  <i
                    className={`bi ${jobIcon(job)} mt-1`}
                    style={{ color: jobColor(job), fontSize: "1rem", flex: "0 0 auto" }}
                  />
                  <div className="flex-grow-1" style={{ minWidth: 0 }}>
                    <div
                      style={{
                        color: "var(--cinaf-text)",
                        fontSize: "0.85rem",
                        fontWeight: 500,
                        overflow: "hidden",
                        textOverflow: "ellipsis",
                        whiteSpace: "nowrap",
                      }}
                      title={job.fileName}
                    >
                      {job.fileName}
                    </div>
                    <div className="small" style={{ color: "var(--cinaf-text-muted)" }}>
                      {formatBytes(job.fileSize)} ·{" "}
                      {job.status === "uploading" && `${job.progress}%`}
                      {job.status === "done" && "Terminé"}
                      {job.status === "error" && (job.error ?? "Erreur")}
                      {job.status === "canceled" && "Annulé"}
                    </div>
                    {job.status === "uploading" && (
                      <div
                        className="progress mt-1"
                        style={{ height: 4, background: "var(--cinaf-surface-2)" }}
                      >
                        <div
                          className="progress-bar"
                          role="progressbar"
                          style={{
                            width: `${job.progress}%`,
                            background: "var(--cinaf-gold)",
                            transition: "width 0.2s",
                          }}
                          aria-valuenow={job.progress}
                          aria-valuemin={0}
                          aria-valuemax={100}
                        />
                      </div>
                    )}
                  </div>
                  <button
                    type="button"
                    className="btn btn-sm p-0"
                    style={{
                      color: "var(--cinaf-text-muted)",
                      border: "none",
                      background: "transparent",
                      flex: "0 0 auto",
                    }}
                    aria-label={job.status === "uploading" ? "Annuler" : "Retirer"}
                    title={job.status === "uploading" ? "Annuler l'upload" : "Retirer de la liste"}
                    onClick={() => (job.status === "uploading" ? cancel(job.id) : dismiss(job.id))}
                  >
                    <i className={`bi ${job.status === "uploading" ? "bi-x-circle" : "bi-trash"}`} />
                  </button>
                </div>
              </div>
            ))}
          </div>
          {(doneCount > 0 || errorCount > 0) && (
            <div
              className="px-3 py-2 text-end"
              style={{ background: "var(--cinaf-surface-2)" }}
            >
              <button
                type="button"
                className="btn btn-sm"
                style={{
                  color: "var(--cinaf-text-muted)",
                  border: "none",
                  background: "transparent",
                  fontSize: "0.8rem",
                }}
                onClick={clearFinished}
              >
                <i className="bi bi-eraser me-1" />
                Effacer les terminés
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
