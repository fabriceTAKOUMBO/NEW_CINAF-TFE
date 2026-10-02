"use client";

// ============================================================
// CINAF v2 — Page de confirmation après paiement Stripe réussi
// Pointée par `return_url` de la Stripe Embedded Checkout Session.
// ============================================================
//
// Comportement :
//  - Si `?session_id=` présent : `GET /api/subscriptions/session/{id}` lit le
//    statut du paiement ET active l'abonnement côté serveur s'il est payé
//    (sans attendre le webhook), puis poll `/auth/me` pour confirmer
//    `hasActiveSubscription=true`.
//  - Sinon : activation mock immédiate → refresh une fois.
//  - « Abonnement activé » n'est affiché que si l'activation est confirmée ;
//    sinon, état « activation en attente » avec un bouton pour revérifier.

import { Suspense, useEffect, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { subscriptions } from "@/lib/api";
import type { StripeSessionStatus } from "@/lib/api";

/**
 * Contenu interactif de confirmation de commande Stripe.
 * Gère le polling progressif pour absorber le délai d'arrivée du webhook Stripe sur Symfony.
 */
function SuccessContent() {
  const searchParams = useSearchParams();
  const sessionId = searchParams.get("session_id");
  const { user, refresh } = useAuth();
  const [refreshing, setRefreshing] = useState<boolean>(Boolean(sessionId));
  const [sessionStatus, setSessionStatus] = useState<StripeSessionStatus | null>(null);
  const [sessionError, setSessionError] = useState<string | null>(null);
  /** Incrémenté par « Vérifier à nouveau » pour relancer confirmation + polling. */
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let cancelled = false;

    async function confirmAndPoll() {
      // Étape 1 : si retour Stripe, on récupère le statut réel de la session
      // (le backend active l'abonnement à cette occasion si le paiement est accepté).
      if (sessionId) {
        setSessionError(null);
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
  }, [sessionId, attempt]);

  useEffect(() => {
    if (user?.hasActiveSubscription) {
      setRefreshing(false);
    }
  }, [user?.hasActiveSubscription]);

  // Cas particulier : la session existe mais le paiement a échoué/n'est pas finalisé.
  const paymentFailed = sessionStatus !== null && sessionStatus.paymentStatus === "unpaid";
  // Vérifications terminées sans confirmation : on ne prétend pas que l'accès est ouvert.
  const pending = !paymentFailed && !refreshing && !user?.hasActiveSubscription;

  /** Relance la confirmation côté serveur et le polling de /auth/me. */
  const checkAgain = () => {
    setRefreshing(true);
    setAttempt((n) => n + 1);
  };

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
              : refreshing || pending
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
          ) : pending ? (
            <i className="bi bi-hourglass-split" style={{ fontSize: "2.5rem", color: "var(--cinaf-gold)" }} />
          ) : (
            <i className="bi bi-check-circle-fill" style={{ fontSize: "2.5rem", color: "#28a745" }} />
          )}
        </div>

        <h1 className="fw-bold mb-3" style={{ color: "var(--cinaf-text)" }}>
          {paymentFailed
            ? "Paiement non confirmé"
            : refreshing
            ? "Activation de votre abonnement..."
            : pending
            ? "Activation en attente"
            : "Abonnement activé !"}
        </h1>

        <p className="mb-4" style={{ color: "var(--cinaf-text-muted)" }}>
          {paymentFailed
            ? "Votre paiement n'a pas été finalisé. Aucun montant n'a été débité. Vous pouvez retenter la souscription."
            : refreshing
            ? "Votre paiement a été reçu. Nous activons votre accès au catalogue, cela prend quelques secondes."
            : pending
            ? `${
                // N'affirmer « paiement reçu » que si Stripe l'a réellement confirmé.
                sessionStatus?.paymentStatus === "paid"
                  ? "Votre paiement a bien été reçu par Stripe, mais l'activation de votre abonnement n'est pas encore confirmée."
                  : "Nous n'avons pas encore pu confirmer votre paiement ni l'activation de votre abonnement."
              } Vérifiez à nouveau dans quelques instants ; si le problème persiste, contactez-nous en indiquant la référence Stripe ci-dessous.`
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
          ) : pending ? (
            <button
              type="button"
              onClick={checkAgain}
              className="btn fw-semibold px-4 py-2"
              style={{ background: "var(--cinaf-gold)", color: "#000", borderRadius: 8 }}
            >
              <i className="bi bi-arrow-repeat me-2" />
              Vérifier à nouveau
            </button>
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

/**
 * Page de confirmation de succès de paiement Stripe enveloppée dans Suspense.
 */
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
