"use client";

// ============================================================
// CINAF v2 — Tableau "Historique des paiements"
// Composant réutilisable affichant les paiements Stripe d'un
// utilisateur. Consommé par /mon-abonnement (côté user) et par
// /admin/utilisateurs/[id] (côté admin).
// ============================================================

import type { Payment } from "@/lib/api";

interface PaymentsHistoryTableProps {
  /** Liste des paiements à afficher (déjà triés du plus récent au plus ancien côté backend). */
  payments: Payment[];
  /** Indique si le chargement initial est en cours. */
  loading: boolean;
  /** Message d'erreur éventuel à afficher à la place du tableau. */
  error: string | null;
}

/**
 * Formate un montant Stripe (en centimes) vers une chaîne lisible.
 * Ex : (999, "EUR") → "9,99 €".
 */
function formatAmount(amountInCents: number, currency: string): string {
  try {
    return (amountInCents / 100).toLocaleString("fr-FR", {
      style: "currency",
      currency: (currency || "EUR").toUpperCase(),
    });
  } catch {
    // Fallback si la devise n'est pas reconnue par Intl.
    return `${(amountInCents / 100).toFixed(2)} ${currency}`;
  }
}

/**
 * Formate une date ISO 8601 en JJ/MM/AAAA. Retourne "—" si la date est nulle.
 */
function formatDate(iso: string | null): string {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString("fr-FR");
}

/**
 * Mappe un statut Stripe vers un libellé français + une classe Bootstrap.
 */
function statusBadge(status: string | null): { label: string; className: string } {
  switch (status) {
    case "paid":
      return { label: "Payée", className: "bg-success" };
    case "open":
      return { label: "En attente", className: "bg-warning text-dark" };
    case "void":
      return { label: "Annulée", className: "bg-secondary" };
    case "uncollectible":
      return { label: "Non recouvrable", className: "bg-danger" };
    default:
      return { label: status ?? "—", className: "bg-secondary" };
  }
}

/**
 * Tableau Bootstrap responsive listant les paiements (date, plan, montant, statut, lien PDF).
 * Affiche un spinner si `loading`, un message d'erreur si `error`, un état vide si la liste est vide.
 */
export default function PaymentsHistoryTable({
  payments,
  loading,
  error,
}: PaymentsHistoryTableProps) {
  // État de chargement
  if (loading) {
    return (
      <div className="text-center py-4">
        <div
          className="spinner-border"
          role="status"
          style={{ color: "var(--cinaf-gold)" }}
        >
          <span className="visually-hidden">Chargement de l&apos;historique...</span>
        </div>
      </div>
    );
  }

  // État d'erreur
  if (error) {
    return (
      <div className="alert alert-danger py-2 mb-0" role="alert">
        <i className="bi bi-exclamation-triangle-fill me-2" />
        {error}
      </div>
    );
  }

  // État vide
  if (payments.length === 0) {
    return (
      <p
        className="mb-0 py-3 text-center"
        style={{ color: "var(--cinaf-text-muted)" }}
      >
        <i className="bi bi-inbox me-2" />
        Vous n&apos;avez pas encore effectué de paiement.
      </p>
    );
  }

  // Tableau standard
  return (
    <div className="table-responsive">
      <table
        className="table table-dark table-hover mb-0"
        style={{ background: "transparent" }}
      >
        <thead>
          <tr
            style={{
              color: "var(--cinaf-text-muted)",
              borderBottom: "1px solid rgba(255,255,255,0.1)",
            }}
          >
            <th>Date</th>
            <th>Plan</th>
            <th>Montant</th>
            <th>Statut</th>
            <th>Facture</th>
          </tr>
        </thead>
        <tbody>
          {payments.map((p) => {
            const badge = statusBadge(p.status);
            return (
              <tr
                key={p.id}
                style={{ borderBottom: "1px solid rgba(255,255,255,0.05)" }}
              >
                <td style={{ color: "var(--cinaf-text)" }}>
                  {formatDate(p.paidAt)}
                </td>
                <td style={{ color: "var(--cinaf-text)" }}>
                  {p.planName ?? "—"}
                </td>
                <td style={{ color: "var(--cinaf-text)" }}>
                  {formatAmount(p.amount, p.currency)}
                </td>
                <td>
                  <span className={`badge ${badge.className}`}>{badge.label}</span>
                </td>
                <td>
                  {p.invoicePdfUrl ? (
                    <a
                      href={p.invoicePdfUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="btn btn-outline-light btn-sm"
                      title="Télécharger la facture (PDF)"
                    >
                      <i className="bi bi-filetype-pdf me-1" />
                      PDF
                    </a>
                  ) : (
                    <span style={{ color: "var(--cinaf-text-muted)" }}>—</span>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
