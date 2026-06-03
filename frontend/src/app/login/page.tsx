"use client";

// ============================================================
// CINAF v2 — Page Connexion
// Cette page gère l'authentification des utilisateurs existants.
// Elle utilise le contexte useAuth pour effectuer la connexion.
// ============================================================

import { useState, type FormEvent, type ChangeEvent } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import type { ApiError } from "@/lib/api";

// Structure des données du formulaire de connexion
interface LoginForm {
  email: string;
  password: string;
}

export default function LoginPage() {
  // Récupération de la méthode login du contexte d'authentification
  const { login } = useAuth();
  const router = useRouter();

  // États pour la gestion du formulaire, de l'affichage du mot de passe et des erreurs
  const [form, setForm] = useState<LoginForm>({ email: "", password: "" });
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  /**
   * Met à jour l'état du formulaire lors de la saisie utilisateur.
   * Efface également l'erreur en cours.
   */
  const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setForm((prev) => ({ ...prev, [name]: value }));
    if (error) setError(null);
  };

  /**
   * Gère la soumission du formulaire de connexion.
   * Valide les champs, appelle l'API via le contexte et redirige en cas de succès.
   */
  const handleSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setError(null);

    // Validation basique côté client
    if (!form.email || !form.password) {
      setError("Veuillez remplir tous les champs.");
      return;
    }

    setIsLoading(true);
    try {
      // Tentative de connexion via le service d'authentification
      await login({ email: form.email, password: form.password });
      // Redirection vers l'accueil après une connexion réussie
      router.push("/");
    } catch (err: unknown) {
      const apiErr = err as ApiError;
      // Gestion des erreurs d'authentification (401/400) ou erreurs génériques
      if (apiErr.statusCode === 401 || apiErr.statusCode === 400) {
        setError("Email ou mot de passe incorrect.");
      } else {
        setError(apiErr.message || "Une erreur est survenue. Veuillez réessayer.");
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="auth-page">
      <div style={{ width: "100%", maxWidth: 440 }}>
        {/* Logo et slogan */}
        <div className="text-center mb-4">
          <Link href="/" className="cinaf-logo-text" style={{ textDecoration: "none" }}>
            CINAF
          </Link>
          <p style={{ color: "var(--cinaf-text-muted)", fontSize: "0.875rem", marginTop: "0.5rem" }}>
            Votre portail du cinéma africain
          </p>
        </div>

        {/* Carte contenant le formulaire de connexion */}
        <div className="cinaf-card p-4">
          <h1
            className="mb-1"
            style={{ fontSize: "1.4rem", fontWeight: 700, color: "var(--cinaf-text)" }}
          >
            Connexion
          </h1>
          <p style={{ color: "var(--cinaf-text-muted)", fontSize: "0.875rem", marginBottom: "1.5rem" }}>
            Heureux de vous revoir !
          </p>

          {/* Affichage des messages d'erreur API ou validation */}
          {error && (
            <div className="alert-cinaf-error p-3 mb-3 d-flex align-items-center gap-2">
              <i className="bi bi-exclamation-circle-fill" />
              <span style={{ fontSize: "0.875rem" }}>{error}</span>
            </div>
          )}

          <form onSubmit={handleSubmit} noValidate>
            {/* Champ Email avec icône Bootstrap */}
            <div className="mb-3">
              <label htmlFor="email" className="form-label">
                Adresse email
              </label>
              <div className="input-group">
                <span
                  className="input-group-text"
                  style={{
                    backgroundColor: "var(--cinaf-surface-2)",
                    borderColor: "var(--cinaf-border)",
                    color: "var(--cinaf-text-muted)",
                  }}
                >
                  <i className="bi bi-envelope" />
                </span>
                <input
                  id="email"
                  type="email"
                  name="email"
                  className="form-control"
                  placeholder="vous@exemple.com"
                  value={form.email}
                  onChange={handleChange}
                  autoComplete="email"
                  required
                  disabled={isLoading}
                />
              </div>
            </div>

            {/* Champ Mot de passe avec bouton pour masquer/afficher */}
            <div className="mb-2">
              <label htmlFor="password" className="form-label">
                Mot de passe
              </label>
              <div className="input-group">
                <span
                  className="input-group-text"
                  style={{
                    backgroundColor: "var(--cinaf-surface-2)",
                    borderColor: "var(--cinaf-border)",
                    color: "var(--cinaf-text-muted)",
                  }}
                >
                  <i className="bi bi-lock" />
                </span>
                <input
                  id="password"
                  type={showPassword ? "text" : "password"}
                  name="password"
                  className="form-control"
                  placeholder="Votre mot de passe"
                  value={form.password}
                  onChange={handleChange}
                  autoComplete="current-password"
                  required
                  disabled={isLoading}
                />
                <button
                  type="button"
                  className="btn password-toggle-btn"
                  onClick={() => setShowPassword((v) => !v)}
                  tabIndex={-1}
                  aria-label={showPassword ? "Masquer le mot de passe" : "Afficher le mot de passe"}
                >
                  <i className={`bi bi-eye${showPassword ? "-slash" : ""}`} />
                </button>
              </div>
            </div>

            {/* Lien de secours pour mot de passe perdu */}
            <div className="text-end mb-4">
              <Link
                href="/forgot-password"
                style={{ fontSize: "0.8rem", color: "var(--cinaf-text-muted)" }}
              >
                Mot de passe oublié ?
              </Link>
            </div>

            {/* Bouton de validation avec état de chargement */}
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
                  Connexion en cours…
                </>
              ) : (
                <>
                  <i className="bi bi-play-circle me-2" />
                  Se connecter
                </>
              )}
            </button>
          </form>
        </div>

        {/* Navigation vers la page d'inscription */}
        <p className="text-center mt-3" style={{ color: "var(--cinaf-text-muted)", fontSize: "0.875rem" }}>
          Pas encore de compte ?{" "}
          <Link href="/register" style={{ color: "var(--cinaf-gold)", fontWeight: 600 }}>
            S&apos;inscrire gratuitement
          </Link>
        </p>
      </div>
    </div>
  );
}
