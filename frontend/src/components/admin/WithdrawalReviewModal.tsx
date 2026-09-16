"use client";

/**
 * ============================================================
 * CINAF v2 — Modale de revue administrative pour retraits (WithdrawalReviewModal)
 * ============================================================
 * Fenêtre de dialogue permettant aux administrateurs de statuer sur une demande
 * de retrait d'œuvre formulée par un studio.
 * 
 * Deux actions possibles :
 * - `approve` : Valide la dépublication. L'œuvre passe au statut `WITHDRAWN` et
 *   n'est plus accessible aux utilisateurs finaux dans le catalogue.
 * - `reject` : Refuse la demande de retrait. L'œuvre demeure `PUBLISHED`.
 * - Note optionnelle : L'administrateur peut consigner un motif explicatif pour le producteur.
 */

import { useEffect, useState } from "react";

/**
 * Propriétés attendues par le composant `WithdrawalReviewModal`.
 */
interface WithdrawalReviewModalProps {
  /** État d'ouverture de la modale */
  open: boolean;
  /** Action sélectionnée par l'administrateur : approbation ou rejet */
  action: "approve" | "reject" | null;
  /** Callback de fermeture sans validation */
  onClose: () => void;
  /** Callback de confirmation exécutant l'action avec la note administrative */
  onConfirm: (reviewNote: string | undefined) => void | Promise<void>;
}

/**
 * Modale de décision administrative sur les demandes de retrait d'œuvres.
 * 
 * @param props - Propriétés de la modale
 * @returns La modale de révision avec zone de commentaire
 */
export default function WithdrawalReviewModal({
  open,
  action,
  onClose,
  onConfirm,
}: WithdrawalReviewModalProps) {
  const [note, setNote] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Réinitialisation des champs lors de chaque ouverture
  useEffect(() => {
    if (open) {
      setNote("");
      setError(null);
      setSubmitting(false);
    }
  }, [open]);

  if (!open || !action) return null;

  const isApprove = action === "approve";

  /**
   * Soumet la décision administrative et transmet la note optionnelle.
   */
  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await onConfirm(note.trim() || undefined);
      onClose();
    } catch (err: unknown) {
      const m = (err as { message?: string })?.message ?? "Action impossible.";
      setError(m);
    } finally {
      setSubmitting(false);
    }
  }

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
                  <i
                    className={`bi ${isApprove ? "bi-check2-circle" : "bi-x-octagon"} me-2`}
                    style={{ color: isApprove ? "#8fd68f" : "#ff8a8a" }}
                  />
                  {isApprove ? "Approuver la demande" : "Rejeter la demande"}
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
                  {isApprove
                    ? "Le contenu sera marqué comme retiré et ne sera plus diffusable."
                    : "Le contenu reste publié. Le studio sera informé du refus."}
                </p>
                <label htmlFor="reviewNote" className="form-label">
                  Note (optionnelle)
                </label>
                <textarea
                  id="reviewNote"
                  className="form-control"
                  rows={4}
                  placeholder="Commentaire interne ou message au studio…"
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  style={{
                    background: "var(--cinaf-surface-2)",
                    color: "var(--cinaf-text)",
                    border: "1px solid var(--cinaf-border)",
                  }}
                />
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
                  disabled={submitting}
                  style={{
                    background: isApprove ? "#1f6337" : "#7a2020",
                    color: "#fff",
                    fontWeight: 600,
                    border: "none",
                  }}
                >
                  {submitting ? (
                    <>
                      <span className="spinner-border spinner-border-sm me-2" />
                      Traitement…
                    </>
                  ) : isApprove ? (
                    "Confirmer le retrait"
                  ) : (
                    "Confirmer le rejet"
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
