"use client";

// ============================================================
// CINAF v2 — Création d'une série (studio)
// ============================================================

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { studioSeries } from "@/lib/api";

/**
 * Page de création d'une nouvelle série télévisée.
 * 
 * Étape 1 :
 * - Renseigne le titre, le synopsis et l'année de sortie.
 * - Enregistre le brouillon via `studioSeries.create`.
 * - Redirige vers `/studio/series/[id]/edit` pour gérer les saisons, épisodes et l'affiche.
 * 
 * @returns Le formulaire de création initiale de série.
 */
export default function NewSeriePage() {
  const router = useRouter();
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [title, setTitle] = useState("");
  const [synopsis, setSynopsis] = useState("");
  const [year, setYear] = useState<number>(new Date().getFullYear());

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const created = await studioSeries.create({
        title: title.trim(),
        synopsis: synopsis.trim(),
        year,
      });
      router.push(`/studio/series/${created.id}/edit`);
    } catch (err: unknown) {
      setError((err as { message?: string })?.message ?? "Création impossible.");
      setSubmitting(false);
    }
  }

  return (
    <div>
      <Link
        href="/studio/series"
        className="d-inline-block mb-2"
        style={{ color: "var(--cinaf-text-muted)", textDecoration: "none" }}
      >
        <i className="bi bi-arrow-left me-1" />
        Retour aux séries
      </Link>
      <h1 className="mb-3" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
        <i className="bi bi-plus-circle me-2" style={{ color: "var(--cinaf-gold)" }} />
        Nouvelle série
      </h1>
      <div
        className="alert d-flex align-items-start gap-2 mb-3"
        style={{
          background: "rgba(200,168,75,0.08)",
          border: "1px solid rgba(200,168,75,0.35)",
          color: "var(--cinaf-text)",
        }}
      >
        <i className="bi bi-info-circle-fill mt-1" style={{ color: "var(--cinaf-gold)" }} />
        <div>
          <strong>Étape 1 sur 2 — création du brouillon.</strong>
          <div className="small mt-1" style={{ color: "var(--cinaf-text-muted)" }}>
            Renseignez d&apos;abord les métadonnées. À l&apos;étape suivante (édition), vous pourrez
            uploader l&apos;affiche (max 10 Mo), créer les saisons et uploader les épisodes
            (vidéo max 3 Go par épisode), puis publier.
          </div>
        </div>
      </div>

      <form
        onSubmit={handleSubmit}
        className="p-3"
        style={{
          background: "var(--cinaf-surface)",
          border: "1px solid var(--cinaf-border)",
          borderRadius: 10,
          maxWidth: 720,
        }}
      >
        <div className="mb-3">
          <label htmlFor="title" className="form-label" style={{ color: "var(--cinaf-text)" }}>
            Titre <span style={{ color: "#ff8a8a" }}>*</span>
          </label>
          <input
            id="title"
            type="text"
            className="form-control"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            required
            maxLength={255}
            style={{
              background: "var(--cinaf-surface-2)",
              color: "var(--cinaf-text)",
              border: "1px solid var(--cinaf-border)",
            }}
          />
        </div>

        <div className="mb-3">
          <label htmlFor="synopsis" className="form-label" style={{ color: "var(--cinaf-text)" }}>
            Synopsis <span style={{ color: "#ff8a8a" }}>*</span>
          </label>
          <textarea
            id="synopsis"
            className="form-control"
            rows={4}
            value={synopsis}
            onChange={(e) => setSynopsis(e.target.value)}
            required
            style={{
              background: "var(--cinaf-surface-2)",
              color: "var(--cinaf-text)",
              border: "1px solid var(--cinaf-border)",
            }}
          />
        </div>

        <div className="mb-3">
          <label htmlFor="year" className="form-label" style={{ color: "var(--cinaf-text)" }}>
            Année <span style={{ color: "#ff8a8a" }}>*</span>
          </label>
          <input
            id="year"
            type="number"
            className="form-control"
            min={1900}
            max={new Date().getFullYear() + 5}
            value={year}
            onChange={(e) => setYear(Number(e.target.value))}
            required
            style={{
              background: "var(--cinaf-surface-2)",
              color: "var(--cinaf-text)",
              border: "1px solid var(--cinaf-border)",
              maxWidth: 200,
            }}
          />
        </div>

        {error && (
          <div
            className="alert"
            style={{ background: "#2a1414", color: "#ff8a8a", border: "1px solid #5a2020" }}
          >
            <i className="bi bi-exclamation-triangle me-2" />
            {error}
          </div>
        )}

        <div className="d-flex gap-2">
          <button
            type="submit"
            className="btn"
            disabled={submitting}
            style={{ background: "var(--cinaf-gold)", color: "#000", fontWeight: 600 }}
          >
            {submitting ? (
              <>
                <span className="spinner-border spinner-border-sm me-2" />
                Création…
              </>
            ) : (
              <>
                <i className="bi bi-check2 me-1" />
                Créer le brouillon
              </>
            )}
          </button>
          <Link href="/studio/series" className="btn btn-secondary">
            Annuler
          </Link>
        </div>
      </form>
    </div>
  );
}
