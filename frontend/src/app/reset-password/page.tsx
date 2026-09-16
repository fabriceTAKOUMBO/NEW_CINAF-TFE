"use client";

// ============================================================
// CINAF v2 — Page Réinitialisation du mot de passe
// Cette page est accédée via un lien envoyé par email.
// Elle lit le token depuis l'URL (?token=xxx) pour valider l'action.
// ============================================================

import { Suspense, useState, useEffect, type FormEvent, type ChangeEvent } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { auth } from "@/lib/api";
import type { ApiError } from "@/lib/api";

// Structure des données pour le nouveau mot de passe
interface ResetForm {
  password: string;
  confirmPassword: string;
}

// Structure des erreurs de validation locales
interface FormErrors {
  password?: string;
  confirmPassword?: string;
}

/**
 * Valide la complexité du nouveau mot de passe et sa confirmation.
 */
function validate(form: ResetForm): FormErrors {
  const errors: FormErrors = {};
  if (!form.password) {
    errors.password = "Le mot de passe est requis.";
  } else if (form.password.length < 8) {
    errors.password = "Le mot de passe doit contenir au moins 8 caractères.";
  }
  if (!form.confirmPassword) {
    errors.confirmPassword = "Veuillez confirmer le mot de passe.";
  } else if (form.password !== form.confirmPassword) {
    errors.confirmPassword = "Les mots de passe ne correspondent pas.";
  }
  return errors;
}

/**
 * Page de réinitialisation de mot de passe enveloppée dans un Suspense.
 * Requis par Next.js App Router pour sécuriser la lecture des SearchParams (`?token=`).
 */
export default function ResetPasswordPage() {
  return (
    <Suspense fallback={<div className="d-flex justify-content-center py-5"><div className="spinner-border text-warning" /></div>}>
      <ResetPasswordContent />
    </Suspense>
  );
}

/**
 * Contenu interactif de réinitialisation de mot de passe.
 * 
 * Fonctionnalités :
 * - Extrait le jeton de sécurité (`token`) passé en paramètre d'URL.
 * - Valide la saisie et la confirmation du nouveau mot de passe.
 * - Appelle l'API `auth.resetPassword(token, password)`.
 * - Redirige automatiquement l'utilisateur vers `/login` après 3 secondes en cas de succès.
 * 
 * @returns Le formulaire de saisie du nouveau mot de passe ou un message d'erreur si le token est manquant.
 */
function ResetPasswordContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  // Récupération du jeton de sécurité transmis dans l'URL
  const token = searchParams.get("token");

  // États de gestion du formulaire et de l'affichage
  const [form, setForm] = useState<ResetForm>({ password: "", confirmPassword: "" });
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<FormErrors>({});
  const [serverError, setServerError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  /**
   * Effet de redirection automatique après un succès.
   * L'utilisateur est renvoyé vers la connexion après 3 secondes.
   */
  useEffect(() => {
    if (success) {
      const timer = setTimeout(() => router.push("/login"), 3000);
      return () => clearTimeout(timer);
    }
  }, [success, router]);

  /**
   * Met à jour les champs et réinitialise les messages d'erreur lors de la saisie.
   */
  const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setForm((prev) => ({ ...prev, [name]: value }));
    if (fieldErrors[name as keyof FormErrors]) {
      setFieldErrors((prev) => ({ ...prev, [name]: undefined }));
    }
    if (serverError) setServerError(null);
  };

  /**
   * Soumission du nouveau mot de passe au serveur.
   */
  const handleSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setServerError(null);

    // Vérification de la présence du token indispensable
    if (!token) {
      setServerError("Lien invalide ou expiré. Veuillez refaire une demande de réinitialisation.");
      return;
    }

    // Validation client des champs
    const errors = validate(form);
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    setIsLoading(true);
    try {
      // Appel API pour finaliser le changement avec le token et le nouveau mot de passe
      await auth.resetPassword(token, form.password);
      setSuccess(true);
    } catch (err: unknown) {
      const apiErr = err as ApiError;
      // Gestion des erreurs spécifiques au token (expiration, usage unique)
      if (apiErr.statusCode === 400) {
        setServerError(
          "Le lien de réinitialisation est invalide ou a expiré. Veuillez faire une nouvelle demande."
        );
      } else {
        setServerError(
          apiErr.message || "Une erreur est survenue. Veuillez réessayer."
        );
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="auth-page">
      <div style={{ width: "100%", maxWidth: 420 }}>
        {/* Logo CINAF */}
        <div className="text-center mb-4">
          <Link href="/" className="cinaf-logo-text" style={{ textDecoration: "none" }}>
            CINAF
          </Link>
        </div>

        <div className="cinaf-card p-4">
          {/* Cas 1 : Le token est absent de l'URL, on bloque l'action */}
          {!token && !success ? (
            <div className="text-center py-2">
              <i
                className="bi bi-exclamation-triangle"
                style={{ fontSize: "2.5rem", color: "var(--cinaf-danger)", display: "block", marginBottom: "1rem" }}
              />
              <h2 style={{ fontSize: "1.25rem", fontWeight: 700, marginBottom: "0.75rem" }}>
                Lien invalide
              </h2>
              <p style={{ color: "var(--cinaf-text-muted)", fontSize: "0.875rem", marginBottom: "1.5rem" }}>
                Ce lien de réinitialisation est manquant ou invalide.
              </p>
              <Link href="/forgot-password" className="btn btn-cinaf w-100">
                Faire une nouvelle demande
              </Link>
            </div>
          ) : success ? (
            /* Cas 2 : Succès de l'opération, affichage d'un message et d'une barre de progression */
            <div className="text-center py-2">
              <div
                style={{
                  width: 56,
                  height: 56,
                  borderRadius: "50%",
                  background: "rgba(39, 174, 96, 0.15)",
                  border: "2px solid rgba(39, 174, 96, 0.4)",
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  margin: "0 auto 1.25rem",
                }}
              >
                <i
                  className="bi bi-check-circle"
                  style={{ fontSize: "1.75rem", color: "#6fcf97" }}
                />
              </div>
              <h2 style={{ fontSize: "1.25rem", fontWeight: 700, marginBottom: "0.75rem" }}>
                Mot de passe modifié !
              </h2>
              <p style={{ color: "var(--cinaf-text-muted)", fontSize: "0.875rem", marginBottom: "1.5rem" }}>
                Votre mot de passe a été réinitialisé avec succès.
                Vous allez être redirigé vers la page de connexion…
              </p>
              {/* Animation CSS pour la barre de progression de redirection */}
              <div
                style={{
                  height: 3,
                  borderRadius: 2,
                  backgroundColor: "var(--cinaf-border)",
                  overflow: "hidden",
                  marginBottom: "1.5rem",
                }}
              >
                <div
                  style={{
                    height: "100%",
                    backgroundColor: "var(--cinaf-gold)",
                    animation: "progressBar 3s linear forwards",
                  }}
                />
              </div>
              <style>{`
                @keyframes progressBar {
                  from { width: 0% }
                  to { width: 100% }
                }
              `}</style>
              <Link href="/login" className="btn btn-cinaf w-100">
                <i className="bi bi-arrow-left me-2" />
                Se connecter maintenant
              </Link>
            </div>
          ) : (
            /* Cas 3 : Formulaire par défaut */
            <>
              <h1
                className="mb-1"
                style={{ fontSize: "1.4rem", fontWeight: 700, color: "var(--cinaf-text)" }}
              >
                Nouveau mot de passe
              </h1>
              <p
                style={{
                  color: "var(--cinaf-text-muted)",
                  fontSize: "0.875rem",
                  marginBottom: "1.5rem",
                }}
              >
                Choisissez un mot de passe fort d&apos;au moins 8 caractères.
              </p>

              {/* Erreurs renvoyées par le backend */}
              {serverError && (
                <div className="alert-cinaf-error p-3 mb-3 d-flex align-items-center gap-2">
                  <i className="bi bi-exclamation-circle-fill" />
                  <span style={{ fontSize: "0.875rem" }}>{serverError}</span>
                </div>
              )}

              <form onSubmit={handleSubmit} noValidate>
                {/* Champ Nouveau mot de passe */}
                <div className="mb-3">
                  <label htmlFor="password" className="form-label">
                    Nouveau mot de passe
                  </label>
                  <div className="input-group">
                    <span
                      className="input-group-text"
                      style={{
                        backgroundColor: "var(--cinaf-surface-2)",
                        borderColor: fieldErrors.password ? "var(--cinaf-danger)" : "var(--cinaf-border)",
                        color: "var(--cinaf-text-muted)",
                      }}
                    >
                      <i className="bi bi-lock" />
                    </span>
                    <input
                      id="password"
                      type={showPassword ? "text" : "password"}
                      name="password"
                      className={`form-control${fieldErrors.password ? " is-invalid" : ""}`}
                      placeholder="8 caractères minimum"
                      value={form.password}
                      onChange={handleChange}
                      autoComplete="new-password"
                      disabled={isLoading}
                    />
                    <button
                      type="button"
                      className="btn password-toggle-btn"
                      onClick={() => setShowPassword((v) => !v)}
                      tabIndex={-1}
                      aria-label="Toggle password visibility"
                    >
                      <i className={`bi bi-eye${showPassword ? "-slash" : ""}`} />
                    </button>
                    {fieldErrors.password && (
                      <div className="invalid-feedback" style={{ color: "var(--cinaf-danger)" }}>
                        {fieldErrors.password}
                      </div>
                    )}
                  </div>
                </div>

                {/* Champ Confirmation du nouveau mot de passe */}
                <div className="mb-4">
                  <label htmlFor="confirmPassword" className="form-label">
                    Confirmer le mot de passe
                  </label>
                  <div className="input-group">
                    <span
                      className="input-group-text"
                      style={{
                        backgroundColor: "var(--cinaf-surface-2)",
                        borderColor: fieldErrors.confirmPassword ? "var(--cinaf-danger)" : "var(--cinaf-border)",
                        color: "var(--cinaf-text-muted)",
                      }}
                    >
                      <i className="bi bi-lock-fill" />
                    </span>
                    <input
                      id="confirmPassword"
                      type={showConfirm ? "text" : "password"}
                      name="confirmPassword"
                      className={`form-control${fieldErrors.confirmPassword ? " is-invalid" : ""}`}
                      placeholder="Répétez le mot de passe"
                      value={form.confirmPassword}
                      onChange={handleChange}
                      autoComplete="new-password"
                      disabled={isLoading}
                    />
                    <button
                      type="button"
                      className="btn password-toggle-btn"
                      onClick={() => setShowConfirm((v) => !v)}
                      tabIndex={-1}
                      aria-label="Toggle confirm password visibility"
                    >
                      <i className={`bi bi-eye${showConfirm ? "-slash" : ""}`} />
                    </button>
                    {fieldErrors.confirmPassword && (
                      <div className="invalid-feedback" style={{ color: "var(--cinaf-danger)" }}>
                        {fieldErrors.confirmPassword}
                      </div>
                    )}
                  </div>
                </div>

                {/* Bouton de validation final */}
                <button
                  type="submit"
                  className="btn btn-cinaf w-100 py-2"
                  disabled={isLoading}
                >
                  {isLoading ? (
                    <>
                      <span
                        className="spinner-border spinner-border-sm me-2"
                        role="status"
                        aria-hidden="true"
                      />
                      Modification en cours…
                    </>
                  ) : (
                    <>
                      <i className="bi bi-shield-check me-2" />
                      Définir le nouveau mot de passe
                    </>
                  )}
                </button>
              </form>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
