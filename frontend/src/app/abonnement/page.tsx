"use client";

// ============================================================
// CINAF v2 — Page d'abonnement (Pricing + Embedded Checkout)
// Le formulaire Stripe s'ouvre directement dans la page sans redirection.
// ============================================================

import { useState, useEffect, useCallback, useRef } from "react";
import { useRouter } from "next/navigation";
import { loadStripe, type Stripe } from "@stripe/stripe-js";
import {
  EmbeddedCheckoutProvider,
  EmbeddedCheckout,
} from "@stripe/react-stripe-js";
import { useAuth } from "@/lib/auth";
import { subscriptions, isStripeCheckoutResponse } from "@/lib/api";
import type { SubscriptionPlan, UserSubscription, ApiError } from "@/lib/api";

const STRIPE_PK = process.env.NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY ?? "";

// Singleton stripe-js promise — ne se charge qu'une fois par navigation.
let stripePromise: Promise<Stripe | null> | null = null;

/**
 * Initialise ou retourne l'instance Singleton du SDK Stripe JS client.
 * Évite les recharges multiples de la librairie Stripe lors des navigations internes.
 */
function getStripe(): Promise<Stripe | null> {
  if (stripePromise === null) {
    stripePromise = STRIPE_PK ? loadStripe(STRIPE_PK) : Promise.resolve(null);
  }
  return stripePromise;
}

/**
 * Page de tarification et de souscription aux abonnements CINAF.
 * 
 * Fonctionnalités :
 * - Liste les plans d'abonnement disponibles (mensuel, annuel) avec leurs avantages.
 * - Intègre le composant Stripe Embedded Checkout directement dans la page pour éviter
 *   les redirections externes et maximiser le taux de conversion.
 * - Redirige vers `/login?next=/abonnement` si l'utilisateur n'est pas authentifié lors du clic.
 * 
 * @returns La vue de tarification et d'encaissement Stripe.
 */
export default function AbonnementPage() {
  const { isAuthenticated, isLoading: authLoading, refresh } = useAuth();
  const router = useRouter();

  const [plans, setPlans] = useState<SubscriptionPlan[]>([]);
  const [currentSub, setCurrentSub] = useState<UserSubscription | null>(null);
  const [loading, setLoading] = useState(true);
  const [checkoutLoading, setCheckoutLoading] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Quand non-null : Stripe Embedded Checkout est affiché par-dessus la grille des plans.
  const [clientSecret, setClientSecret] = useState<string | null>(null);
  const checkoutRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    async function loadData() {
      try {
        const plansList = await subscriptions.getPlans();
        setPlans(plansList);
        if (isAuthenticated) {
          try {
            const sub = await subscriptions.getCurrent();
            setCurrentSub(sub);
          } catch {
            // Pas d'abonnement actif
          }
        }
      } catch {
        setError("Impossible de charger les plans d'abonnement.");
      } finally {
        setLoading(false);
      }
    }
    if (!authLoading) {
      loadData();
    }
  }, [authLoading, isAuthenticated]);

  // Scroll auto vers le widget Checkout dès qu'il est monté.
  useEffect(() => {
    if (clientSecret && checkoutRef.current) {
      checkoutRef.current.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }, [clientSecret]);

  const fetchClientSecret = useCallback(() => Promise.resolve(clientSecret ?? ""), [clientSecret]);

  const handleSubscribe = async (planId: string) => {
    if (!isAuthenticated) {
      router.push("/login?next=/abonnement");
      return;
    }

    setCheckoutLoading(planId);
    setError(null);

    try {
      const res = await subscriptions.subscribe(planId);
      if (isStripeCheckoutResponse(res)) {
        // Stripe Embedded : on charge le widget dans la même page.
        if (!STRIPE_PK) {
          setError(
            "Clé publique Stripe manquante (NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY).",
          );
          setCheckoutLoading(null);
          return;
        }
        setClientSecret(res.clientSecret);
        setCheckoutLoading(null);
        return;
      }
      // Mock : abo créé immédiatement.
      await refresh();
      router.push("/abonnement/success");
    } catch (err) {
      const apiErr = err as ApiError;
      setError(apiErr.message || "Erreur lors de la souscription.");
      setCheckoutLoading(null);
    }
  };

  const handleCloseCheckout = () => {
    setClientSecret(null);
  };

  if (loading || authLoading) {
    return (
      <div className="d-flex justify-content-center align-items-center" style={{ minHeight: "60vh" }}>
        <div className="spinner-border text-warning" role="status">
          <span className="visually-hidden">Chargement...</span>
        </div>
      </div>
    );
  }

  return (
    <div className="container py-5">
      {/* En-tête */}
      <div className="text-center mb-5">
        <h1 className="fw-bold mb-3" style={{ color: "var(--cinaf-text)" }}>
          Choisissez votre abonnement
        </h1>
        <p className="lead" style={{ color: "var(--cinaf-text-muted)", maxWidth: 600, margin: "0 auto" }}>
          Accédez à l&apos;intégralité du catalogue CINAF : films, séries, documentaires africains et indépendants.
        </p>
      </div>

      {/* Widget Stripe Embedded Checkout — affiché par-dessus la grille quand un plan est sélectionné */}
      {clientSecret && (
        <div ref={checkoutRef} className="mb-5">
          <div
            className="p-4 mb-3 d-flex justify-content-between align-items-center"
            style={{ background: "var(--cinaf-surface)", borderRadius: 16 }}
          >
            <div>
              <h2 className="h5 fw-bold mb-1" style={{ color: "var(--cinaf-text)" }}>
                <i className="bi bi-credit-card-fill me-2" style={{ color: "var(--cinaf-gold)" }} />
                Paiement sécurisé
              </h2>
              <p className="mb-0 small" style={{ color: "var(--cinaf-text-muted)" }}>
                Entrez vos informations bancaires ci-dessous. Vos données sont traitées par Stripe.
              </p>
            </div>
            <button
              type="button"
              className="btn btn-sm btn-outline-secondary"
              onClick={handleCloseCheckout}
            >
              <i className="bi bi-x-lg me-1" />
              Annuler
            </button>
          </div>
          <div style={{ background: "#fff", borderRadius: 12, overflow: "hidden" }}>
            <EmbeddedCheckoutProvider
              key={clientSecret}
              stripe={getStripe()}
              options={{ fetchClientSecret }}
            >
              <EmbeddedCheckout />
            </EmbeddedCheckoutProvider>
          </div>
        </div>
      )}

      {/* Message si déjà abonné */}
      {!clientSecret && currentSub && (
        <div className="alert py-3 mb-4 text-center" style={{ background: "rgba(200, 168, 75, 0.15)", borderColor: "var(--cinaf-gold)" }}>
          <i className="bi bi-check-circle-fill me-2" style={{ color: "var(--cinaf-gold)" }} />
          <strong>Vous êtes abonné</strong> — Plan {currentSub.plan.name} ({currentSub.plan.formattedPrice}/{currentSub.plan.billingInterval === "month" ? "mois" : "an"})
          {currentSub.canceledAt && (
            <span className="text-warning ms-2">
              (Annulation programmée — accès jusqu&apos;au {currentSub.endsAt ? new Date(currentSub.endsAt).toLocaleDateString("fr-FR") : "—"})
            </span>
          )}
        </div>
      )}

      {/* Erreur */}
      {error && (
        <div className="alert alert-danger text-center mb-4">
          <i className="bi bi-exclamation-triangle-fill me-2" />
          {error}
        </div>
      )}

      {/* Grille des plans — masquée pendant le paiement pour focus */}
      {!clientSecret && (
        <div className="row justify-content-center g-4">
          {plans.length === 0 && (
            <div className="col-12 text-center" style={{ color: "var(--cinaf-text-muted)" }}>
              Aucun plan d&apos;abonnement disponible pour le moment.
            </div>
          )}

          {plans.map((plan) => {
            const isCurrentPlan = currentSub?.plan.id === plan.id;
            const isAnnual = plan.billingInterval === "year";

            return (
              <div key={plan.id} className="col-md-6 col-lg-4">
                <div
                  className="card h-100 border-0 position-relative overflow-hidden"
                  style={{
                    background: "var(--cinaf-surface)",
                    borderRadius: 16,
                    border: isAnnual ? "2px solid var(--cinaf-gold)" : undefined,
                  }}
                >
                  {isAnnual && (
                    <div
                      className="position-absolute top-0 end-0 px-3 py-1 fw-semibold"
                      style={{
                        background: "var(--cinaf-gold)",
                        color: "#000",
                        borderBottomLeftRadius: 12,
                        fontSize: "0.8rem",
                      }}
                    >
                      Populaire
                    </div>
                  )}

                  <div className="card-body d-flex flex-column p-4">
                    <h3 className="fw-bold mb-1" style={{ color: "var(--cinaf-text)" }}>
                      {plan.name}
                    </h3>
                    <p className="mb-3" style={{ color: "var(--cinaf-text-muted)", fontSize: "0.9rem" }}>
                      Facturation {isAnnual ? "annuelle" : "mensuelle"}
                    </p>

                    <div className="mb-4">
                      <span className="fw-bold" style={{ fontSize: "2.5rem", color: "var(--cinaf-gold)" }}>
                        {plan.price.toFixed(2).replace(".", ",")}
                      </span>
                      <span style={{ color: "var(--cinaf-text-muted)" }}>
                        {" "}{plan.currency.toUpperCase()}/{isAnnual ? "an" : "mois"}
                      </span>
                    </div>

                    {plan.trialDays > 0 && (
                      <div className="mb-3">
                        <span className="badge bg-success px-3 py-2">
                          {plan.trialDays} jours d&apos;essai gratuit
                        </span>
                      </div>
                    )}

                    <ul className="list-unstyled mb-4 flex-grow-1">
                      {plan.features.map((feature, i) => (
                        <li key={i} className="d-flex align-items-start gap-2 mb-2">
                          <i className="bi bi-check-circle-fill mt-1" style={{ color: "var(--cinaf-gold)", flexShrink: 0 }} />
                          <span style={{ color: "var(--cinaf-text)" }}>{feature}</span>
                        </li>
                      ))}
                    </ul>

                    <button
                      className="btn w-100 fw-semibold py-2"
                      style={{
                        background: isCurrentPlan ? "transparent" : "var(--cinaf-gold)",
                        color: isCurrentPlan ? "var(--cinaf-gold)" : "#000",
                        border: isCurrentPlan ? "2px solid var(--cinaf-gold)" : "none",
                        borderRadius: 8,
                      }}
                      onClick={() => handleSubscribe(plan.id)}
                      disabled={isCurrentPlan || checkoutLoading !== null}
                    >
                      {checkoutLoading === plan.id ? (
                        <><span className="spinner-border spinner-border-sm me-2" />Chargement du paiement...</>
                      ) : isCurrentPlan ? (
                        <><i className="bi bi-check-lg me-2" />Plan actuel</>
                      ) : (
                        "S'abonner"
                      )}
                    </button>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Garantie */}
      {!clientSecret && (
        <div className="text-center mt-5" style={{ color: "var(--cinaf-text-muted)" }}>
          <p className="mb-1">
            <i className="bi bi-shield-lock-fill me-2" />
            Paiement sécurisé par Stripe — Annulation à tout moment
          </p>
          <p className="small">
            En vous abonnant, vous acceptez nos conditions d&apos;utilisation et notre politique de confidentialité.
          </p>
        </div>
      )}
    </div>
  );
}
