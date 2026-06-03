"use client";

// ============================================================
// CINAF v2 — Édition d'un film (studio)
// Métadonnées + upload poster + upload vidéo principale
// + actions cycle de vie (publish, withdraw, delete).
// ============================================================

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { studioFilms, type StudioFilm, type UploadResult } from "@/lib/api";
import StatusBadge from "@/components/studio/StatusBadge";
import UploadDropzone from "@/components/studio/UploadDropzone";
import WithdrawalDialog from "@/components/studio/WithdrawalDialog";

interface PageProps {
  params: { id: string };
}

export default function EditFilmPage({ params }: PageProps) {
  const { id } = params;
  const router = useRouter();

  const [film, setFilm] = useState<StudioFilm | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [title, setTitle] = useState("");
  const [synopsis, setSynopsis] = useState("");
  const [year, setYear] = useState<number>(new Date().getFullYear());
  const [duration, setDuration] = useState<number>(90);
  const [poster, setPoster] = useState<string | null>(null);
  const [bunnyVideoId, setBunnyVideoId] = useState<string | null>(null);

  const [saving, setSaving] = useState(false);
  const [actionPending, setActionPending] = useState(false);
  const [withdrawOpen, setWithdrawOpen] = useState(false);
  const [savedAt, setSavedAt] = useState<Date | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      try {
        const f = await studioFilms.get(id);
        if (cancelled) return;
        setFilm(f);
        setTitle(f.title);
        setSynopsis(f.synopsis ?? "");
        setYear(f.year);
        setDuration(f.duration);
        setPoster(f.poster ?? null);
        setBunnyVideoId(f.bunnyVideoId ?? null);
      } catch (err: unknown) {
        if (cancelled) return;
        setError((err as { message?: string })?.message ?? "Chargement impossible.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [id]);

  async function handleSave() {
    setSaving(true);
    setError(null);
    try {
      const updated = await studioFilms.update(id, {
        title: title.trim(),
        synopsis: synopsis.trim(),
        year,
        duration,
        poster,
        bunnyVideoId,
      });
      setFilm(updated);
      setSavedAt(new Date());
    } catch (err: unknown) {
      setError((err as { message?: string })?.message ?? "Sauvegarde impossible.");
    } finally {
      setSaving(false);
    }
  }

  async function handlePublish() {
    if (!film) return;
    if (!confirm("Publier ce film ? Il deviendra visible par les abonnés.")) return;
    setActionPending(true);
    try {
      const updated = await studioFilms.publish(film.id);
      setFilm(updated);
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Publication impossible.");
    } finally {
      setActionPending(false);
    }
  }

  async function handleDelete() {
    if (!film) return;
    if (!confirm("Supprimer définitivement ce brouillon ?")) return;
    setActionPending(true);
    try {
      await studioFilms.remove(film.id);
      router.push("/studio/films");
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Suppression impossible.");
      setActionPending(false);
    }
  }

  async function submitWithdrawal(reason: string) {
    if (!film) return;
    await studioFilms.withdraw(film.id, reason);
    // Recharger le film car son status devrait toujours être PUBLISHED
    // tant que la demande n'a pas été approuvée par un admin.
    const f = await studioFilms.get(film.id);
    setFilm(f);
  }

  // Le auto-PATCH est désormais géré par le UploadProvider (résilient à la
  // navigation). On rafraîchit juste l'état local quand l'upload finit
  // pour que l'aperçu (poster) et le badge bunnyVideoId soient à jour.
  function onPosterUploaded(r: UploadResult) {
    setPoster(r.url);
    if (film) {
      // Refetch pour rester en cohérence avec ce que le manager a PATCHé.
      studioFilms.get(film.id).then(setFilm).catch(() => {});
    }
  }

  function onVideoUploaded(r: UploadResult) {
    setBunnyVideoId(r.path);
    if (film) {
      studioFilms.get(film.id).then(setFilm).catch(() => {});
    }
  }

  if (loading) {
    return (
      <div className="text-center py-5">
        <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} />
      </div>
    );
  }

  if (error && !film) {
    return (
      <div
        className="alert"
        style={{ background: "#2a1414", color: "#ff8a8a", border: "1px solid #5a2020" }}
      >
        <i className="bi bi-exclamation-triangle me-2" />
        {error}
        <div className="mt-2">
          <Link href="/studio/films" className="btn btn-sm btn-secondary">
            Retour
          </Link>
        </div>
      </div>
    );
  }

  if (!film) return null;

  const isDraft = film.status === "DRAFT";
  const isPublished = film.status === "PUBLISHED";

  return (
    <div>
      <Link
        href="/studio/films"
        className="d-inline-block mb-2"
        style={{ color: "var(--cinaf-text-muted)", textDecoration: "none" }}
      >
        <i className="bi bi-arrow-left me-1" />
        Retour aux films
      </Link>

      <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <h1 className="mb-0" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
          <i className="bi bi-pencil-square me-2" style={{ color: "var(--cinaf-gold)" }} />
          {title || "Film sans titre"}
        </h1>
        <div className="d-flex align-items-center gap-2">
          <StatusBadge status={film.status} />
          {savedAt && (
            <span className="small" style={{ color: "var(--cinaf-text-muted)" }}>
              <i className="bi bi-check2-circle me-1" style={{ color: "#8fd68f" }} />
              Sauvegardé à {savedAt.toLocaleTimeString("fr-FR")}
            </span>
          )}
        </div>
      </div>

      <div className="row g-3">
        {/* Colonne formulaire */}
        <div className="col-12 col-lg-8">
          <div
            className="p-3"
            style={{
              background: "var(--cinaf-surface)",
              border: "1px solid var(--cinaf-border)",
              borderRadius: 10,
            }}
          >
            <h5 className="mb-3" style={{ color: "var(--cinaf-text)" }}>
              Métadonnées
            </h5>

            <div className="mb-3">
              <label htmlFor="title" className="form-label" style={{ color: "var(--cinaf-text)" }}>
                Titre
              </label>
              <input
                id="title"
                type="text"
                className="form-control"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                style={{
                  background: "var(--cinaf-surface-2)",
                  color: "var(--cinaf-text)",
                  border: "1px solid var(--cinaf-border)",
                }}
              />
              <div className="small mt-1" style={{ color: "var(--cinaf-text-muted)" }}>
                Slug : <code>{film.slug}</code>
              </div>
            </div>

            <div className="mb-3">
              <label htmlFor="synopsis" className="form-label" style={{ color: "var(--cinaf-text)" }}>
                Synopsis
              </label>
              <textarea
                id="synopsis"
                className="form-control"
                rows={5}
                value={synopsis}
                onChange={(e) => setSynopsis(e.target.value)}
                style={{
                  background: "var(--cinaf-surface-2)",
                  color: "var(--cinaf-text)",
                  border: "1px solid var(--cinaf-border)",
                }}
              />
            </div>

            <div className="row g-3 mb-3">
              <div className="col-12 col-md-6">
                <label htmlFor="year" className="form-label" style={{ color: "var(--cinaf-text)" }}>
                  Année
                </label>
                <input
                  id="year"
                  type="number"
                  className="form-control"
                  min={1900}
                  max={new Date().getFullYear() + 5}
                  value={year}
                  onChange={(e) => setYear(Number(e.target.value))}
                  style={{
                    background: "var(--cinaf-surface-2)",
                    color: "var(--cinaf-text)",
                    border: "1px solid var(--cinaf-border)",
                  }}
                />
              </div>
              <div className="col-12 col-md-6">
                <label htmlFor="duration" className="form-label" style={{ color: "var(--cinaf-text)" }}>
                  Durée (minutes)
                </label>
                <input
                  id="duration"
                  type="number"
                  className="form-control"
                  min={1}
                  max={600}
                  value={duration}
                  onChange={(e) => setDuration(Number(e.target.value))}
                  style={{
                    background: "var(--cinaf-surface-2)",
                    color: "var(--cinaf-text)",
                    border: "1px solid var(--cinaf-border)",
                  }}
                />
              </div>
            </div>

            <hr style={{ borderColor: "var(--cinaf-border)" }} />

            <h5 className="mb-3" style={{ color: "var(--cinaf-text)" }}>
              Affiche
            </h5>
            <UploadDropzone
              target={{ type: "film", id, purpose: "poster" }}
              onUploaded={onPosterUploaded}
              disabled={!film}
              extra={
                poster ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img
                    src={poster}
                    alt="Affiche actuelle"
                    style={{
                      width: 100,
                      height: 140,
                      objectFit: "cover",
                      borderRadius: 6,
                      border: "1px solid var(--cinaf-border)",
                    }}
                  />
                ) : undefined
              }
            />

            <hr style={{ borderColor: "var(--cinaf-border)", marginTop: "1.5rem" }} />

            <h5 className="mb-3" style={{ color: "var(--cinaf-text)" }}>
              Vidéo principale
            </h5>
            <UploadDropzone
              target={{ type: "film", id, purpose: "video" }}
              onUploaded={onVideoUploaded}
              disabled={!film}
            />
            {bunnyVideoId && (
              <div className="small mt-2" style={{ color: "var(--cinaf-text-muted)" }}>
                <i className="bi bi-camera-reels me-1" style={{ color: "#8fd68f" }} />
                Vidéo associée : <code>{bunnyVideoId}</code>
              </div>
            )}

            {error && (
              <div
                className="alert mt-3 mb-0"
                style={{ background: "#2a1414", color: "#ff8a8a", border: "1px solid #5a2020" }}
              >
                <i className="bi bi-exclamation-triangle me-2" />
                {error}
              </div>
            )}
          </div>
        </div>

        {/* Colonne actions */}
        <div className="col-12 col-lg-4">
          <div
            className="p-3"
            style={{
              background: "var(--cinaf-surface)",
              border: "1px solid var(--cinaf-border)",
              borderRadius: 10,
              position: "sticky",
              top: 80,
            }}
          >
            <h5 className="mb-3" style={{ color: "var(--cinaf-text)" }}>
              Actions
            </h5>
            <button
              type="button"
              onClick={handleSave}
              disabled={saving}
              className="btn w-100 mb-2"
              style={{
                background: "var(--cinaf-gold)",
                color: "#000",
                fontWeight: 600,
              }}
            >
              {saving ? (
                <>
                  <span className="spinner-border spinner-border-sm me-2" />
                  Sauvegarde…
                </>
              ) : (
                <>
                  <i className="bi bi-save me-1" />
                  Sauvegarder
                </>
              )}
            </button>

            {isDraft && (
              <>
                <button
                  type="button"
                  onClick={handlePublish}
                  disabled={actionPending}
                  className="btn w-100 mb-2"
                  style={{ background: "#1f6337", color: "#fff", border: "none" }}
                >
                  <i className="bi bi-broadcast me-1" />
                  Publier
                </button>
                <button
                  type="button"
                  onClick={handleDelete}
                  disabled={actionPending}
                  className="btn w-100"
                  style={{ background: "#3a1414", color: "#ff8a8a", border: "none" }}
                >
                  <i className="bi bi-trash me-1" />
                  Supprimer le brouillon
                </button>
              </>
            )}

            {isPublished && (
              <button
                type="button"
                onClick={() => setWithdrawOpen(true)}
                disabled={actionPending}
                className="btn w-100"
                style={{ background: "#5a3520", color: "#f0c080", border: "none" }}
              >
                <i className="bi bi-shield-slash me-1" />
                Demander un retrait
              </button>
            )}

            <hr style={{ borderColor: "var(--cinaf-border)" }} />
            <div className="small" style={{ color: "var(--cinaf-text-muted)" }}>
              <div className="mb-1">
                <strong>Créé le :</strong>{" "}
                {new Date(film.createdAt).toLocaleDateString("fr-FR")}
              </div>
              {film.publishedAt && (
                <div className="mb-1">
                  <strong>Publié le :</strong>{" "}
                  {new Date(film.publishedAt).toLocaleDateString("fr-FR")}
                </div>
              )}
              {film.withdrawnAt && (
                <div>
                  <strong>Retiré le :</strong>{" "}
                  {new Date(film.withdrawnAt).toLocaleDateString("fr-FR")}
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      <WithdrawalDialog
        open={withdrawOpen}
        title={`Retrait de « ${film.title} »`}
        onClose={() => setWithdrawOpen(false)}
        onSubmit={submitWithdrawal}
      />
    </div>
  );
}
