"use client";

// ============================================================
// CINAF v2 — Page Inscription
// Permet la création d'un nouveau compte utilisateur.
// Gère la validation côté client et la force du mot de passe.
// ============================================================

import { useState, type FormEvent, type ChangeEvent } from "react";
import Link from "next/link";
import { useAuth } from "@/lib/auth";
import type { ApiError } from "@/lib/api";

// Interface représentant les données du formulaire d'inscription
interface RegisterForm {
  firstName: string;
  lastName: string;
  email: string;
  password: string;
  confirmPassword: string;
  consentRgpd: boolean;
}

// Interface pour les erreurs de validation par champ
interface FormErrors {
  firstName?: string;
  lastName?: string;
  email?: string;
  password?: string;
  confirmPassword?: string;
  consentRgpd?: string;
}

/**
 * Fonction utilitaire de validation des données du formulaire.
 * Vérifie le format de l'email, la longueur du mot de passe et la correspondance des confirmations.
 * 
 * @param form - Les champs saisis par l'utilisateur lors de l'inscription.
 * @returns Un dictionnaire des erreurs de validation par champ, le cas échéant.
 */
function validateForm(form: RegisterForm): FormErrors {
  const errors: FormErrors = {};

  if (!form.firstName.trim()) errors.firstName = "Le prénom est requis.";
  if (!form.lastName.trim()) errors.lastName = "Le nom est requis.";

  if (!form.email.trim()) {
    errors.email = "L'email est requis.";
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
    errors.email = "Veuillez saisir un email valide.";
  }

  if (!form.password) {
    errors.password = "Le mot de passe est requis.";
  } else if (form.password.length < 8) {
    errors.password = "Le mot de passe doit contenir au moins 8 caractères.";
  }

  if (!form.confirmPassword) {
    errors.confirmPassword = "Veuillez confirmer votre mot de passe.";
  } else if (form.password !== form.confirmPassword) {
    errors.confirmPassword = "Les mots de passe ne correspondent pas.";
  }

  if (!form.consentRgpd) {
    errors.consentRgpd = "Vous devez accepter les CGU pour vous inscrire.";
  }

  return errors;
}

/**
 * Page d'inscription pour les nouveaux utilisateurs de CINAF.
 * 
 * Fonctionnalités :
 * - Formulaire complet : prénom, nom, email, mot de passe et confirmation.
 * - Validation en temps réel côté client et jauge dynamique de force du mot de passe.
 * - Gestion de l'acceptation RGPD des conditions générales d'utilisation.
 * - Appelle la méthode `register` de `AuthProvider`.
 * - Affiche une vue de félicitations invitant l'utilisateur à vérifier sa boîte mail.
 * 
 * @returns Le composant de page d'inscription.
 */
export default function RegisterPage() {
  // Récupération de la méthode register du contexte d'authentification
  const { register } = useAuth();

  // Initialisation de l'état du formulaire
  const [form, setForm] = useState<RegisterForm>({
    firstName: "",
    lastName: "",
    email: "",
    password: "",
    confirmPassword: "",
    consentRgpd: false,
  });

  // États pour la gestion de l'UI et des retours API
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<FormErrors>({});

  /**
   * Met à jour les champs du formulaire lors de la saisie.
   * Gère les inputs classiques et les checkboxes.
   */
  const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
    const { name, value, type, checked } = e.target;
    setForm((prev) => ({
      ...prev,
      [name]: type === "checkbox" ? checked : value,
    }));
    // Effacer l'erreur spécifique du champ lors de la modification
    if (fieldErrors[name as keyof FormErrors]) {
      setFieldErrors((prev) => ({ ...prev, [name]: undefined }));
    }
    if (serverError) setServerError(null);
  };

  /**
   * Gère la soumission du formulaire d'inscription.
   * Exécute la validation client avant d'appeler l'API.
   */
  const handleSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setServerError(null);
    setSuccessMessage(null);

    // Validation des données côté client
    const errors = validateForm(form);
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    setIsLoading(true);
    try {
      // Appel du service d'inscription
      const response = await register({
        firstName: form.firstName.trim(),
        lastName: form.lastName.trim(),
        email: form.email.trim(),
        password: form.password,
        consentRgpd: form.consentRgpd,
      });
      // Message affiché en cas de succès total (nécessite souvent une activation par email)
      setSuccessMessage(
        response.message ||
          "Inscription réussie ! Vérifiez votre email pour activer votre compte."
      );
    } catch (err: unknown) {
      const apiErr = err as ApiError;
      // Gestion spécifique du cas où l'email est déjà pris (HTTP 409 Conflict)
      if (apiErr.statusCode === 409 || apiErr.message?.toLowerCase().includes("exist")) {
        setServerError("Un compte avec cet email existe déjà.");
      } else {
        setServerError(
          apiErr.message || "Une erreur est survenue. Veuillez réessayer."
        );
      }
    } finally {
      setIsLoading(false);
    }
  };

  /**
   * Calcule un score de complexité du mot de passe pour donner un retour visuel à l'utilisateur.
   */
  const getPasswordStrength = (): { label: string; color: string; width: string } => {
    const p = form.password;
    if (!p) return { label: "", color: "transparent", width: "0%" };
    let score = 0;
    if (p.length >= 8) score++;
    if (p.length >= 12) score++;
    if (/[A-Z]/.test(p)) score++;
    if (/[0-9]/.test(p)) score++;
    if (/[^A-Za-z0-9]/.test(p)) score++;

    if (score <= 1) return { label: "Faible", color: "var(--cinaf-danger)", width: "25%" };
    if (score === 2) return { label: "Moyen", color: "#f39c12", width: "50%" };
    if (score === 3) return { label: "Bien", color: "#27ae60", width: "75%" };
    return { label: "Fort", color: "#27ae60", width: "100%" };
  };

  const strength = getPasswordStrength();

  // Écran de succès affiché après une inscription réussie
  if (successMessage) {
    return (
      <div className="auth-page">
        <div style={{ width: "100%", maxWidth: 440 }}>
          <div className="cinaf-card p-4 text-center">
            <div
              style={{
                width: 64,
                height: 64,
                borderRadius: "50%",
                background: "rgba(39, 174, 96, 0.15)",
                border: "2px solid rgba(39, 174, 96, 0.4)",
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                margin: "0 auto 1.25rem",
              }}
            >
              <i className="bi bi-envelope-check" style={{ fontSize: "1.75rem", color: "#6fcf97" }} />
            </div>
            <h2 style={{ fontSize: "1.3rem", fontWeight: 700, marginBottom: "0.75rem" }}>
              Vérifiez votre email
            </h2>
            <p style={{ color: "var(--cinaf-text-muted)", fontSize: "0.9rem", lineHeight: 1.6 }}>
              {successMessage}
            </p>
            <div className="mt-4">
              <Link href="/login" className="btn btn-cinaf w-100">
                <i className="bi bi-arrow-left me-2" />
                Aller à la connexion
              </Link>
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="auth-page">
      <div style={{ width: "100%", maxWidth: 480 }}>
        {/* En-tête avec Logo */}
        <div className="text-center mb-4">
          <Link href="/" className="cinaf-logo-text" style={{ textDecoration: "none" }}>
            CINAF
          </Link>
          <p style={{ color: "var(--cinaf-text-muted)", fontSize: "0.875rem", marginTop: "0.5rem" }}>
            Rejoignez la communauté du cinéma africain
          </p>
        </div>

        <div className="cinaf-card p-4">
          <h1
            className="mb-1"
            style={{ fontSize: "1.4rem", fontWeight: 700, color: "var(--cinaf-text)" }}
          >
            Créer un compte
          </h1>
          <p style={{ color: "var(--cinaf-text-muted)", fontSize: "0.875rem", marginBottom: "1.5rem" }}>
            Gratuit et sans engagement
          </p>

          {/* Affichage des erreurs globales renvoyées par le backend */}
          {serverError && (
            <div className="alert-cinaf-error p-3 mb-3 d-flex align-items-center gap-2">
              <i className="bi bi-exclamation-circle-fill" />
              <span style={{ fontSize: "0.875rem" }}>{serverError}</span>
            </div>
          )}

          <form onSubmit={handleSubmit} noValidate>
            {/* Champs d'identité : Prénom et Nom côte à côte */}
            <div className="row g-3 mb-3">
              <div className="col-6">
                <label htmlFor="firstName" className="form-label">Prénom</label>
                <input
                  id="firstName"
                  type="text"
                  name="firstName"
                  className={`form-control${fieldErrors.firstName ? " is-invalid" : ""}`}
                  placeholder="Jean"
                  value={form.firstName}
                  onChange={handleChange}
                  autoComplete="given-name"
                  disabled={isLoading}
                />
                {fieldErrors.firstName && (
                  <div className="invalid-feedback" style={{ color: "var(--cinaf-danger)" }}>
                    {fieldErrors.firstName}
                  </div>
                )}
              </div>
              <div className="col-6">
                <label htmlFor="lastName" className="form-label">Nom</label>
                <input
                  id="lastName"
                  type="text"
                  name="lastName"
                  className={`form-control${fieldErrors.lastName ? " is-invalid" : ""}`}
                  placeholder="Dupont"
                  value={form.lastName}
                  onChange={handleChange}
                  autoComplete="family-name"
                  disabled={isLoading}
                />
                {fieldErrors.lastName && (
                  <div className="invalid-feedback" style={{ color: "var(--cinaf-danger)" }}>
                    {fieldErrors.lastName}
                  </div>
                )}
              </div>
            </div>

            {/* Champ Adresse email */}
            <div className="mb-3">
              <label htmlFor="email" className="form-label">Adresse email</label>
              <div className="input-group">
                <span
                  className="input-group-text"
                  style={{
                    backgroundColor: "var(--cinaf-surface-2)",
                    borderColor: fieldErrors.email ? "var(--cinaf-danger)" : "var(--cinaf-border)",
                    color: "var(--cinaf-text-muted)",
                  }}
                >
                  <i className="bi bi-envelope" />
                </span>
                <input
                  id="email"
                  type="email"
                  name="email"
                  className={`form-control${fieldErrors.email ? " is-invalid" : ""}`}
                  placeholder="vous@exemple.com"
                  value={form.email}
                  onChange={handleChange}
                  autoComplete="email"
                  disabled={isLoading}
                />
                {fieldErrors.email && (
                  <div className="invalid-feedback" style={{ color: "var(--cinaf-danger)" }}>
                    {fieldErrors.email}
                  </div>
                )}
              </div>
            </div>

            {/* Champ Mot de passe avec indicateur de force visuel */}
            <div className="mb-1">
              <label htmlFor="password" className="form-label">Mot de passe</label>
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

            {/* Barre de progression de la force du mot de passe */}
            {form.password && (
              <div className="mb-3">
                <div
                  style={{
                    height: 3,
                    borderRadius: 2,
                    backgroundColor: "var(--cinaf-border)",
                    marginTop: 6,
                    overflow: "hidden",
                  }}
                >
                  <div
                    style={{
                      height: "100%",
                      width: strength.width,
                      backgroundColor: strength.color,
                      transition: "width 0.3s ease",
                    }}
                  />
                </div>
                <div
                  style={{
                    fontSize: "0.75rem",
                    color: strength.color,
                    textAlign: "right",
                    marginTop: 3,
                  }}
                >
                  {strength.label}
                </div>
              </div>
            )}

            {/* Confirmation du mot de passe pour éviter les erreurs de saisie */}
            <div className="mb-3">
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
                  type={showConfirmPassword ? "text" : "password"}
                  name="confirmPassword"
                  className={`form-control${fieldErrors.confirmPassword ? " is-invalid" : ""}`}
                  placeholder="Répétez votre mot de passe"
                  value={form.confirmPassword}
                  onChange={handleChange}
                  autoComplete="new-password"
                  disabled={isLoading}
                />
                <button
                  type="button"
                  className="btn password-toggle-btn"
                  onClick={() => setShowConfirmPassword((v) => !v)}
                  tabIndex={-1}
                  aria-label="Toggle confirm password visibility"
                >
                  <i className={`bi bi-eye${showConfirmPassword ? "-slash" : ""}`} />
                </button>
                {fieldErrors.confirmPassword && (
                  <div className="invalid-feedback" style={{ color: "var(--cinaf-danger)" }}>
                    {fieldErrors.confirmPassword}
                  </div>
                )}
              </div>
            </div>

            {/* Consentement RGPD et acceptation des conditions d'utilisation */}
            <div className="mb-4">
              <div className="form-check">
                <input
                  id="consentRgpd"
                  type="checkbox"
                  name="consentRgpd"
                  className="form-check-input"
                  checked={form.consentRgpd}
                  onChange={handleChange}
                  disabled={isLoading}
                />
                <label htmlFor="consentRgpd" className="form-check-label">
                  J&apos;accepte les{" "}
                  <Link href="/cgu" style={{ color: "var(--cinaf-gold)" }}>
                    CGU
                  </Link>{" "}
                  et la{" "}
                  <Link href="/confidentialite" style={{ color: "var(--cinaf-gold)" }}>
                    politique de confidentialité
                  </Link>
                </label>
              </div>
              {fieldErrors.consentRgpd && (
                <div style={{ color: "var(--cinaf-danger)", fontSize: "0.8rem", marginTop: "0.25rem" }}>
                  {fieldErrors.consentRgpd}
                </div>
              )}
            </div>

            {/* Bouton de création de compte */}
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
                  Inscription en cours…
                </>
              ) : (
                <>
                  <i className="bi bi-person-plus me-2" />
                  Créer mon compte
                </>
              )}
            </button>
          </form>
        </div>

        {/* Lien de redirection vers la connexion pour les utilisateurs déjà inscrits */}
        <p className="text-center mt-3" style={{ color: "var(--cinaf-text-muted)", fontSize: "0.875rem" }}>
          Déjà un compte ?{" "}
          <Link href="/login" style={{ color: "var(--cinaf-gold)", fontWeight: 600 }}>
            Se connecter
          </Link>
        </p>
      </div>
    </div>
  );
}
