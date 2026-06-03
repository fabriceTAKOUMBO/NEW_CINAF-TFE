"use client";

// ============================================================
// CINAF v2 — Page de confirmation après paiement Stripe réussi
// Pointée par `return_url` de la Stripe Embedded Checkout Session.
// ============================================================
//
// Comportement :
//  - Si `?session_id=` présent : confirme le paiement via l'API backend
//    (`GET /api/subscriptions/session/{id}`) puis poll `/auth/me` jusqu'à
//    voir `hasActiveSubscription=true` (le webhook a un délai 1-3s).
//  - Sinon : activation mock immédiate → refresh une fois.

import { Suspense, useEffect, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { subscriptions } from "@/lib/api";
import type { StripeSessionStatus } from "@/lib/api";

function SuccessContent() {
  const searchParams = useSearchParams();
  const sessionId = searchParams.get("session_id");
  const { user, refresh } = useAuth();
  const [refreshing, setRefreshing] = useState<boolean>(Boolean(sessionId));
  const [sessionStatus, setSessionStatus] = useState<StripeSessionStatus | null>(null);
  const [sessionError, setSessionError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function confirmAndPoll() {
      // Étape 1 : si retour Stripe, on récupère le statut réel de la session.
      if (sessionId) {
        try {
          const status = await subscriptions.getSessionStatus(sessionId);
          if (!cancelled) setSessionStatus(status);
        } catch {
          if (!cancelled) setSessionError("Impossible de confirmer le statut du paiement.");
        }
      }

      // Étape 2 : poll /auth/me pour absorber le délai du webhook.
      const maxAttempts = sessionId ? 6 : 1;
      for (let i = 0; i < maxAttempts && !cancelled; i++) {
        await refresh();
        if (cancelled) return;
        if (i + 1 < maxAttempts) {
          await new Promise((r) => setTimeout(r, 2000));
        }
      }
      if (!cancelled) setRefreshing(false);
    }

    void confirmAndPoll();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sessionId]);

  useEffect(() => {
    if (user?.hasActiveSubscription) {
      setRefreshing(false);
    }
  }, [user?.hasActiveSubscription]);

  // Cas particulier : la session existe mais le paiement a échoué/n'est pas finalisé.
  const paymentFailed = sessionStatus !== null && sessionStatus.paymentStatus === "unpaid";

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
        <div
          className="d-inline-flex align-items-center justify-content-center rounded-circle mb-4"
          style={{
            width: 80,
            height: 80,
            background: paymentFailed
              ? "rgba(220, 53, 69, 0.15)"
              : refreshing
              ? "rgba(200, 168, 75, 0.15)"
              : "rgba(40, 167, 69, 0.15)",
          }}
        >
          {paymentFailed ? (
            <i className="bi bi-x-circle-fill" style={{ fontSize: "2.5rem", color: "#dc3545" }} />
          ) : refreshing ? (
            <div className="spinner-border" role="status" style={{ color: "var(--cinaf-gold)" }}>
              <span className="visually-hidden">Activation en cours...</span>
            </div>
          ) : (
            <i className="bi bi-check-circle-fill" style={{ fontSize: "2.5rem", color: "#28a745" }} />
          )}
        </div>

        <h1 className="fw-bold mb-3" style={{ color: "var(--cinaf-text)" }}>
          {paymentFailed
            ? "Paiement non confirmé"
            : refreshing
            ? "Activation de votre abonnement..."
            : "Abonnement activé !"}
        </h1>

        <p className="mb-4" style={{ color: "var(--cinaf-text-muted)" }}>
          {paymentFailed
            ? "Votre paiement n'a pas été finalisé. Aucun montant n'a été débité. Vous pouvez retenter la souscription."
            : refreshing
            ? "Votre paiement a été reçu. Nous activons votre accès au catalogue, cela prend quelques secondes."
            : "Votre paiement a été traité avec succès. Vous avez maintenant accès à l'intégralité du catalogue CINAF."}
        </p>

        {sessionError && (
          <div className="alert alert-warning small">
            {sessionError}
          </div>
        )}

        <div className="d-flex flex-column flex-sm-row gap-3 justify-content-center">
          {paymentFailed ? (
            <Link
              href="/abonnement"
              className="btn fw-semibold px-4 py-2"
              style={{ background: "var(--cinaf-gold)", color: "#000", borderRadius: 8 }}
            >
              <i className="bi bi-arrow-clockwise me-2" />
              Réessayer le paiement
            </Link>
          ) : (
            <Link
              href="/catalogue"
              className="btn fw-semibold px-4 py-2"
              style={{ background: "var(--cinaf-gold)", color: "#000", borderRadius: 8 }}
            >
              <i className="bi bi-collection-play-fill me-2" />
              Explorer le catalogue
            </Link>
          )}
          <Link
            href="/profile"
            className="btn btn-outline-secondary px-4 py-2"
            style={{ borderRadius: 8 }}
          >
            <i className="bi bi-person-fill me-2" />
            Mon profil
          </Link>
        </div>

        {sessionId && (
          <p className="mt-4 small" style={{ color: "var(--cinaf-text-muted)" }}>
            Référence Stripe : {sessionId.substring(0, 20)}...
          </p>
        )}
      </div>
    </div>
  );
}

export default function AbonnementSuccessPage() {
  return (
    <Suspense fallback={
      <div className="d-flex justify-content-center align-items-center" style={{ minHeight: "60vh" }}>
        <div className="spinner-border text-warning" role="status">
          <span className="visually-hidden">Chargement...</span>
        </div>
      </div>
    }>
      <SuccessContent />
    </Suspense>
  );
}
