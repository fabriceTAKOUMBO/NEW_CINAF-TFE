"use client";

// ============================================================
// CINAF v2 — Page "Mon abonnement"
// Vue user-facing permettant de consulter le statut de son abonnement,
// résilier en mode différé (ou annuler la résiliation) et consulter
// l'historique des paiements Stripe.
// ============================================================

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useAuth } from "@/lib/auth";
import { subscriptions } from "@/lib/api";
import type { ApiError, Payment, UserSubscription } from "@/lib/api";
import PaymentsHistoryTable from "@/components/PaymentsHistoryTable";

/** Modes possibles pour la modal de confirmation (résiliation ou annulation). */
type ConfirmMode = "cancel" | "resume" | null;

/**
 * Détermine l'état d'affichage à partir de l'abonnement courant.
 * Reflète la matrice du brief :
 *  - ACTIVE sans résiliation différée → "actif"
 *  - ACTIVE + canceledAt + endsAt futur → "scheduled_cancel"
 *  - EXPIRED → "expired"
 *  - Aucun abo (null) → "none"
 */
type SubState = "active" | "scheduled_cancel" | "expired" | "none";

function computeState(sub: UserSubscription | null): SubState {
  if (!sub) return "none";
  const status = (sub.status ?? "").toUpperCase();
  if (status === "EXPIRED") return "expired";
  if (status === "ACTIVE") {
    if (sub.canceledAt && sub.endsAt && new Date(sub.endsAt) > new Date()) {
      return "scheduled_cancel";
    }
    return "active";
  }
  // Statuts inattendus (CANCELED legacy par ex.) → traités comme expirés visuellement.
  return "expired";
}

/**
 * Formate une date ISO 8601 en chaîne longue française (ex. "15 mai 2026").
 */
function formatLongDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString("fr-FR", {
    day: "2-digit",
    month: "long",
    year: "numeric",
  });
}

/**
 * Page dédiée à la gestion fine de l'abonnement de l'utilisateur.
 * 
 * Fonctionnalités :
 * - Affiche en temps réel le statut calculé (`SubState` : actif, résiliation programmée, expiré, néant).
 * - Modal interactive pour confirmer la résiliation différée (qui préserve l'accès jusqu'à la fin de la période payée).
 * - Bouton de reprise immédiate ("Annuler la résiliation") qui rétablit les prélèvements sans rupture d'accès.
 * - Intègre la table des règlements `PaymentsHistoryTable`.
 * 
 * @returns La vue complète de gestion de l'abonnement.
 */
export default function MonAbonnementPage() {
  const router = useRouter();
  const { user, isAuthenticated, isLoading, refresh } = useAuth();

  // État de l'abonnement courant + historique paiements.
  const [sub, setSub] = useState<UserSubscription | null>(null);
  const [subLoading, setSubLoading] = useState(true);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [paymentsLoading, setPaymentsLoading] = useState(true);
  const [paymentsError, setPaymentsError] = useState<string | null>(null);

  // Modal de confirmation : 'cancel' (résilier), 'resume' (annuler résiliation), null (fermée).
  const [confirmMode, setConfirmMode] = useState<ConfirmMode>(null);
  const [actionPending, setActionPending] = useState(false);
  const [toast, setToast] = useState<{ kind: "ok" | "err"; text: string } | null>(null);

  // Redirection si non authentifié.
  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace("/login?next=/mon-abonnement");
    }
  }, [isLoading, isAuthenticated, router]);

  // Chargement initial : abonnement + historique paiements.
  useEffect(() => {
    if (!user) return;
    let cancelled = false;

    setSubLoading(true);
    subscriptions
      .getCurrent()
      .then((s) => {
        if (!cancelled) setSub(s);
      })
      .catch(() => {
        if (!cancelled) setSub(null);
      })
      .finally(() => {
        if (!cancelled) setSubLoading(false);
      });

    setPaymentsLoading(true);
    setPaymentsError(null);
    subscriptions
      .getPayments()
      .then((list) => {
        if (!cancelled) setPayments(list);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const msg =
          (err as ApiError)?.message ?? "Impossible de charger l'historique.";
        setPaymentsError(msg);
      })
      .finally(() => {
        if (!cancelled) setPaymentsLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [user]);

  // Calcul de l'état affichage (memoizé pour éviter recalculs inutiles).
  const state = useMemo(() => computeState(sub), [sub]);

  /**
   * Confirme l'action en cours (résiliation ou annulation de résiliation),
   * met à jour l'état local + le context auth, puis ferme la modal.
   */
  async function handleConfirm() {
    if (!confirmMode) return;
    setActionPending(true);
    setToast(null);
    try {
      if (confirmMode === "cancel") {
        const result = await subscriptions.cancel();
        setSub(result.subscription);
        setToast({
          kind: "ok",
          text: "Résiliation enregistrée. Vous conservez l'accès jusqu'à la fin de la période en cours.",
        });
      } else {
        const result = await subscriptions.resume();
        setSub(result.subscription);
        setToast({
          kind: "ok",
          text: "Résiliation annulée. Votre cycle de facturation reprend normalement.",
        });
      }
      // Rafraîchit /auth/me pour synchroniser hasActiveSubscription.
      await refresh();
      setConfirmMode(null);
    } catch (err: unknown) {
      const msg = (err as ApiError)?.message ?? "Une erreur est survenue.";
      setToast({ kind: "err", text: msg });
    } finally {
      setActionPending(false);
    }
  }

  // Spinner pendant la vérification d'auth ou le chargement initial.
  if (isLoading || !isAuthenticated || !user) {
    return (
      <div
        className="d-flex justify-content-center align-items-center"
        style={{ minHeight: "60vh" }}
      >
        <div
          className="spinner-border"
          role="status"
          style={{ color: "var(--cinaf-gold)" }}
        >
          <span className="visually-hidden">Chargement...</span>
        </div>
      </div>
    );
  }

  // ── Rendu principal ─────────────────────────────────────────
  return (
    <div className="container py-5" style={{ maxWidth: 960 }}>
      <h1
        className="fw-bold mb-4"
        style={{ color: "var(--cinaf-text)" }}
      >
        <i
          className="bi bi-gem me-2"
          style={{ color: "var(--cinaf-gold)" }}
        />
        Mon abonnement
      </h1>

      {/* Toast simple (alert Bootstrap) — affiché en haut, dismissible. */}
      {toast && (
        <div
          className={`alert ${
            toast.kind === "ok" ? "alert-success" : "alert-danger"
          } d-flex align-items-center justify-content-between`}
          role="alert"
        >
          <span>
            <i
              className={`bi ${
                toast.kind === "ok"
                  ? "bi-check-circle-fill"
                  : "bi-exclamation-triangle-fill"
              } me-2`}
            />
            {toast.text}
          </span>
          <button
            type="button"
            className="btn-close"
            aria-label="Fermer"
            onClick={() => setToast(null)}
          />
        </div>
      )}

      {/* Carte principale : statut de l'abonnement */}
      <div
        className="card border-0 mb-4 p-4"
        style={{ background: "var(--cinaf-surface)", borderRadius: 12 }}
      >
        {subLoading ? (
          <div className="text-center py-3">
            <div
              className="spinner-border"
              role="status"
              style={{ color: "var(--cinaf-gold)" }}
            />
          </div>
        ) : (
          <SubscriptionCard
            state={state}
            sub={sub}
            actionPending={actionPending}
            onAskCancel={() => setConfirmMode("cancel")}
            onAskResume={() => setConfirmMode("resume")}
          />
        )}
      </div>

      {/* Carte secondaire : historique des paiements */}
      <div
        className="card border-0 p-4"
        style={{ background: "var(--cinaf-surface)", borderRadius: 12 }}
      >
        <h5
          className="fw-semibold mb-3"
          style={{ color: "var(--cinaf-gold)" }}
        >
          <i className="bi bi-receipt me-2" />
          Historique des paiements
        </h5>
        <PaymentsHistoryTable
          payments={payments}
          loading={paymentsLoading}
          error={paymentsError}
        />
      </div>

      {/* Modal de confirmation (résilier OU annuler la résiliation) */}
      {confirmMode && (
        <ConfirmModal
          mode={confirmMode}
          endsAt={sub?.endsAt ?? null}
          pending={actionPending}
          onCancel={() => !actionPending && setConfirmMode(null)}
          onConfirm={handleConfirm}
        />
      )}
    </div>
  );
}

// ─── Sous-composant : carte d'état de l'abonnement ────────────

interface SubscriptionCardProps {
  state: SubState;
  sub: UserSubscription | null;
  actionPending: boolean;
  onAskCancel: () => void;
  onAskResume: () => void;
}

/**
 * Affiche la carte d'état adaptée à l'état courant de l'abonnement.
 * Rend trois variantes : actif / résilié-en-attente / expiré ou inexistant.
 */
function SubscriptionCard({
  state,
  sub,
  actionPending,
  onAskCancel,
  onAskResume,
}: SubscriptionCardProps) {
  // Cas 1 : abonnement actif, pas de résiliation différée.
  if (state === "active" && sub) {
    return (
      <>
        <div className="d-flex align-items-center gap-2 mb-3">
          <span
            className="badge bg-success"
            style={{ fontSize: "0.85rem" }}
          >
            <i className="bi bi-check-circle-fill me-1" />
            Abonnement actif
          </span>
        </div>
        <p style={{ color: "var(--cinaf-text)" }} className="mb-3 fs-5">
          Abonnement actif jusqu&apos;au{" "}
          <strong>{formatLongDate(sub.endsAt)}</strong>.
        </p>
        <SubscriptionMeta sub={sub} />
        <button
          type="button"
          className="btn btn-outline-warning"
          onClick={onAskCancel}
          disabled={actionPending}
        >
          <i className="bi bi-x-circle me-2" />
          Résilier
        </button>
      </>
    );
  }

  // Cas 2 : ACTIVE + canceledAt → résiliation différée enregistrée.
  if (state === "scheduled_cancel" && sub) {
    return (
      <>
        <div className="d-flex align-items-center gap-2 mb-3">
          <span
            className="badge bg-warning text-dark"
            style={{ fontSize: "0.85rem" }}
          >
            <i className="bi bi-clock-history me-1" />
            Résiliation programmée
          </span>
        </div>
        <p style={{ color: "var(--cinaf-text)" }} className="mb-3 fs-5">
          Abonnement résilié, accès maintenu jusqu&apos;au{" "}
          <strong>{formatLongDate(sub.endsAt)}</strong>.
        </p>
        <SubscriptionMeta sub={sub} />
        <button
          type="button"
          className="btn"
          onClick={onAskResume}
          disabled={actionPending}
          style={{ background: "var(--cinaf-gold)", color: "#000" }}
        >
          <i className="bi bi-arrow-counterclockwise me-2" />
          Annuler la résiliation
        </button>
      </>
    );
  }

  // Cas 3 : abonnement expiré ou inexistant.
  return (
    <>
      <div className="d-flex align-items-center gap-2 mb-3">
        <span className="badge bg-secondary" style={{ fontSize: "0.85rem" }}>
          <i className="bi bi-x-circle-fill me-1" />
          {state === "expired" ? "Abonnement expiré" : "Aucun abonnement"}
        </span>
      </div>
      <p style={{ color: "var(--cinaf-text-muted)" }} className="mb-3">
        {state === "expired"
          ? "Votre abonnement est arrivé à échéance. Réabonnez-vous pour retrouver l'accès au catalogue CINAF."
          : "Vous n'avez pas encore d'abonnement. Découvrez nos formules pour profiter du catalogue CINAF."}
      </p>
      <Link
        href="/abonnement"
        className="btn fw-semibold px-4"
        style={{ background: "var(--cinaf-gold)", color: "#000" }}
      >
        <i className="bi bi-star-fill me-2" />
        {state === "expired" ? "Se réabonner" : "Voir les plans"}
      </Link>
    </>
  );
}

// ─── Sous-composant : méta-données plan / dates ─────────────

/**
 * Affiche les méta-données d'un abonnement (plan, date de début, fin prévue).
 */
function SubscriptionMeta({ sub }: { sub: UserSubscription }) {
  return (
    <div className="row g-3 mb-3">
      <div className="col-sm-6">
        <small style={{ color: "var(--cinaf-text-muted)" }}>Plan</small>
        <p
          className="mb-0 fw-medium"
          style={{ color: "var(--cinaf-text)" }}
        >
          {sub.plan.name} — {sub.plan.formattedPrice}/
          {sub.plan.billingInterval === "year" ? "an" : "mois"}
        </p>
      </div>
      <div className="col-sm-6">
        <small style={{ color: "var(--cinaf-text-muted)" }}>Début</small>
        <p
          className="mb-0 fw-medium"
          style={{ color: "var(--cinaf-text)" }}
        >
          {formatLongDate(sub.startsAt)}
        </p>
      </div>
    </div>
  );
}

// ─── Sous-composant : modal de confirmation ──────────────────

interface ConfirmModalProps {
  mode: "cancel" | "resume";
  endsAt: string | null;
  pending: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}

/**
 * Modal Bootstrap (rendue manuellement avec d-block) pour confirmer
 * une résiliation différée ou son annulation. Le texte s'adapte au `mode`.
 */
function ConfirmModal({
  mode,
  endsAt,
  pending,
  onCancel,
  onConfirm,
}: ConfirmModalProps) {
  const endsAtLabel = formatLongDate(endsAt);
  const isCancel = mode === "cancel";

  return (
    <div
      className="modal d-block"
      style={{ background: "rgba(0,0,0,0.75)" }}
      onClick={onCancel}
    >
      <div
        className="modal-dialog modal-dialog-centered"
        onClick={(e) => e.stopPropagation()}
      >
        <div
          className="modal-content border-0"
          style={{ background: "var(--cinaf-surface)" }}
        >
          <div className="modal-header border-0 pb-0">
            <h5
              className="modal-title"
              style={{ color: "var(--cinaf-text)", fontWeight: 700 }}
            >
              <i
                className={`bi ${
                  isCancel
                    ? "bi-exclamation-circle-fill text-warning"
                    : "bi-arrow-counterclockwise"
                } me-2`}
                style={!isCancel ? { color: "var(--cinaf-gold)" } : undefined}
              />
              {isCancel
                ? "Résilier votre abonnement ?"
                : "Annuler la résiliation ?"}
            </h5>
          </div>
          <div
            className="modal-body"
            style={{ color: "var(--cinaf-text-muted)" }}
          >
            {isCancel ? (
              <>
                <p className="mb-2">
                  Vous ne serez pas prélevé(e) lors du prochain renouvellement.
                </p>
                <p className="mb-2">
                  <strong style={{ color: "var(--cinaf-text)" }}>
                    Vous conservez l&apos;accès au catalogue CINAF jusqu&apos;au{" "}
                    {endsAtLabel}.
                  </strong>
                </p>
                <p className="mb-0">
                  Vous pouvez annuler cette résiliation à tout moment avant
                  cette date.
                </p>
              </>
            ) : (
              <>
                <p className="mb-2">
                  Votre abonnement reprendra son cycle de facturation normal.
                </p>
                <p className="mb-0">
                  Le prochain prélèvement aura lieu le{" "}
                  <strong style={{ color: "var(--cinaf-text)" }}>
                    {endsAtLabel}
                  </strong>
                  .
                </p>
              </>
            )}
          </div>
          <div className="modal-footer border-0">
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              onClick={onCancel}
              disabled={pending}
            >
              Annuler
            </button>
            <button
              type="button"
              className={`btn btn-sm ${
                isCancel ? "btn-warning" : "btn-cinaf"
              }`}
              onClick={onConfirm}
              disabled={pending}
              style={
                !isCancel
                  ? { background: "var(--cinaf-gold)", color: "#000" }
                  : undefined
              }
            >
              {pending ? (
                <>
                  <span className="spinner-border spinner-border-sm me-2" />
                  Traitement...
                </>
              ) : isCancel ? (
                <>
                  <i className="bi bi-x-circle me-2" />
                  Confirmer la résiliation
                </>
              ) : (
                <>
                  <i className="bi bi-check-lg me-2" />
                  Confirmer
                </>
              )}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
