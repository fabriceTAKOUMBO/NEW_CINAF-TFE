"use client";

// ============================================================
// CINAF v2 — Page Profil utilisateur
// Cette route est protégée : elle redirige vers /login si l'utilisateur n'est pas authentifié.
// Elle permet de visualiser ses infos, de modifier son nom/prénom et de supprimer son compte.
// ============================================================

import { useState, useEffect, type FormEvent, type ChangeEvent } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useAuth } from "@/lib/auth";
import { users, subscriptions, payments as paymentsApi } from "@/lib/api";
import type { ApiError, UserSubscription, PaymentRecord } from "@/lib/api";

// Structure locale pour le formulaire de mise à jour du profil
interface ProfileForm {
  firstName: string;
  lastName: string;
}

// Correspondance entre les rôles techniques Symfony et les libellés affichables
const ROLE_LABELS: Record<string, string> = {
  ROLE_USER: "Utilisateur",
  ROLE_ABONNE: "Abonné",
  ROLE_CREATEUR: "Créateur",
  ROLE_MODERATEUR: "Modérateur",
  ROLE_ADMIN: "Administrateur",
};

/**
 * Détermine le rôle le plus élevé à afficher selon la hiérarchie définie.
 */
function getHighestRole(roles: string[]): string {
  const priority = ["ROLE_ADMIN", "ROLE_MODERATEUR", "ROLE_CREATEUR", "ROLE_ABONNE", "ROLE_USER"];
  for (const r of priority) {
    if (roles.includes(r)) return ROLE_LABELS[r] ?? r;
  }
  return "Utilisateur";
}

export default function ProfilePage() {
  const { user, isAuthenticated, isLoading, logout } = useAuth();
  const router = useRouter();

  // États pour le formulaire et le suivi des actions (sauvegarde, suppression)
  const [form, setForm] = useState<ProfileForm>({ firstName: "", lastName: "" });
  const [isSaving, setIsSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saveSuccess, setSaveSuccess] = useState(false);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  // États pour l'abonnement et les paiements
  const [currentSub, setCurrentSub] = useState<UserSubscription | null>(null);
  const [paymentHistory, setPaymentHistory] = useState<PaymentRecord[]>([]);
  const [isCanceling, setIsCanceling] = useState(false);
  const [cancelMessage, setCancelMessage] = useState<string | null>(null);

  /**
   * Sécurité : redirection vers la connexion si l'utilisateur n'est pas logué.
   */
  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace("/login");
    }
  }, [isLoading, isAuthenticated, router]);

  /**
   * Pré-remplissage du formulaire avec les données actuelles de l'utilisateur.
   */
  useEffect(() => {
    if (user) {
      setForm({ firstName: user.firstName, lastName: user.lastName });
    }
  }, [user]);

  /**
   * Chargement de l'abonnement actif et de l'historique des paiements.
   */
  useEffect(() => {
    if (!user) return;

    subscriptions.getCurrent().then(setCurrentSub).catch(() => {});
    paymentsApi.getHistory().then(setPaymentHistory).catch(() => {});
  }, [user]);

  // Affichage d'un spinner pendant le chargement initial ou la vérification d'auth
  if (isLoading || !isAuthenticated || !user) {
    return (
      <div className="d-flex justify-content-center align-items-center" style={{ minHeight: "60vh" }}>
        <div className="spinner-border text-warning" role="status">
          <span className="visually-hidden">Chargement...</span>
        </div>
      </div>
    );
  }

  // Génération des initiales pour l'avatar par défaut
  const initials = `${user.firstName.charAt(0)}${user.lastName.charAt(0)}`.toUpperCase();
  // Formatage de la date de création du compte
  const memberSince = new Date(user.createdAt).toLocaleDateString("fr-FR", {
    year: "numeric", month: "long", day: "numeric",
  });

  /**
   * Gère les changements dans les inputs du profil.
   */
  const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setForm((prev) => ({ ...prev, [name]: value }));
    setSaveError(null);
    setSaveSuccess(false);
  };

  /**
   * Enregistre les modifications du profil auprès de l'API.
   */
  const handleSave = async (e: FormEvent) => {
    e.preventDefault();
    // Validation basique
    if (!form.firstName.trim() || !form.lastName.trim()) {
      setSaveError("Le prénom et le nom sont obligatoires.");
      return;
    }
    setIsSaving(true);
    setSaveError(null);
    setSaveSuccess(false);
    try {
      // Appel PATCH au backend via le module users de l'API
      await users.updateProfile(user.id, { firstName: form.firstName.trim(), lastName: form.lastName.trim() });
      setSaveSuccess(true);
    } catch (err) {
      const apiErr = err as ApiError;
      setSaveError(apiErr.message ?? "Une erreur est survenue.");
    } finally {
      setIsSaving(false);
    }
  };

  /**
   * Annule l'abonnement actif de l'utilisateur.
   */
  const handleCancelSubscription = async () => {
    setIsCanceling(true);
    setCancelMessage(null);
    try {
      const result = await subscriptions.cancel();
      setCancelMessage(result.message);
      // Recharger l'abonnement courant
      const updated = await subscriptions.getCurrent();
      setCurrentSub(updated);
    } catch (err) {
      const apiErr = err as ApiError;
      setCancelMessage(apiErr.message || "Erreur lors de l'annulation.");
    } finally {
      setIsCanceling(false);
    }
  };

  /**
   * Gère la suppression définitive du compte utilisateur (RGPD).
   */
  const handleDelete = async () => {
    setIsDeleting(true);
    try {
      // Appel DELETE au backend
      await users.deleteAccount(user.id);
      // Déconnexion locale (await pour garantir le nettoyage des tokens) puis redirection
      await logout();
      router.replace("/");
    } catch {
      setIsDeleting(false);
      setShowDeleteModal(false);
    }
  };

  return (
    <div className="container py-5" style={{ maxWidth: 760 }}>
      {/* En-tête du profil avec avatar généré et rôle */}
      <div className="d-flex align-items-center gap-4 mb-5">
        <div
          className="rounded-circle d-flex align-items-center justify-content-center fw-bold fs-3"
          style={{
            width: 80, height: 80,
            background: "linear-gradient(135deg, #c8a84b, #a0783a)",
            color: "#000", flexShrink: 0,
          }}
        >
          {initials}
        </div>
        <div>
          <h1 className="fw-bold mb-1" style={{ color: "var(--cinaf-text)" }}>
            {user.firstName} {user.lastName}
          </h1>
          <span
            className="badge px-3 py-1"
            style={{ background: "var(--cinaf-gold)", color: "#000", fontWeight: 600 }}
          >
            {getHighestRole(user.roles)}
          </span>
        </div>
      </div>

      {/* Section des informations statiques du compte */}
      <div className="card border-0 mb-4 p-4" style={{ background: "var(--cinaf-surface)", borderRadius: 12 }}>
        <h5 className="fw-semibold mb-3" style={{ color: "var(--cinaf-gold)" }}>
          <i className="bi bi-person-circle me-2" />
          Informations du compte
        </h5>
        <div className="row g-3">
          <div className="col-sm-6">
            <small style={{ color: "var(--cinaf-text-muted)" }}>Email</small>
            <p className="mb-0 fw-medium" style={{ color: "var(--cinaf-text)" }}>{user.email}</p>
          </div>
          <div className="col-sm-6">
            <small style={{ color: "var(--cinaf-text-muted)" }}>Membre depuis</small>
            <p className="mb-0 fw-medium" style={{ color: "var(--cinaf-text)" }}>{memberSince}</p>
          </div>
          <div className="col-sm-6">
            <small style={{ color: "var(--cinaf-text-muted)" }}>Statut</small>
            <p className="mb-0">
              {user.isVerified
                ? <span className="text-success fw-medium"><i className="bi bi-check-circle-fill me-1" />Compte vérifié</span>
                : <span className="text-warning fw-medium"><i className="bi bi-exclamation-circle-fill me-1" />Email non vérifié</span>}
            </p>
          </div>
        </div>
      </div>

      {/* Formulaire permettant de modifier les champs éditables */}
      <div className="card border-0 mb-4 p-4" style={{ background: "var(--cinaf-surface)", borderRadius: 12 }}>
        <h5 className="fw-semibold mb-3" style={{ color: "var(--cinaf-gold)" }}>
          <i className="bi bi-pencil-square me-2" />
          Modifier le profil
        </h5>
        <form onSubmit={handleSave} noValidate>
          <div className="row g-3 mb-3">
            <div className="col-sm-6">
              <label htmlFor="firstName" className="form-label" style={{ color: "var(--cinaf-text-muted)" }}>
                Prénom
              </label>
              <input
                type="text"
                id="firstName"
                name="firstName"
                className="form-control cinaf-input"
                value={form.firstName}
                onChange={handleChange}
                required
              />
            </div>
            <div className="col-sm-6">
              <label htmlFor="lastName" className="form-label" style={{ color: "var(--cinaf-text-muted)" }}>
                Nom
              </label>
              <input
                type="text"
                id="lastName"
                name="lastName"
                className="form-control cinaf-input"
                value={form.lastName}
                onChange={handleChange}
                required
              />
            </div>
          </div>

          {saveError && (
            <div className="alert alert-danger py-2 mb-3" role="alert">
              <i className="bi bi-exclamation-triangle-fill me-2" />
              {saveError}
            </div>
          )}
          {saveSuccess && (
            <div className="alert alert-success py-2 mb-3" role="alert">
              <i className="bi bi-check-circle-fill me-2" />
              Profil mis à jour avec succès.
            </div>
          )}

          <button
            type="submit"
            className="btn fw-semibold px-4"
            disabled={isSaving}
            style={{ background: "var(--cinaf-gold)", color: "#000" }}
          >
            {isSaving ? (
              <><span className="spinner-border spinner-border-sm me-2" />Enregistrement...</>
            ) : (
              <><i className="bi bi-check-lg me-2" />Enregistrer</>
            )}
          </button>
        </form>
      </div>

      {/* Section Mon Abonnement */}
      <div className="card border-0 mb-4 p-4" style={{ background: "var(--cinaf-surface)", borderRadius: 12 }}>
        <h5 className="fw-semibold mb-3" style={{ color: "var(--cinaf-gold)" }}>
          <i className="bi bi-credit-card-2-front-fill me-2" />
          Mon Abonnement
        </h5>

        {currentSub ? (
          <div>
            <div className="row g-3 mb-3">
              <div className="col-sm-6">
                <small style={{ color: "var(--cinaf-text-muted)" }}>Plan</small>
                <p className="mb-0 fw-medium" style={{ color: "var(--cinaf-text)" }}>
                  {currentSub.plan.name} — {currentSub.plan.formattedPrice}/{currentSub.plan.billingInterval === "month" ? "mois" : "an"}
                </p>
              </div>
              <div className="col-sm-6">
                <small style={{ color: "var(--cinaf-text-muted)" }}>Statut</small>
                <p className="mb-0">
                  {(() => {
                    // Le backend renvoie le statut en MAJUSCULES (ACTIVE / CANCELED / EXPIRED).
                    // Une résiliation programmée reste ACTIVE avec canceledAt + endsAt futur
                    // (même règle que la page /mon-abonnement, source de vérité).
                    const st = (currentSub.status ?? "").toUpperCase();
                    const scheduledCancel =
                      st === "ACTIVE" &&
                      !!currentSub.canceledAt &&
                      !!currentSub.endsAt &&
                      new Date(currentSub.endsAt) > new Date();
                    if (scheduledCancel) {
                      return <span className="badge bg-warning text-dark">Résiliation programmée</span>;
                    }
                    if (st === "ACTIVE") {
                      return <span className="badge bg-success">Actif</span>;
                    }
                    if (st === "EXPIRED") {
                      return <span className="badge bg-secondary">Expiré</span>;
                    }
                    return <span className="badge bg-secondary">{currentSub.status}</span>;
                  })()}
                </p>
              </div>
              <div className="col-sm-6">
                <small style={{ color: "var(--cinaf-text-muted)" }}>Début</small>
                <p className="mb-0 fw-medium" style={{ color: "var(--cinaf-text)" }}>
                  {new Date(currentSub.startsAt).toLocaleDateString("fr-FR")}
                </p>
              </div>
              {currentSub.endsAt && (
                <div className="col-sm-6">
                  <small style={{ color: "var(--cinaf-text-muted)" }}>
                    {currentSub.canceledAt ? "Accès jusqu'au" : "Prochain renouvellement"}
                  </small>
                  <p className="mb-0 fw-medium" style={{ color: "var(--cinaf-text)" }}>
                    {new Date(currentSub.endsAt).toLocaleDateString("fr-FR")}
                  </p>
                </div>
              )}
            </div>

            {cancelMessage && (
              <div className="alert alert-info py-2 mb-3">
                <i className="bi bi-info-circle-fill me-2" />
                {cancelMessage}
              </div>
            )}

            {!currentSub.canceledAt && (
              <button
                className="btn btn-outline-warning btn-sm"
                onClick={handleCancelSubscription}
                disabled={isCanceling}
              >
                {isCanceling ? (
                  <><span className="spinner-border spinner-border-sm me-2" />Annulation...</>
                ) : (
                  <><i className="bi bi-x-circle me-2" />Annuler l&apos;abonnement</>
                )}
              </button>
            )}
          </div>
        ) : (
          <div>
            <p style={{ color: "var(--cinaf-text-muted)" }} className="mb-3">
              Vous n&apos;avez pas d&apos;abonnement actif.
            </p>
            <Link
              href="/abonnement"
              className="btn fw-semibold px-4"
              style={{ background: "var(--cinaf-gold)", color: "#000" }}
            >
              <i className="bi bi-star-fill me-2" />
              Découvrir les plans
            </Link>
          </div>
        )}
      </div>

      {/* Section Historique des paiements */}
      {paymentHistory.length > 0 && (
        <div className="card border-0 mb-4 p-4" style={{ background: "var(--cinaf-surface)", borderRadius: 12 }}>
          <h5 className="fw-semibold mb-3" style={{ color: "var(--cinaf-gold)" }}>
            <i className="bi bi-receipt me-2" />
            Historique des paiements
          </h5>
          <div className="table-responsive">
            <table className="table table-dark table-hover mb-0" style={{ background: "transparent" }}>
              <thead>
                <tr style={{ color: "var(--cinaf-text-muted)", borderBottom: "1px solid rgba(255,255,255,0.1)" }}>
                  <th>Date</th>
                  <th>Montant</th>
                  <th>Statut</th>
                  <th>Facture</th>
                </tr>
              </thead>
              <tbody>
                {paymentHistory.map((payment) => (
                  <tr key={payment.id} style={{ borderBottom: "1px solid rgba(255,255,255,0.05)" }}>
                    <td style={{ color: "var(--cinaf-text)" }}>
                      {payment.paidAt
                        ? new Date(payment.paidAt).toLocaleDateString("fr-FR")
                        : new Date(payment.createdAt).toLocaleDateString("fr-FR")}
                    </td>
                    <td style={{ color: "var(--cinaf-text)" }}>{payment.formattedAmount}</td>
                    <td>
                      <span className={`badge ${payment.status === "succeeded" ? "bg-success" : payment.status === "failed" ? "bg-danger" : "bg-secondary"}`}>
                        {payment.status === "succeeded" ? "Payé" : payment.status === "failed" ? "Échoué" : payment.status}
                      </span>
                    </td>
                    <td>
                      {payment.invoice?.invoicePdf ? (
                        <a
                          href={payment.invoice.invoicePdf}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="btn btn-outline-light btn-sm"
                        >
                          <i className="bi bi-download me-1" />
                          PDF
                        </a>
                      ) : (
                        <span style={{ color: "var(--cinaf-text-muted)" }}>—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Zone de danger pour les actions irréversibles (suppression de compte) */}
      <div
        className="card border-0 p-4"
        style={{ background: "var(--cinaf-surface)", borderRadius: 12, border: "1px solid #3a1a1a !important" }}
      >
        <h5 className="fw-semibold mb-1 text-danger">
          <i className="bi bi-shield-exclamation me-2" />
          Zone dangereuse
        </h5>
        <p style={{ color: "var(--cinaf-text-muted)", fontSize: "0.9rem" }} className="mb-3">
          La suppression de votre compte est irréversible. Toutes vos données seront effacées (RGPD — droit à l&apos;oubli).
        </p>
        <button
          className="btn btn-outline-danger btn-sm"
          onClick={() => setShowDeleteModal(true)}
        >
          <i className="bi bi-trash3-fill me-2" />
          Supprimer mon compte
        </button>
      </div>

      {/* Modal de confirmation pour éviter les suppressions accidentelles */}
      {showDeleteModal && (
        <div
          className="modal d-block"
          style={{ background: "rgba(0,0,0,0.75)" }}
          onClick={() => !isDeleting && setShowDeleteModal(false)}
        >
          <div className="modal-dialog modal-dialog-centered" onClick={(e) => e.stopPropagation()}>
            <div className="modal-content border-0" style={{ background: "var(--cinaf-surface)" }}>
              <div className="modal-header border-0 pb-0">
                <h5 className="modal-title text-danger">
                  <i className="bi bi-exclamation-triangle-fill me-2" />
                  Confirmer la suppression
                </h5>
              </div>
              <div className="modal-body" style={{ color: "var(--cinaf-text-muted)" }}>
                Êtes-vous sûr de vouloir supprimer votre compte ?
                Cette action est <strong className="text-danger">irréversible</strong>.
              </div>
              <div className="modal-footer border-0">
                <button
                  className="btn btn-outline-secondary btn-sm"
                  onClick={() => setShowDeleteModal(false)}
                  disabled={isDeleting}
                >
                  Annuler
                </button>
                <button
                  className="btn btn-danger btn-sm"
                  onClick={handleDelete}
                  disabled={isDeleting}
                >
                  {isDeleting
                    ? <><span className="spinner-border spinner-border-sm me-2" />Suppression...</>
                    : <><i className="bi bi-trash3-fill me-2" />Confirmer la suppression</>}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
