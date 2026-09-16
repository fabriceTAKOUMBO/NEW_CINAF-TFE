"use client";

// ============================================================
// CINAF v2 — Parcours self-service "Je suis producteur"
// Page accessible depuis le footer. Logique de redirection :
//   - Non logué        → /login?next=/devenir-producteur
//   - Admin            → message d'erreur (séparation Phase H)
//   - Déjà un studio   → /studio
//   - Sinon            → formulaire de création (name + description)
// Après création, on rafraîchit /auth/me pour récupérer ROLE_CREATEUR
// puis on redirige vers /studio.
// ============================================================

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useAuth } from "@/lib/auth";
import { isAdmin } from "@/lib/auth-helpers";
import { studio as studioApi, studioOnboarding, type ApiError } from "@/lib/api";

/**
 * Parcours d'intégration en libre-service ("Onboarding") pour devenir producteur sur CINAF.
 * 
 * Fonctionnalités :
 * - Vérifie si l'utilisateur est authentifié (sinon redirection vers `/login`).
 * - Interdit la création de studio aux comptes administrateurs.
 * - Détecte si l'utilisateur possède déjà un studio (`studioApi.getMe`) et le redirige vers `/studio`.
 * - Soumet le formulaire de création de studio (`studioOnboarding.create`), puis rafraîchit la session
 *   JWT pour acquérir le rôle `ROLE_CREATEUR`.
 * 
 * @returns Le formulaire de candidature / création de studio.
 */
export default function DevenirProducteurPage() {
  const router = useRouter();
  const { user, isAuthenticated, isLoading, refresh } = useAuth();

  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Détecte si l'user a déjà un studio (appel /api/studio/me). On ne
  // s'appuie pas sur user.roles parce que le user peut avoir ROLE_CREATEUR
  // sans nécessairement avoir un studio actif (cas marginal).
  const [hasStudio, setHasStudio] = useState<boolean | null>(null);

  // Redirige vers /login si non authentifié.
  useEffect(() => {
    if (isLoading) return;
    if (!isAuthenticated) {
      router.replace("/login?next=/devenir-producteur");
    }
  }, [isLoading, isAuthenticated, router]);

  // Si l'user a un studio, on redirige direct vers /studio. Ce check
  // se fait via /api/studio/me et ne nécessite pas l'inspection du JWT.
  useEffect(() => {
    if (isLoading || !isAuthenticated || isAdmin(user)) return;
    let cancelled = false;
    (async () => {
      try {
        await studioApi.getMe();
        if (!cancelled) {
          setHasStudio(true);
          router.replace("/studio");
        }
      } catch {
        if (!cancelled) setHasStudio(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [isLoading, isAuthenticated, user, router]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    const trimmedName = name.trim();
    const trimmedDesc = description.trim();
    if (trimmedName === "" || trimmedDesc === "") {
      setError("Le nom et la description sont obligatoires.");
      return;
    }
    setSubmitting(true);
    try {
      await studioOnboarding.create({
        name: trimmedName,
        description: trimmedDesc,
      });
      // Rafraîchit le profil pour récupérer le nouveau ROLE_CREATEUR
      // (sinon le layout /studio refuse l'accès).
      await refresh();
      router.replace("/studio");
    } catch (err) {
      const apiErr = err as ApiError;
      setError(apiErr?.message ?? "Erreur lors de la création du studio.");
    } finally {
      setSubmitting(false);
    }
  }

  // ── États d'attente / blocage ──────────────────────────────

  if (isLoading || !isAuthenticated || hasStudio === true) {
    return (
      <div
        className="d-flex align-items-center justify-content-center"
        style={{ minHeight: "60vh" }}
      >
        <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} />
      </div>
    );
  }

  if (isAdmin(user)) {
    return (
      <div className="container py-5" style={{ maxWidth: 640 }}>
        <div
          className="alert"
          style={{
            background: "#2a1414",
            color: "#ff8a8a",
            border: "1px solid #5a2020",
          }}
        >
          <i className="bi bi-exclamation-triangle me-2" />
          Un administrateur ne peut pas créer de studio.
        </div>
        <Link href="/" className="btn btn-outline-secondary mt-3">
          Retour à l&apos;accueil
        </Link>
      </div>
    );
  }

  // ── Formulaire ─────────────────────────────────────────────

  return (
    <div className="container py-5" style={{ maxWidth: 640 }}>
      <h1 style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
        <i
          className="bi bi-camera-reels me-2"
          style={{ color: "var(--cinaf-gold)" }}
        />
        Je suis producteur
      </h1>
      <p style={{ color: "var(--cinaf-text-muted)" }}>
        Créez votre studio et commencez à publier vos films et séries sur CINAF.
        Votre premier contenu sera modéré par un administrateur ; les suivants
        seront mis en ligne directement.
      </p>

      <form
        onSubmit={handleSubmit}
        className="p-4 mt-4"
        style={{
          background: "var(--cinaf-surface)",
          border: "1px solid var(--cinaf-border)",
          borderRadius: 10,
        }}
      >
        <div className="mb-3">
          <label
            htmlFor="studio-name"
            className="form-label"
            style={{ color: "var(--cinaf-text)", fontWeight: 600 }}
          >
            Nom du studio
          </label>
          <input
            id="studio-name"
            type="text"
            className="form-control"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Ex : Sahel Productions"
            required
            maxLength={255}
            disabled={submitting}
          />
        </div>

        <div className="mb-3">
          <label
            htmlFor="studio-description"
            className="form-label"
            style={{ color: "var(--cinaf-text)", fontWeight: 600 }}
          >
            Description
          </label>
          <textarea
            id="studio-description"
            className="form-control"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="Quelques mots sur votre studio, vos productions, votre démarche…"
            rows={4}
            required
            disabled={submitting}
          />
        </div>

        {error && (
          <div
            className="alert"
            style={{
              background: "#2a1414",
              color: "#ff8a8a",
              border: "1px solid #5a2020",
            }}
          >
            <i className="bi bi-exclamation-triangle me-2" />
            {error}
          </div>
        )}

        <div className="d-flex gap-2 mt-3">
          <button
            type="submit"
            className="btn"
            style={{
              background: "var(--cinaf-gold)",
              color: "#000",
              fontWeight: 600,
            }}
            disabled={submitting}
          >
            {submitting ? (
              <>
                <span
                  className="spinner-border spinner-border-sm me-2"
                  role="status"
                />
                Création en cours…
              </>
            ) : (
              <>
                <i className="bi bi-check-circle me-2" />
                Créer mon studio
              </>
            )}
          </button>
          <Link
            href="/"
            className="btn btn-outline-secondary"
            aria-disabled={submitting}
          >
            Annuler
          </Link>
        </div>
      </form>
    </div>
  );
}
