"use client";

/**
 * ============================================================
 * CINAF v2 — Tableau d'historique des paiements (PaymentsHistoryTable)
 * ============================================================
 * Composant de présentation réutilisable listant les transactions et factures Stripe.
 * Consommé à la fois par :
 * - `/mon-abonnement` : consultation personnelle par l'utilisateur connecté.
 * - `/admin/utilisateurs/[id]` : consultation administrative du dossier client.
 * 
 * Fonctionnalités & Formatage :
 * - Conversion des montants Stripe de centimes en devises formatées (ex: 999 → "9,99 €").
 * - Formatage localisé des dates ISO en français ("JJ/MM/AAAA").
 * - Badges de statut colorés traduits (Payée, En attente, Annulée, Non recouvrable).
 * - Lien direct vers le PDF officiel de facture Stripe hébergé.
 * - Gestion élégante des états de chargement (`loading`), d'erreur (`error`) et de liste vide.
 */

import type { Payment } from "@/lib/api";

/**
 * Propriétés attendues par le composant `PaymentsHistoryTable`.
 */
interface PaymentsHistoryTableProps {
  /** Liste ordonnée des paiements reçus depuis l'API */
  payments: Payment[];
  /** Indique si les données sont en cours de chargement */
  loading: boolean;
  /** Message d'erreur éventuel en cas d'échec de récupération */
  error: string | null;
}

/**
 * Convertit un montant exprimé en centimes (format Stripe) vers une chaîne monétaire formatée en français.
 * 
 * @param amountInCents - Montant en centimes (ex: 999)
 * @param currency - Code de devise ISO (ex: "eur")
 * @returns Le montant formaté (ex: "9,99 €")
 */
function formatAmount(amountInCents: number, currency: string): string {
  try {
    return (amountInCents / 100).toLocaleString("fr-FR", {
      style: "currency",
      currency: (currency || "EUR").toUpperCase(),
    });
  } catch {
    // Fallback de sécurité si la devise n'est pas supportée par l'environnement Intl
    return `${(amountInCents / 100).toFixed(2)} ${currency}`;
  }
}

/**
 * Formate un horodatage ISO 8601 en date française (JJ/MM/AAAA).
 * 
 * @param iso - Date sous forme de chaîne ISO ou null
 * @returns Date formatée ou tiret cadratin si indisponible
 */
function formatDate(iso: string | null): string {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString("fr-FR");
}

/**
 * Associe à un statut Stripe son équivalent français et la classe Bootstrap appropriée.
 * 
 * @param status - Statut retourné par l'API Stripe ('paid', 'open', 'void', etc.)
 * @returns Libellé en français et classe CSS du badge
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
 * Tableau de facturation et historique des paiements Stripe.
 * 
 * @param props - Propriétés du composant
 * @returns Le tableau responsive des transactions
 */
export default function PaymentsHistoryTable({
  payments,
  loading,
  error,
}: PaymentsHistoryTableProps) {
  // État de chargement en cours
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

  // État d'erreur réseau ou serveur
  if (error) {
    return (
      <div className="alert alert-danger py-2 mb-0" role="alert">
        <i className="bi bi-exclamation-triangle-fill me-2" />
        {error}
      </div>
    );
  }

  // État aucun paiement enregistré
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

  // Tableau complet des règlements
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
