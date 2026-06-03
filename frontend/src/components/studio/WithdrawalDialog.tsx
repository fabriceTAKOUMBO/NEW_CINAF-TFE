"use client";

// ============================================================
// CINAF v2 — Modale de demande de retrait (studio)
// Le studio saisit un motif (>= 10 caractères) qui sera
// envoyé via studioFilms.withdraw / studioSeries.withdraw.
// ============================================================

import { useEffect, useState } from "react";

interface WithdrawalDialogProps {
  open: boolean;
  title?: string;
  onClose: () => void;
  onSubmit: (reason: string) => void | Promise<void>;
}

export default function WithdrawalDialog({
  open,
  title,
  onClose,
  onSubmit,
}: WithdrawalDialogProps) {
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setReason("");
      setError(null);
      setSubmitting(false);
    }
  }, [open]);

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const trimmed = reason.trim();
    if (trimmed.length < 10) {
      setError("Le motif doit contenir au moins 10 caractères.");
      return;
    }
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit(trimmed);
      onClose();
    } catch (err: unknown) {
      const m = (err as { message?: string })?.message ?? "Erreur lors de l'envoi.";
      setError(m);
    } finally {
      setSubmitting(false);
    }
  }

  if (!open) return null;

  return (
    <>
      <div
        className="modal-backdrop fade show"
        style={{ zIndex: 1050 }}
        onClick={submitting ? undefined : onClose}
      />
      <div
        className="modal fade show d-block"
        tabIndex={-1}
        role="dialog"
        style={{ zIndex: 1055 }}
      >
        <div className="modal-dialog modal-dialog-centered">
          <div
            className="modal-content"
            style={{
              background: "var(--cinaf-surface)",
              color: "var(--cinaf-text)",
              border: "1px solid var(--cinaf-border)",
            }}
          >
            <form onSubmit={handleSubmit}>
              <div className="modal-header" style={{ borderColor: "var(--cinaf-border)" }}>
                <h5 className="modal-title">
                  <i className="bi bi-shield-slash me-2" style={{ color: "#f0c080" }} />
                  {title ?? "Demander un retrait"}
                </h5>
                <button
                  type="button"
                  className="btn-close btn-close-white"
                  onClick={onClose}
                  disabled={submitting}
                  aria-label="Fermer"
                />
              </div>
              <div className="modal-body">
                <p className="small" style={{ color: "var(--cinaf-text-muted)" }}>
                  Décrivez précisément la raison de votre demande. Un administrateur
                  examinera la demande avant approbation.
                </p>
                <label htmlFor="reason" className="form-label">
                  Motif <span style={{ color: "#ff8a8a" }}>*</span>
                </label>
                <textarea
                  id="reason"
                  className="form-control"
                  rows={5}
                  minLength={10}
                  required
                  placeholder="Ex : Le contenu nécessite une mise à jour majeure, problème de droits…"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  style={{
                    background: "var(--cinaf-surface-2)",
                    color: "var(--cinaf-text)",
                    border: "1px solid var(--cinaf-border)",
                  }}
                />
                <div className="small mt-1" style={{ color: "var(--cinaf-text-muted)" }}>
                  {reason.trim().length} / 10 caractères minimum
                </div>
                {error && (
                  <div
                    className="alert mt-2 mb-0 py-2 small"
                    style={{
                      background: "#2a1414",
                      color: "#ff8a8a",
                      border: "1px solid #5a2020",
                    }}
                  >
                    {error}
                  </div>
                )}
              </div>
              <div className="modal-footer" style={{ borderColor: "var(--cinaf-border)" }}>
                <button
                  type="button"
                  className="btn btn-secondary"
                  onClick={onClose}
                  disabled={submitting}
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="btn"
                  disabled={submitting || reason.trim().length < 10}
                  style={{
                    background: "var(--cinaf-gold)",
                    color: "#000",
                    fontWeight: 600,
                  }}
                >
                  {submitting ? (
                    <>
                      <span className="spinner-border spinner-border-sm me-2" />
                      Envoi…
                    </>
                  ) : (
                    "Envoyer la demande"
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </>
  );
}
