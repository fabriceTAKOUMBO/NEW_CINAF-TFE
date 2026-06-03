"use client";

// ============================================================
// CINAF v2 — Page d'annulation de checkout Stripe
// Affichée quand l'utilisateur annule le processus de paiement.
// ============================================================

import Link from "next/link";

export default function AbonnementCancelPage() {
  return (
    <div className="container py-5">
      <div
        className="mx-auto text-center p-5"
        style={{
          maxWidth: 600,
          background: "var(--cinaf-surface)",
          borderRadius: 16,
        }}
      >
        {/* Icône d'information */}
        <div
          className="d-inline-flex align-items-center justify-content-center rounded-circle mb-4"
          style={{
            width: 80,
            height: 80,
            background: "rgba(200, 168, 75, 0.15)",
          }}
        >
          <i className="bi bi-x-circle-fill" style={{ fontSize: "2.5rem", color: "var(--cinaf-gold)" }} />
        </div>

        <h1 className="fw-bold mb-3" style={{ color: "var(--cinaf-text)" }}>
          Paiement annulé
        </h1>

        <p className="mb-4" style={{ color: "var(--cinaf-text-muted)" }}>
          Votre processus de paiement a été annulé. Aucun montant n&apos;a été débité.
          Vous pouvez revenir à la page d&apos;abonnement pour choisir un plan.
        </p>

        {/* Actions */}
        <div className="d-flex flex-column flex-sm-row gap-3 justify-content-center">
          <Link
            href="/abonnement"
            className="btn fw-semibold px-4 py-2"
            style={{ background: "var(--cinaf-gold)", color: "#000", borderRadius: 8 }}
          >
            <i className="bi bi-arrow-left me-2" />
            Retour aux plans
          </Link>
          <Link
            href="/"
            className="btn btn-outline-secondary px-4 py-2"
            style={{ borderRadius: 8 }}
          >
            <i className="bi bi-house-fill me-2" />
            Accueil
          </Link>
        </div>
      </div>
    </div>
  );
}
