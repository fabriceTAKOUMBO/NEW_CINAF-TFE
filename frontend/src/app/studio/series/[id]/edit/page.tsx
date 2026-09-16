"use client";

// ============================================================
// CINAF v2 — Édition d'une série (studio)
// Métadonnées + poster + gestion des saisons et épisodes.
// La gestion des saisons/épisodes utilise des modales simples
// pour rester proche du style Bootstrap 5 sans dépendance.
// ============================================================

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  studioSeries,
  type StudioSerie,
  type StudioSeason,
  type StudioEpisode,
  type UploadResult,
} from "@/lib/api";
import StatusBadge from "@/components/studio/StatusBadge";
import UploadDropzone from "@/components/studio/UploadDropzone";
import WithdrawalDialog from "@/components/studio/WithdrawalDialog";
import { useUploads } from "@/lib/upload-context";

interface PageProps {
  params: { id: string };
}

/**
 * Page d'édition complète d'une série pour le studio.
 * 
 * Fonctionnalités :
 * - Édition des métadonnées générales (titre, synopsis, année).
 * - Upload de l'affiche de la série via `UploadDropzone`.
 * - Gestion arborescente des saisons (`SeasonModal`) et des épisodes (`EpisodeModal`).
 * - Envoi asynchrone des vidéos d'épisodes en arrière-plan avec suivi dans le tray global.
 * - Actions de cycle de vie : publication, suppression et demande de retrait.
 * 
 * @param props.params.id - Identifiant UUID de la série.
 * @returns L'interface complète de configuration de la série.
 */
export default function EditSeriePage({ params }: PageProps) {
  const { id } = params;
  const router = useRouter();

  const [serie, setSerie] = useState<StudioSerie | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [title, setTitle] = useState("");
  const [synopsis, setSynopsis] = useState("");
  const [year, setYear] = useState<number>(new Date().getFullYear());
  const [poster, setPoster] = useState<string | null>(null);

  const [saving, setSaving] = useState(false);
  const [actionPending, setActionPending] = useState(false);
  const [withdrawOpen, setWithdrawOpen] = useState(false);
  const [savedAt, setSavedAt] = useState<Date | null>(null);

  // Modales saison / épisode
  const [seasonModalOpen, setSeasonModalOpen] = useState(false);
  const [episodeModal, setEpisodeModal] = useState<{ seasonId: string } | null>(null);

  async function loadSerie() {
    setLoading(true);
    try {
      const s = await studioSeries.get(id);
      setSerie(s);
      setTitle(s.title);
      setSynopsis(s.synopsis ?? "");
      setYear(s.year);
      setPoster(s.poster ?? null);
    } catch (err: unknown) {
      setError((err as { message?: string })?.message ?? "Chargement impossible.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadSerie();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  async function handleSave() {
    setSaving(true);
    setError(null);
    try {
      const updated = await studioSeries.update(id, {
        title: title.trim(),
        synopsis: synopsis.trim(),
        year,
        poster,
      });
      setSerie(updated);
      setSavedAt(new Date());
    } catch (err: unknown) {
      setError((err as { message?: string })?.message ?? "Sauvegarde impossible.");
    } finally {
      setSaving(false);
    }
  }

  async function handlePublish() {
    if (!serie) return;
    if (!confirm("Publier cette série ?")) return;
    setActionPending(true);
    try {
      const updated = await studioSeries.publish(serie.id);
      setSerie(updated);
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Publication impossible.");
    } finally {
      setActionPending(false);
    }
  }

  async function handleDelete() {
    if (!serie) return;
    if (!confirm("Supprimer ce brouillon ?")) return;
    setActionPending(true);
    try {
      await studioSeries.remove(serie.id);
      router.push("/studio/series");
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Suppression impossible.");
      setActionPending(false);
    }
  }

  async function submitWithdrawal(reason: string) {
    if (!serie) return;
    await studioSeries.withdraw(serie.id, reason);
    await loadSerie();
  }

  // L'auto-PATCH est désormais géré par le UploadProvider. On rafraîchit
  // juste l'état local pour voir l'aperçu mis à jour.
  function onPosterUploaded(r: UploadResult) {
    setPoster(r.url);
    if (serie) {
      studioSeries.get(serie.id).then(setSerie).catch(() => {});
    }
  }

  async function handleDeleteSeason(season: StudioSeason) {
    if (!serie) return;
    if (!confirm(`Supprimer la saison ${season.number} ? Tous les épisodes seront supprimés.`))
      return;
    try {
      await studioSeries.removeSeason(serie.id, season.id);
      await loadSerie();
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Suppression impossible.");
    }
  }

  async function handleDeleteEpisode(season: StudioSeason, episode: StudioEpisode) {
    if (!serie) return;
    if (!confirm(`Supprimer l'épisode "${episode.title}" ?`)) return;
    try {
      await studioSeries.removeEpisode(serie.id, season.id, episode.id);
      await loadSerie();
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Suppression impossible.");
    }
  }

  if (loading) {
    return (
      <div className="text-center py-5">
        <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} />
      </div>
    );
  }

  if (error && !serie) {
    return (
      <div
        className="alert"
        style={{ background: "#2a1414", color: "#ff8a8a", border: "1px solid #5a2020" }}
      >
        <i className="bi bi-exclamation-triangle me-2" />
        {error}
      </div>
    );
  }

  if (!serie) return null;

  const isDraft = serie.status === "DRAFT";
  const isPublished = serie.status === "PUBLISHED";
  const seasons = (serie.seasons ?? []).slice().sort((a, b) => a.number - b.number);

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

      <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <h1 className="mb-0" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
          <i className="bi bi-collection-play me-2" style={{ color: "var(--cinaf-gold)" }} />
          {title || "Série sans titre"}
        </h1>
        <div className="d-flex align-items-center gap-2">
          <StatusBadge status={serie.status} />
          {savedAt && (
            <span className="small" style={{ color: "var(--cinaf-text-muted)" }}>
              <i className="bi bi-check2-circle me-1" style={{ color: "#8fd68f" }} />
              Sauvegardé à {savedAt.toLocaleTimeString("fr-FR")}
            </span>
          )}
        </div>
      </div>

      <div className="row g-3">
        <div className="col-12 col-lg-8">
          <div
            className="p-3 mb-3"
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
                Slug : <code>{serie.slug}</code>
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
            </div>
            <hr style={{ borderColor: "var(--cinaf-border)" }} />
            <h5 className="mb-3" style={{ color: "var(--cinaf-text)" }}>
              Affiche
            </h5>
            <UploadDropzone
              target={{ type: "serie", id, purpose: "poster" }}
              onUploaded={onPosterUploaded}
              disabled={!serie}
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
          </div>

          {/* Saisons */}
          <div
            className="p-3"
            style={{
              background: "var(--cinaf-surface)",
              border: "1px solid var(--cinaf-border)",
              borderRadius: 10,
            }}
          >
            <div className="d-flex align-items-center justify-content-between mb-3">
              <h5 className="mb-0" style={{ color: "var(--cinaf-text)" }}>
                Saisons et épisodes
              </h5>
              <button
                type="button"
                className="btn btn-sm"
                onClick={() => setSeasonModalOpen(true)}
                style={{ background: "var(--cinaf-gold)", color: "#000", fontWeight: 600 }}
              >
                <i className="bi bi-plus-lg me-1" />
                Ajouter une saison
              </button>
            </div>
            {seasons.length === 0 ? (
              <div
                className="text-center py-4"
                style={{ color: "var(--cinaf-text-muted)" }}
              >
                Aucune saison. Ajoutez-en une pour commencer.
              </div>
            ) : (
              <div className="accordion" id="seasonsAccordion">
                {seasons.map((season, idx) => (
                  <div
                    key={season.id}
                    className="accordion-item"
                    style={{
                      background: "var(--cinaf-surface-2)",
                      border: "1px solid var(--cinaf-border)",
                    }}
                  >
                    <h2 className="accordion-header">
                      <button
                        className={`accordion-button ${idx === 0 ? "" : "collapsed"}`}
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target={`#season-${season.id}`}
                        style={{
                          background: "var(--cinaf-surface-2)",
                          color: "var(--cinaf-text)",
                        }}
                      >
                        <span className="me-3" style={{ fontWeight: 700 }}>
                          Saison {season.number}
                        </span>
                        {season.title && (
                          <span style={{ color: "var(--cinaf-text-muted)" }}>{season.title}</span>
                        )}
                        <span className="ms-auto small me-3" style={{ color: "var(--cinaf-text-muted)" }}>
                          {(season.episodes ?? []).length} épisode
                          {(season.episodes ?? []).length > 1 ? "s" : ""}
                        </span>
                      </button>
                    </h2>
                    <div
                      id={`season-${season.id}`}
                      className={`accordion-collapse collapse ${idx === 0 ? "show" : ""}`}
                    >
                      <div className="accordion-body">
                        <div className="d-flex justify-content-between align-items-center mb-2">
                          <p className="mb-0 small" style={{ color: "var(--cinaf-text-muted)" }}>
                            {season.synopsis ?? "Aucun synopsis pour cette saison."}
                          </p>
                          <div className="d-flex gap-2">
                            <button
                              type="button"
                              className="btn btn-sm btn-cinaf-outline"
                              onClick={() => setEpisodeModal({ seasonId: season.id })}
                            >
                              <i className="bi bi-plus-lg me-1" />
                              Épisode
                            </button>
                            <button
                              type="button"
                              className="btn btn-sm"
                              onClick={() => handleDeleteSeason(season)}
                              style={{ background: "#3a1414", color: "#ff8a8a", border: "none" }}
                            >
                              <i className="bi bi-trash" />
                            </button>
                          </div>
                        </div>
                        {(season.episodes ?? []).length === 0 ? (
                          <div
                            className="text-center py-3 small"
                            style={{ color: "var(--cinaf-text-muted)" }}
                          >
                            Aucun épisode. Ajoutez-en un.
                          </div>
                        ) : (
                          <ul className="list-unstyled mb-0">
                            {(season.episodes ?? [])
                              .slice()
                              .sort((a, b) => a.number - b.number)
                              .map((ep) => (
                                <li
                                  key={ep.id}
                                  className="d-flex align-items-center gap-2 py-2"
                                  style={{ borderTop: "1px solid var(--cinaf-border)" }}
                                >
                                  <span
                                    style={{
                                      width: 28,
                                      height: 28,
                                      borderRadius: 6,
                                      background: "var(--cinaf-gold)",
                                      color: "#000",
                                      fontWeight: 700,
                                      fontSize: "0.75rem",
                                      display: "flex",
                                      alignItems: "center",
                                      justifyContent: "center",
                                      flex: "0 0 auto",
                                    }}
                                  >
                                    {ep.number}
                                  </span>
                                  <div className="flex-grow-1">
                                    <div style={{ color: "var(--cinaf-text)", fontWeight: 600 }}>
                                      {ep.title}
                                    </div>
                                    <div className="small" style={{ color: "var(--cinaf-text-muted)" }}>
                                      {ep.duration ? `${ep.duration} min` : "—"}
                                      {ep.bunnyVideoId && (
                                        <span className="ms-2">
                                          <i
                                            className="bi bi-camera-reels"
                                            style={{ color: "#8fd68f" }}
                                          />
                                        </span>
                                      )}
                                    </div>
                                  </div>
                                  <button
                                    type="button"
                                    className="btn btn-sm"
                                    onClick={() => handleDeleteEpisode(season, ep)}
                                    style={{
                                      background: "#3a1414",
                                      color: "#ff8a8a",
                                      border: "none",
                                    }}
                                    title="Supprimer"
                                  >
                                    <i className="bi bi-trash" />
                                  </button>
                                </li>
                              ))}
                          </ul>
                        )}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

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
              style={{ background: "var(--cinaf-gold)", color: "#000", fontWeight: 600 }}
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
                <strong>Créée le :</strong>{" "}
                {new Date(serie.createdAt).toLocaleDateString("fr-FR")}
              </div>
              {serie.publishedAt && (
                <div className="mb-1">
                  <strong>Publiée le :</strong>{" "}
                  {new Date(serie.publishedAt).toLocaleDateString("fr-FR")}
                </div>
              )}
              {serie.withdrawnAt && (
                <div>
                  <strong>Retirée le :</strong>{" "}
                  {new Date(serie.withdrawnAt).toLocaleDateString("fr-FR")}
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      <SeasonModal
        open={seasonModalOpen}
        onClose={() => setSeasonModalOpen(false)}
        onCreated={async () => {
          setSeasonModalOpen(false);
          await loadSerie();
        }}
        serieId={serie.id}
        existingNumbers={seasons.map((s) => s.number)}
      />

      <EpisodeModal
        open={episodeModal !== null}
        onClose={() => setEpisodeModal(null)}
        onCreated={async () => {
          setEpisodeModal(null);
          await loadSerie();
        }}
        serieId={serie.id}
        seasonId={episodeModal?.seasonId ?? null}
        existingNumbers={
          episodeModal
            ? (seasons.find((s) => s.id === episodeModal.seasonId)?.episodes ?? []).map(
                (e) => e.number,
              )
            : []
        }
      />

      <WithdrawalDialog
        open={withdrawOpen}
        title={`Retrait de « ${serie.title} »`}
        onClose={() => setWithdrawOpen(false)}
        onSubmit={submitWithdrawal}
      />
    </div>
  );
}

// ─── Modale "Ajouter une saison" ─────────────────────────────

/**
 * Modale de création d'une nouvelle saison pour la série.
 * 
 * @param props.open - Booléen déterminant l'affichage de la modale.
 * @param props.onClose - Callback de fermeture.
 * @param props.onCreated - Callback de succès pour rafraîchir la liste des saisons.
 * @param props.serieId - Identifiant UUID de la série parente.
 * @param props.existingNumbers - Numéros de saisons déjà existants pour auto-incrémenter le numéro suggéré.
 */
function SeasonModal({
  open,
  onClose,
  onCreated,
  serieId,
  existingNumbers,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: () => void | Promise<void>;
  serieId: string;
  existingNumbers: number[];
}) {
  const nextNumber =
    existingNumbers.length === 0 ? 1 : Math.max(...existingNumbers) + 1;
  const [number, setNumber] = useState<number>(nextNumber);
  const [title, setTitle] = useState("");
  const [synopsis, setSynopsis] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setNumber(nextNumber);
      setTitle("");
      setSynopsis("");
      setError(null);
      setSubmitting(false);
    }
  }, [open, nextNumber]);

  if (!open) return null;

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await studioSeries.createSeason(serieId, {
        number,
        title: title.trim() || undefined,
        synopsis: synopsis.trim() || undefined,
      });
      await onCreated();
    } catch (err: unknown) {
      setError((err as { message?: string })?.message ?? "Création impossible.");
      setSubmitting(false);
    }
  }

  return (
    <>
      <div className="modal-backdrop fade show" style={{ zIndex: 1050 }} onClick={onClose} />
      <div className="modal fade show d-block" tabIndex={-1} role="dialog" style={{ zIndex: 1055 }}>
        <div className="modal-dialog modal-dialog-centered">
          <div
            className="modal-content"
            style={{
              background: "var(--cinaf-surface)",
              color: "var(--cinaf-text)",
              border: "1px solid var(--cinaf-border)",
            }}
          >
            <form onSubmit={handleSubmit}>
              <div className="modal-header" style={{ borderColor: "var(--cinaf-border)" }}>
                <h5 className="modal-title">Nouvelle saison</h5>
                <button
                  type="button"
                  className="btn-close btn-close-white"
                  onClick={onClose}
                  disabled={submitting}
                />
              </div>
              <div className="modal-body">
                <div className="mb-3">
                  <label className="form-label">Numéro de saison *</label>
                  <input
                    type="number"
                    min={1}
                    className="form-control"
                    value={number}
                    onChange={(e) => setNumber(Number(e.target.value))}
                    required
                    style={{
                      background: "var(--cinaf-surface-2)",
                      color: "var(--cinaf-text)",
                      border: "1px solid var(--cinaf-border)",
                    }}
                  />
                </div>
                <div className="mb-3">
                  <label className="form-label">Titre (optionnel)</label>
                  <input
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
                </div>
                <div className="mb-3">
                  <label className="form-label">Synopsis (optionnel)</label>
                  <textarea
                    rows={3}
                    className="form-control"
                    value={synopsis}
                    onChange={(e) => setSynopsis(e.target.value)}
                    style={{
                      background: "var(--cinaf-surface-2)",
                      color: "var(--cinaf-text)",
                      border: "1px solid var(--cinaf-border)",
                    }}
                  />
                </div>
                {error && (
                  <div
                    className="alert mb-0 py-2 small"
                    style={{
                      background: "#2a1414",
                      color: "#ff8a8a",
                      border: "1px solid #5a2020",
                    }}
                  >
                    {error}
                  </div>
                )}
              </div>
              <div className="modal-footer" style={{ borderColor: "var(--cinaf-border)" }}>
                <button type="button" className="btn btn-secondary" onClick={onClose} disabled={submitting}>
                  Annuler
                </button>
                <button
                  type="submit"
                  className="btn"
                  disabled={submitting}
                  style={{ background: "var(--cinaf-gold)", color: "#000", fontWeight: 600 }}
                >
                  {submitting ? "Création…" : "Créer"}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </>
  );
}

// ─── Modale "Ajouter un épisode" ─────────────────────────────

/**
 * Modale de création d'un épisode au sein d'une saison de la série.
 * 
 * Permet de définir :
 * - Le numéro de l'épisode et son titre.
 * - Le synopsis et la durée prévisionnelle.
 * - Le fichier vidéo associé (qui sera immédiatement transmis au gestionnaire
 *   d'upload en tâche de fond `UploadProvider` dès la validation).
 * 
 * @param props.open - Booléen déterminant l'affichage.
 * @param props.onClose - Callback de fermeture.
 * @param props.onCreated - Callback après création pour recharger l'arborescence.
 * @param props.serieId - UUID de la série.
 * @param props.seasonId - UUID de la saison ciblée.
 * @param props.existingNumbers - Numéros d'épisodes existants dans la saison.
 */
function EpisodeModal({
  open,
  onClose,
  onCreated,
  serieId,
  seasonId,
  existingNumbers,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: () => void | Promise<void>;
  serieId: string;
  seasonId: string | null;
  existingNumbers: number[];
}) {
  const nextNumber =
    existingNumbers.length === 0 ? 1 : Math.max(...existingNumbers) + 1;
  const [number, setNumber] = useState<number>(nextNumber);
  const [title, setTitle] = useState("");
  const [synopsis, setSynopsis] = useState("");
  const [duration, setDuration] = useState<number>(45);
  // La vidéo n'est PAS uploadée au moment du select (l'épisode n'existe
  // pas encore — le path Bunny ne peut donc pas être construit). On stocke
  // juste le File ; l'upload réel est déclenché après la création.
  const [videoFile, setVideoFile] = useState<File | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Accès au manager global pour enqueue l'upload après création.
  const { enqueue } = useUploads();

  useEffect(() => {
    if (open) {
      setNumber(nextNumber);
      setTitle("");
      setSynopsis("");
      setDuration(45);
      setVideoFile(null);
      setError(null);
      setSubmitting(false);
    }
  }, [open, nextNumber]);

  if (!open || !seasonId) return null;

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!seasonId) return;
    setError(null);
    setSubmitting(true);
    try {
      // 1. Créer l'épisode (sans bunnyVideoId — la vidéo arrivera après).
      const episode = await studioSeries.createEpisode(serieId, seasonId, {
        number,
        title: title.trim(),
        synopsis: synopsis.trim() || undefined,
        duration,
      });
      // 2. Si une vidéo a été sélectionnée, l'enqueue dans le manager.
      //    L'upload tourne en arrière-plan dans le tray, l'auto-PATCH
      //    écrit bunnyVideoId quand c'est fini.
      if (videoFile) {
        enqueue(videoFile, {
          type: "episode",
          serieId,
          seasonId,
          episodeId: episode.id,
          purpose: "video",
        });
      }
      await onCreated();
    } catch (err: unknown) {
      setError((err as { message?: string })?.message ?? "Création impossible.");
      setSubmitting(false);
    }
  }

  return (
    <>
      <div className="modal-backdrop fade show" style={{ zIndex: 1050 }} onClick={onClose} />
      <div className="modal fade show d-block" tabIndex={-1} role="dialog" style={{ zIndex: 1055 }}>
        <div className="modal-dialog modal-dialog-centered modal-lg">
          <div
            className="modal-content"
            style={{
              background: "var(--cinaf-surface)",
              color: "var(--cinaf-text)",
              border: "1px solid var(--cinaf-border)",
            }}
          >
            <form onSubmit={handleSubmit}>
              <div className="modal-header" style={{ borderColor: "var(--cinaf-border)" }}>
                <h5 className="modal-title">Nouvel épisode</h5>
                <button
                  type="button"
                  className="btn-close btn-close-white"
                  onClick={onClose}
                  disabled={submitting}
                />
              </div>
              <div className="modal-body">
                <div className="row g-3">
                  <div className="col-12 col-md-3">
                    <label className="form-label">Numéro *</label>
                    <input
                      type="number"
                      min={1}
                      className="form-control"
                      value={number}
                      onChange={(e) => setNumber(Number(e.target.value))}
                      required
                      style={{
                        background: "var(--cinaf-surface-2)",
                        color: "var(--cinaf-text)",
                        border: "1px solid var(--cinaf-border)",
                      }}
                    />
                  </div>
                  <div className="col-12 col-md-9">
                    <label className="form-label">Titre *</label>
                    <input
                      type="text"
                      className="form-control"
                      value={title}
                      onChange={(e) => setTitle(e.target.value)}
                      required
                      style={{
                        background: "var(--cinaf-surface-2)",
                        color: "var(--cinaf-text)",
                        border: "1px solid var(--cinaf-border)",
                      }}
                    />
                  </div>
                </div>
                <div className="mt-3">
                  <label className="form-label">Synopsis</label>
                  <textarea
                    rows={2}
                    className="form-control"
                    value={synopsis}
                    onChange={(e) => setSynopsis(e.target.value)}
                    style={{
                      background: "var(--cinaf-surface-2)",
                      color: "var(--cinaf-text)",
                      border: "1px solid var(--cinaf-border)",
                    }}
                  />
                </div>
                <div className="row g-3 mt-1">
                  <div className="col-12 col-md-6">
                    <label className="form-label">Durée (minutes)</label>
                    <input
                      type="number"
                      min={1}
                      className="form-control"
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
                <div className="mt-3">
                  <label className="form-label">Vidéo (optionnelle)</label>
                  <div className="small mb-2" style={{ color: "var(--cinaf-text-muted)" }}>
                    L&apos;upload démarre après la création de l&apos;épisode (en arrière-plan dans la barre en bas à droite).
                  </div>
                  <input
                    type="file"
                    accept="video/mp4,video/quicktime,video/webm"
                    onChange={(e) => setVideoFile(e.target.files?.[0] ?? null)}
                    disabled={submitting}
                    className="form-control"
                    style={{
                      background: "var(--cinaf-surface-2)",
                      color: "var(--cinaf-text)",
                      border: "1px solid var(--cinaf-border)",
                    }}
                  />
                  {videoFile && (
                    <div className="small mt-1" style={{ color: "#8fd68f" }}>
                      <i className="bi bi-paperclip me-1" />
                      {videoFile.name} ({Math.round(videoFile.size / 1024 / 1024)} Mo) — sera uploadé après création
                    </div>
                  )}
                </div>
                {error && (
                  <div
                    className="alert mt-3 mb-0 py-2 small"
                    style={{
                      background: "#2a1414",
                      color: "#ff8a8a",
                      border: "1px solid #5a2020",
                    }}
                  >
                    {error}
                  </div>
                )}
              </div>
              <div className="modal-footer" style={{ borderColor: "var(--cinaf-border)" }}>
                <button type="button" className="btn btn-secondary" onClick={onClose} disabled={submitting}>
                  Annuler
                </button>
                <button
                  type="submit"
                  className="btn"
                  disabled={submitting}
                  style={{ background: "var(--cinaf-gold)", color: "#000", fontWeight: 600 }}
                >
                  {submitting ? "Création…" : "Créer"}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </>
  );
}
