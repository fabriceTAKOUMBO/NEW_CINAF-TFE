"use client";

// ============================================================
// CINAF v2 — Admin / Détail & édition d'un utilisateur
// ============================================================

import { useEffect, useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import {
  ADMIN_ROLES,
  adminUsers,
  adminSubscriptions,
  subscriptions,
  type AdminRole,
  type AdminUser,
  type Payment,
  type SubscriptionPlan,
  type UserSubscription,
} from "@/lib/api";
import { useAuth } from "@/lib/auth";
import PaymentsHistoryTable from "@/components/PaymentsHistoryTable";

/**
 * Page d'administration détaillée d'un compte utilisateur (/admin/utilisateurs/[id]).
 * 
 * Capacités d'administration :
 * - Modification du profil (nom, prénom, email, état de vérification de l'email).
 * - Attribution et révocation des rôles d'accès (multi-sélection des rôles Symfony).
 * - Suspension temporaire ou réactivation du compte.
 * - Attribution manuelle d'un abonnement payant (plans mensuels/annuels) avec recalcul des dates.
 * - Résiliation différée administrative ou annulation de résiliation avec confirmation.
 * - Consultation de l'historique complet des paiements Stripe associés au compte.
 * 
 * @returns La vue d'édition et de supervision détaillée du compte utilisateur.
 */
export default function AdminUserDetailPage() {
  const params = useParams();
  const router = useRouter();
  const { user: currentUser } = useAuth();
  const id = params.id as string;

  const [user, setUser] = useState<AdminUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Form profil (email/firstName/lastName/isVerified)
  const [profile, setProfile] = useState({ email: "", firstName: "", lastName: "", isVerified: false });
  const [profileSaving, setProfileSaving] = useState(false);
  const [profileMessage, setProfileMessage] = useState<{ kind: "ok" | "err"; text: string } | null>(null);

  // Form rôles (multi-select)
  const [roles, setRoles] = useState<string[]>([]);
  const [rolesSaving, setRolesSaving] = useState(false);
  const [rolesMessage, setRolesMessage] = useState<{ kind: "ok" | "err"; text: string } | null>(null);

  // Suppression / suspension
  const [actionPending, setActionPending] = useState(false);

  // Abonnement
  const [currentSub, setCurrentSub] = useState<UserSubscription | null>(null);
  const [plans, setPlans] = useState<SubscriptionPlan[]>([]);
  const [selectedPlanId, setSelectedPlanId] = useState<string>("");
  const [subPending, setSubPending] = useState(false);
  const [subMessage, setSubMessage] = useState<{ kind: "ok" | "err"; text: string } | null>(null);

  // Édition de l'abo actif (plan + startsAt). endsAt est recalculé.
  const [editPlanId, setEditPlanId] = useState<string>("");
  const [editStartsAt, setEditStartsAt] = useState<string>("");

  // Historique paiements Stripe de l'utilisateur ciblé.
  const [payments, setPayments] = useState<Payment[]>([]);
  const [paymentsLoading, setPaymentsLoading] = useState(true);
  const [paymentsError, setPaymentsError] = useState<string | null>(null);

  // Modal de confirmation pour cancel / resume.
  // 'cancel' = résilier (différé), 'resume' = annuler la résiliation différée.
  const [confirmMode, setConfirmMode] = useState<"cancel" | "resume" | null>(null);

  // Synchronise les inputs édition quand currentSub change.
  useEffect(() => {
    if (currentSub) {
      setEditPlanId(currentSub.plan.id);
      setEditStartsAt(currentSub.startsAt.slice(0, 10));
    }
  }, [currentSub]);

  // Preview de endsAt calculé côté frontend selon plan + startsAt sélectionnés.
  const previewEndsAt = useMemo(() => {
    if (!editPlanId || !editStartsAt) return null;
    const plan = plans.find((p) => p.id === editPlanId);
    if (!plan) return null;
    const start = new Date(editStartsAt);
    if (Number.isNaN(start.getTime())) return null;
    const end = new Date(start);
    const count = plan.intervalCount ?? 1;
    if (plan.intervalUnit === "year" || plan.billingInterval === "year") {
      end.setFullYear(end.getFullYear() + count);
    } else {
      end.setMonth(end.getMonth() + count);
    }
    return end;
  }, [editPlanId, editStartsAt, plans]);

  const isDirty = useMemo(() => {
    if (!currentSub) return false;
    return (
      editPlanId !== currentSub.plan.id ||
      editStartsAt !== currentSub.startsAt.slice(0, 10)
    );
  }, [currentSub, editPlanId, editStartsAt]);

  const isSelf = user?.id === currentUser?.id;

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    Promise.all([
      adminUsers.get(id),
      adminSubscriptions.getCurrent(id).catch(() => null),
      subscriptions.getPlans().catch(() => [] as SubscriptionPlan[]),
    ])
      .then(([u, sub, planList]) => {
        if (cancelled) return;
        setUser(u);
        setProfile({
          email: u.email,
          firstName: u.firstName,
          lastName: u.lastName,
          isVerified: u.isVerified ?? false,
        });
        setRoles(u.roles.filter((r) => r !== "ROLE_USER"));
        setCurrentSub(sub);
        setPlans(planList);
        if (planList.length > 0) {
          setSelectedPlanId(planList[0].id);
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const status = (err as { statusCode?: number })?.statusCode;
        const msg = (err as { message?: string })?.message ?? "Erreur inconnue";
        setError(status === 404 ? "Utilisateur introuvable." : msg);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    // Chargement parallèle de l'historique de paiements (indépendant de l'abo).
    setPaymentsLoading(true);
    setPaymentsError(null);
    adminSubscriptions
      .getPayments(id)
      .then((list) => {
        if (!cancelled) setPayments(list);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const msg =
          (err as { message?: string })?.message ??
          "Impossible de charger l'historique.";
        setPaymentsError(msg);
      })
      .finally(() => {
        if (!cancelled) setPaymentsLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [id]);

  async function assignSubscription() {
    if (!user || !selectedPlanId) return;
    setSubPending(true);
    setSubMessage(null);
    try {
      const created = await adminSubscriptions.assign(user.id, selectedPlanId);
      setCurrentSub(created);
      setSubMessage({ kind: "ok", text: "Abonnement activé." });
    } catch (err: unknown) {
      const msg = (err as { message?: string })?.message ?? "Erreur lors de l'activation.";
      setSubMessage({ kind: "err", text: msg });
    } finally {
      setSubPending(false);
    }
  }

  async function saveSubscription() {
    if (!user || !currentSub || !isDirty) return;
    setSubPending(true);
    setSubMessage(null);
    try {
      const payload: { planId?: string; startsAt?: string } = {};
      if (editPlanId !== currentSub.plan.id) payload.planId = editPlanId;
      if (editStartsAt !== currentSub.startsAt.slice(0, 10)) payload.startsAt = editStartsAt;
      const updated = await adminSubscriptions.update(user.id, payload);
      setCurrentSub(updated);
      setSubMessage({ kind: "ok", text: "Abonnement mis à jour." });
    } catch (err: unknown) {
      const msg = (err as { message?: string })?.message ?? "Erreur lors de la modification.";
      setSubMessage({ kind: "err", text: msg });
    } finally {
      setSubPending(false);
    }
  }

  /**
   * Confirme l'action de la modal : résiliation différée OU annulation de cette résiliation.
   * Le DELETE backend ne supprime plus l'abo : il programme la fin à `endsAt` (canceledAt rempli,
   * status reste ACTIVE). On rafraîchit donc `currentSub` avec la réponse au lieu de le remettre à null.
   */
  async function confirmSubscriptionAction() {
    if (!user || !confirmMode) return;
    setSubPending(true);
    setSubMessage(null);
    try {
      if (confirmMode === "cancel") {
        const res = await adminSubscriptions.cancel(user.id);
        setCurrentSub(res.subscription);
        setSubMessage({
          kind: "ok",
          text:
            "Résiliation enregistrée. L'utilisateur conserve l'accès jusqu'à la fin de la période en cours.",
        });
      } else {
        const res = await adminSubscriptions.resume(user.id);
        setCurrentSub(res.subscription);
        setSubMessage({
          kind: "ok",
          text: "Résiliation annulée. Le cycle de facturation reprend normalement.",
        });
      }
      setConfirmMode(null);
    } catch (err: unknown) {
      const msg = (err as { message?: string })?.message ?? "Erreur lors de l'opération.";
      setSubMessage({ kind: "err", text: msg });
    } finally {
      setSubPending(false);
    }
  }

  async function saveProfile(e: React.FormEvent) {
    e.preventDefault();
    if (!user) return;
    setProfileSaving(true);
    setProfileMessage(null);
    try {
      const updated = await adminUsers.update(user.id, {
        email: profile.email,
        firstName: profile.firstName,
        lastName: profile.lastName,
        isVerified: profile.isVerified,
      });
      setUser(updated);
      setProfileMessage({ kind: "ok", text: "Profil mis à jour avec succès." });
    } catch (err: unknown) {
      const msg = (err as { message?: string })?.message ?? "Erreur inconnue";
      setProfileMessage({ kind: "err", text: msg });
    } finally {
      setProfileSaving(false);
    }
  }

  async function saveRoles(e: React.FormEvent) {
    e.preventDefault();
    if (!user) return;
    setRolesSaving(true);
    setRolesMessage(null);
    // Le backend exige toujours au moins un rôle ; on inclut ROLE_USER de base.
    const payload = roles.includes("ROLE_USER") ? roles : ["ROLE_USER", ...roles];
    try {
      const updated = await adminUsers.updateRole(user.id, payload);
      setUser(updated);
      setRoles(updated.roles.filter((r) => r !== "ROLE_USER"));
      setRolesMessage({ kind: "ok", text: "Rôles mis à jour." });
    } catch (err: unknown) {
      const msg = (err as { message?: string })?.message ?? "Erreur inconnue";
      setRolesMessage({ kind: "err", text: msg });
    } finally {
      setRolesSaving(false);
    }
  }

  async function toggleSuspend() {
    if (!user || isSelf) return;
    setActionPending(true);
    try {
      const updated = user.isSuspended
        ? await adminUsers.activate(user.id)
        : await adminUsers.suspend(user.id);
      setUser(updated);
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Erreur lors de la mise à jour.");
    } finally {
      setActionPending(false);
    }
  }

  async function deleteAccount() {
    if (!user || isSelf) return;
    if (!confirm(`Supprimer définitivement « ${user.email} » ? Cette action est irréversible.`)) {
      return;
    }
    setActionPending(true);
    try {
      await adminUsers.remove(user.id);
      router.push("/admin/utilisateurs");
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Erreur lors de la suppression.");
      setActionPending(false);
    }
  }

  function toggleRole(role: AdminRole) {
    setRoles((prev) =>
      prev.includes(role) ? prev.filter((r) => r !== role) : [...prev, role],
    );
  }

  if (loading) {
    return (
      <div className="container py-5 text-center">
        <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} role="status" />
      </div>
    );
  }

  if (error || !user) {
    return (
      <div className="container py-5 text-center">
        <i
          className="bi bi-exclamation-triangle"
          style={{ fontSize: "3rem", color: "var(--cinaf-gold)" }}
        />
        <h3 className="mt-3" style={{ color: "var(--cinaf-text)" }}>
          {error ?? "Utilisateur introuvable."}
        </h3>
        <Link href="/admin/utilisateurs" className="btn btn-cinaf-outline mt-3">
          <i className="bi bi-arrow-left me-1" />
          Retour à la liste
        </Link>
      </div>
    );
  }

  const initials =
    ((user.firstName?.[0] ?? "") + (user.lastName?.[0] ?? "")).toUpperCase() ||
    (user.email[0]?.toUpperCase() ?? "?");

  return (
    <div className="container py-4">
      <Link
        href="/admin/utilisateurs"
        className="d-inline-block mb-3"
        style={{ color: "var(--cinaf-text-muted)", textDecoration: "none" }}
      >
        <i className="bi bi-arrow-left me-1" />
        Retour à la liste
      </Link>

      <div className="d-flex align-items-center gap-3 mb-4">
        <span
          style={{
            width: 64,
            height: 64,
            borderRadius: "50%",
            background: "linear-gradient(135deg, var(--cinaf-gold), #a07830)",
            color: "#000",
            fontWeight: 700,
            fontSize: "1.5rem",
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
          }}
        >
          {initials}
        </span>
        <div>
          <h1 className="mb-0" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
            {user.firstName} {user.lastName}
            {isSelf && (
              <span className="badge bg-secondary ms-2" style={{ fontSize: "0.7rem" }}>
                Vous
              </span>
            )}
          </h1>
          <p className="mb-0" style={{ color: "var(--cinaf-text-muted)" }}>
            {user.email} · ID {user.id.slice(0, 8)}…
          </p>
        </div>
      </div>

      <div className="row g-4">
        <div className="col-12 col-lg-7">
          <section
            className="p-4"
            style={{
              background: "var(--cinaf-surface)",
              border: "1px solid var(--cinaf-border)",
              borderRadius: 10,
            }}
          >
            <h5 className="mb-3" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
              <i className="bi bi-person-lines-fill me-2" style={{ color: "var(--cinaf-gold)" }} />
              Informations
            </h5>

            <form onSubmit={saveProfile}>
              <div className="mb-3">
                <label className="form-label" style={{ color: "var(--cinaf-text)" }}>
                  Email
                </label>
                <input
                  type="email"
                  className="form-control"
                  value={profile.email}
                  onChange={(e) => setProfile((p) => ({ ...p, email: e.target.value }))}
                  style={{
                    background: "var(--cinaf-surface-2)",
                    color: "var(--cinaf-text)",
                    border: "1px solid var(--cinaf-border)",
                  }}
                />
              </div>

              <div className="row g-3 mb-3">
                <div className="col-12 col-md-6">
                  <label className="form-label" style={{ color: "var(--cinaf-text)" }}>
                    Prénom
                  </label>
                  <input
                    type="text"
                    className="form-control"
                    value={profile.firstName}
                    onChange={(e) => setProfile((p) => ({ ...p, firstName: e.target.value }))}
                    style={{
                      background: "var(--cinaf-surface-2)",
                      color: "var(--cinaf-text)",
                      border: "1px solid var(--cinaf-border)",
                    }}
                  />
                </div>
                <div className="col-12 col-md-6">
                  <label className="form-label" style={{ color: "var(--cinaf-text)" }}>
                    Nom
                  </label>
                  <input
                    type="text"
                    className="form-control"
                    value={profile.lastName}
                    onChange={(e) => setProfile((p) => ({ ...p, lastName: e.target.value }))}
                    style={{
                      background: "var(--cinaf-surface-2)",
                      color: "var(--cinaf-text)",
                      border: "1px solid var(--cinaf-border)",
                    }}
                  />
                </div>
              </div>

              <div className="form-check form-switch mb-3">
                <input
                  className="form-check-input"
                  type="checkbox"
                  id="isVerifiedToggle"
                  checked={profile.isVerified}
                  onChange={(e) => setProfile((p) => ({ ...p, isVerified: e.target.checked }))}
                />
                <label
                  className="form-check-label"
                  htmlFor="isVerifiedToggle"
                  style={{ color: "var(--cinaf-text)" }}
                >
                  Email vérifié
                </label>
              </div>

              <button
                type="submit"
                className="btn btn-cinaf"
                disabled={profileSaving}
              >
                {profileSaving && (
                  <span className="spinner-border spinner-border-sm me-2" role="status" />
                )}
                Enregistrer
              </button>

              {profileMessage && (
                <div
                  className="mt-3 small"
                  style={{
                    color: profileMessage.kind === "ok" ? "#8fd68f" : "#ff8a8a",
                  }}
                >
                  <i
                    className={`bi ${
                      profileMessage.kind === "ok" ? "bi-check-circle" : "bi-exclamation-triangle"
                    } me-1`}
                  />
                  {profileMessage.text}
                </div>
              )}
            </form>
          </section>

          <section
            className="p-4 mt-4"
            style={{
              background: "var(--cinaf-surface)",
              border: "1px solid var(--cinaf-border)",
              borderRadius: 10,
            }}
          >
            <h5 className="mb-3" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
              <i className="bi bi-shield-check me-2" style={{ color: "var(--cinaf-gold)" }} />
              Rôles
            </h5>
            <p className="small" style={{ color: "var(--cinaf-text-muted)" }}>
              ROLE_USER est implicite et toujours présent. Sélectionnez les rôles supplémentaires.
            </p>

            <form onSubmit={saveRoles}>
              <div className="d-flex flex-wrap gap-2 mb-3">
                {ADMIN_ROLES.filter((r) => r !== "ROLE_USER").map((r) => {
                  const active = roles.includes(r);
                  return (
                    <button
                      key={r}
                      type="button"
                      onClick={() => toggleRole(r)}
                      className="btn btn-sm"
                      style={{
                        background: active ? "var(--cinaf-gold)" : "var(--cinaf-surface-2)",
                        color: active ? "#000" : "var(--cinaf-text)",
                        border: "1px solid var(--cinaf-border)",
                        fontWeight: active ? 700 : 400,
                      }}
                    >
                      {r}
                    </button>
                  );
                })}
              </div>
              <button type="submit" className="btn btn-cinaf" disabled={rolesSaving}>
                {rolesSaving && (
                  <span className="spinner-border spinner-border-sm me-2" role="status" />
                )}
                Mettre à jour les rôles
              </button>
              {rolesMessage && (
                <div
                  className="mt-3 small"
                  style={{
                    color: rolesMessage.kind === "ok" ? "#8fd68f" : "#ff8a8a",
                  }}
                >
                  <i
                    className={`bi ${
                      rolesMessage.kind === "ok" ? "bi-check-circle" : "bi-exclamation-triangle"
                    } me-1`}
                  />
                  {rolesMessage.text}
                </div>
              )}
            </form>
          </section>
        </div>

        <div className="col-12 col-lg-5">
          <section
            className="p-4"
            style={{
              background: "var(--cinaf-surface)",
              border: "1px solid var(--cinaf-border)",
              borderRadius: 10,
            }}
          >
            <h5 className="mb-3" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
              <i className="bi bi-info-circle me-2" style={{ color: "var(--cinaf-gold)" }} />
              Statut
            </h5>
            <ul className="list-unstyled mb-3" style={{ color: "var(--cinaf-text-muted)" }}>
              <li className="mb-2">
                <strong style={{ color: "var(--cinaf-text)" }}>Inscrit le : </strong>
                {user.createdAt ? new Date(user.createdAt).toLocaleString("fr-FR") : "—"}
              </li>
              <li className="mb-2">
                <strong style={{ color: "var(--cinaf-text)" }}>Email vérifié : </strong>
                {user.isVerified ? "Oui" : "Non"}
              </li>
              <li>
                <strong style={{ color: "var(--cinaf-text)" }}>Compte : </strong>
                {user.isSuspended ? (
                  <span style={{ color: "#ff8a8a" }}>Suspendu (login bloqué)</span>
                ) : (
                  <span style={{ color: "#8fd68f" }}>Actif</span>
                )}
              </li>
            </ul>

            <button
              type="button"
              onClick={toggleSuspend}
              disabled={isSelf || actionPending}
              className="btn w-100 mb-2"
              style={{
                background: user.isSuspended ? "#1f3a1f" : "#5a3520",
                color: user.isSuspended ? "#8fd68f" : "#f0c080",
                border: "none",
              }}
            >
              <i className={`bi ${user.isSuspended ? "bi-check-circle" : "bi-pause-circle"} me-1`} />
              {user.isSuspended ? "Réactiver le compte" : "Suspendre le compte"}
            </button>

            <button
              type="button"
              onClick={deleteAccount}
              disabled={isSelf || actionPending}
              className="btn w-100"
              style={{
                background: "#3a1414",
                color: "#ff8a8a",
                border: "none",
              }}
            >
              <i className="bi bi-trash me-1" />
              Supprimer définitivement
            </button>

            {isSelf && (
              <p className="mt-3 small" style={{ color: "var(--cinaf-text-muted)" }}>
                <i className="bi bi-lock me-1" />
                Vous ne pouvez pas modifier l&apos;état ni supprimer votre propre compte.
              </p>
            )}
          </section>

          <section
            className="p-4 mt-4"
            style={{
              background: "var(--cinaf-surface)",
              border: "1px solid var(--cinaf-border)",
              borderRadius: 10,
            }}
          >
            <h5 className="mb-3" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
              <i className="bi bi-gem me-2" style={{ color: "var(--cinaf-gold)" }} />
              Abonnement
            </h5>

            {currentSub ? (
              <>
                <div className="mb-3">
                  <label className="form-label small" style={{ color: "var(--cinaf-text-muted)" }}>
                    Plan
                  </label>
                  <select
                    className="form-select"
                    value={editPlanId}
                    onChange={(e) => setEditPlanId(e.target.value)}
                    disabled={subPending}
                    style={{
                      background: "var(--cinaf-surface-2)",
                      color: "var(--cinaf-text)",
                      border: "1px solid var(--cinaf-border)",
                    }}
                  >
                    {plans.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.name} · {p.formattedPrice} /{" "}
                        {p.billingInterval === "year" ? "an" : "mois"}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="mb-3">
                  <label className="form-label small" style={{ color: "var(--cinaf-text-muted)" }}>
                    Date de début
                  </label>
                  <input
                    type="date"
                    className="form-control"
                    value={editStartsAt}
                    onChange={(e) => setEditStartsAt(e.target.value)}
                    disabled={subPending}
                    style={{
                      background: "var(--cinaf-surface-2)",
                      color: "var(--cinaf-text)",
                      border: "1px solid var(--cinaf-border)",
                    }}
                  />
                </div>

                <div className="mb-3">
                  <label className="form-label small" style={{ color: "var(--cinaf-text-muted)" }}>
                    Fin prévue (calculée)
                  </label>
                  <p
                    className="mb-0"
                    style={{
                      color: "var(--cinaf-gold)",
                      fontWeight: 600,
                      padding: "0.5rem 0.75rem",
                      background: "var(--cinaf-surface-2)",
                      border: "1px dashed var(--cinaf-border)",
                      borderRadius: 6,
                    }}
                  >
                    <i className="bi bi-calendar-check me-2" />
                    {previewEndsAt
                      ? previewEndsAt.toLocaleDateString("fr-FR")
                      : "—"}
                  </p>
                </div>

                <div className="mb-3 small" style={{ color: "var(--cinaf-text-muted)" }}>
                  <strong style={{ color: "var(--cinaf-text)" }}>Statut : </strong>
                  <span style={{ color: "#8fd68f" }}>{currentSub.status}</span>
                  {currentSub.canceledAt && (
                    <span className="badge bg-warning text-dark ms-2">
                      <i className="bi bi-clock-history me-1" />
                      Résiliation programmée
                    </span>
                  )}
                </div>

                <button
                  type="button"
                  onClick={saveSubscription}
                  disabled={subPending || !isDirty}
                  className="btn btn-cinaf w-100 mb-2"
                >
                  {subPending && (
                    <span className="spinner-border spinner-border-sm me-2" role="status" />
                  )}
                  <i className="bi bi-save me-1" />
                  Enregistrer les modifications
                </button>

                {/* Si une résiliation est déjà programmée, on propose de l'annuler. */}
                {currentSub.canceledAt &&
                currentSub.status?.toUpperCase() === "ACTIVE" ? (
                  <button
                    type="button"
                    onClick={() => setConfirmMode("resume")}
                    disabled={subPending}
                    className="btn w-100"
                    style={{
                      background: "var(--cinaf-gold)",
                      color: "#000",
                      border: "none",
                    }}
                  >
                    <i className="bi bi-arrow-counterclockwise me-1" />
                    Annuler la résiliation
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={() => setConfirmMode("cancel")}
                    disabled={subPending}
                    className="btn w-100"
                    style={{
                      background: "#5a2020",
                      color: "#ff8a8a",
                      border: "none",
                    }}
                  >
                    <i className="bi bi-x-circle me-1" />
                    Résilier l&apos;abonnement
                  </button>
                )}
              </>
            ) : (
              <>
                <p className="small" style={{ color: "var(--cinaf-text-muted)" }}>
                  Aucun abonnement actif. Sélectionnez un plan pour activer l&apos;accès au
                  catalogue vidéo.
                </p>

                {plans.length === 0 ? (
                  <p style={{ color: "#ff8a8a" }}>
                    <i className="bi bi-exclamation-triangle me-1" />
                    Aucun plan disponible côté backend.
                  </p>
                ) : (
                  <>
                    <select
                      className="form-select mb-2"
                      value={selectedPlanId}
                      onChange={(e) => setSelectedPlanId(e.target.value)}
                      style={{
                        background: "var(--cinaf-surface-2)",
                        color: "var(--cinaf-text)",
                        border: "1px solid var(--cinaf-border)",
                      }}
                    >
                      {plans.map((p) => (
                        <option key={p.id} value={p.id}>
                          {p.name} · {p.formattedPrice} /{" "}
                          {p.billingInterval === "year" ? "an" : "mois"}
                        </option>
                      ))}
                    </select>
                    <button
                      type="button"
                      onClick={assignSubscription}
                      disabled={subPending || !selectedPlanId}
                      className="btn btn-cinaf w-100"
                    >
                      {subPending && (
                        <span className="spinner-border spinner-border-sm me-2" role="status" />
                      )}
                      <i className="bi bi-check-circle me-1" />
                      Activer l&apos;abonnement
                    </button>
                  </>
                )}
              </>
            )}

            {subMessage && (
              <div
                className="mt-3 small"
                style={{
                  color: subMessage.kind === "ok" ? "#8fd68f" : "#ff8a8a",
                }}
              >
                <i
                  className={`bi ${
                    subMessage.kind === "ok" ? "bi-check-circle" : "bi-exclamation-triangle"
                  } me-1`}
                />
                {subMessage.text}
              </div>
            )}
          </section>
        </div>

        {/* Historique paiements Stripe — pleine largeur sous les deux colonnes. */}
        <div className="col-12">
          <section
            className="p-4"
            style={{
              background: "var(--cinaf-surface)",
              border: "1px solid var(--cinaf-border)",
              borderRadius: 10,
            }}
          >
            <h5 className="mb-3" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
              <i className="bi bi-receipt me-2" style={{ color: "var(--cinaf-gold)" }} />
              Historique des paiements
            </h5>
            <PaymentsHistoryTable
              payments={payments}
              loading={paymentsLoading}
              error={paymentsError}
            />
          </section>
        </div>
      </div>

      {/* Modal de confirmation : résiliation différée OU annulation de cette résiliation. */}
      {confirmMode && (
        <SubscriptionConfirmModal
          mode={confirmMode}
          endsAt={currentSub?.endsAt ?? null}
          pending={subPending}
          onCancel={() => !subPending && setConfirmMode(null)}
          onConfirm={confirmSubscriptionAction}
        />
      )}
    </div>
  );
}

// ─── Sous-composant : modal de confirmation admin ───────────

interface SubscriptionConfirmModalProps {
  mode: "cancel" | "resume";
  endsAt: string | null;
  pending: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}

/**
 * Modal Bootstrap pour confirmer une résiliation différée (ou son annulation)
 * côté admin. Reprend la sémantique différée : aucun prélèvement futur,
 * accès maintenu jusqu'à `endsAt`.
 */
function SubscriptionConfirmModal({
  mode,
  endsAt,
  pending,
  onCancel,
  onConfirm,
}: SubscriptionConfirmModalProps) {
  // Formatte une date ISO en chaîne longue française.
  const endsAtLabel = endsAt
    ? new Date(endsAt).toLocaleDateString("fr-FR", {
        day: "2-digit",
        month: "long",
        year: "numeric",
      })
    : "—";
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
                ? "Résilier l'abonnement de cet utilisateur ?"
                : "Annuler la résiliation ?"}
            </h5>
          </div>
          <div className="modal-body" style={{ color: "var(--cinaf-text-muted)" }}>
            {isCancel ? (
              <>
                <p className="mb-2">
                  Aucun prélèvement futur ne sera effectué.
                </p>
                <p className="mb-0">
                  <strong style={{ color: "var(--cinaf-text)" }}>
                    L&apos;utilisateur conserve l&apos;accès au catalogue CINAF
                    jusqu&apos;au {endsAtLabel}.
                  </strong>
                </p>
              </>
            ) : (
              <>
                <p className="mb-2">
                  L&apos;abonnement reprendra son cycle de facturation normal.
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
              className={`btn btn-sm ${isCancel ? "btn-warning" : "btn-cinaf"}`}
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
