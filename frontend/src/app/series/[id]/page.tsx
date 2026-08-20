"use client";

// ============================================================
// CINAF v2 — Détail Série (alimenté par catalogue Bunny)
// Design inspiré de cinaf.tv : hero cinématographique + sélecteur
// de saison (pastilles) + liste d'épisodes soignée.
// ============================================================

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { discover, type DiscoverWork } from "@/lib/api";
import WorkDetailHero from "@/components/WorkDetailHero";
import EpisodeList from "@/components/EpisodeList";

export default function SerieDetailPage() {
  const params = useParams();
  const slug = params.id as string;

  const [work, setWork] = useState<DiscoverWork | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  // Saison sélectionnée dans le sélecteur (slug). Vide = 1re saison par défaut.
  const [selectedSeason, setSelectedSeason] = useState<string>("");

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    discover
      .get(slug)
      .then((w) => {
        if (cancelled) return;
        if (w.kind === "film") {
          setError("redirect-film");
        } else {
          setWork(w);
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const status = (err as { statusCode?: number })?.statusCode;
        const msg = (err as { message?: string })?.message ?? "Erreur inconnue";
        setError(status === 404 ? "Série introuvable." : msg);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [slug]);

  if (loading) {
    return (
      <div className="container py-5 text-center">
        <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} role="status" />
      </div>
    );
  }

  if (error === "redirect-film") {
    return (
      <div className="container py-5 text-center">
        <p style={{ color: "var(--cinaf-text-muted)" }}>Cette œuvre est un film.</p>
        <Link href={`/films/${slug}`} className="btn btn-cinaf">
          Voir la fiche film
        </Link>
      </div>
    );
  }

  if (error || !work) {
    return (
      <div className="container py-5 text-center">
        <i
          className="bi bi-exclamation-triangle"
          style={{ fontSize: "3rem", color: "var(--cinaf-gold)" }}
        />
        <h3 className="mt-3" style={{ color: "var(--cinaf-text)" }}>
          {error ?? "Série introuvable."}
        </h3>
        <Link href="/series" className="btn btn-cinaf-outline mt-3">
          <i className="bi bi-arrow-left me-1" />
          Retour aux séries
        </Link>
      </div>
    );
  }

  const totalEpisodes = work.seasons.reduce((acc, s) => acc + s.episodes.length, 0);
  const firstSeason = work.seasons[0];
  const firstEpisode = firstSeason?.episodes[0];

  // Saison affichée : celle du sélecteur, sinon la première.
  const activeSeason =
    work.seasons.find((s) => s.slug === selectedSeason) ?? firstSeason;

  const metaItems = [
    "HD",
    `${work.seasons.length} saison${work.seasons.length > 1 ? "s" : ""}`,
    `${totalEpisodes} épisode${totalEpisodes > 1 ? "s" : ""}`,
  ];

  return (
    <div>
      <WorkDetailHero
        work={work}
        backHref="/series"
        backLabel="Retour aux séries"
        metaItems={metaItems}
        actions={
          firstEpisode && firstSeason ? (
            <Link
              href={`/watch/episode/${slug}?ep=${encodeURIComponent(
                firstEpisode.slug,
              )}&s=${encodeURIComponent(firstSeason.slug)}`}
              className="btn btn-cinaf px-4"
            >
              <i className="bi bi-play-fill me-1" />
              Regarder le premier épisode
            </Link>
          ) : null
        }
      />

      <div className="container py-5">
        <div className="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
          <h4 className="mb-0" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
            <i className="bi bi-collection-play me-2" style={{ color: "var(--cinaf-gold)" }} />
            Épisodes
          </h4>

          {/* Sélecteur de saison (pastilles) — masqué si une seule saison. */}
          {work.seasons.length > 1 && (
            <div className="d-flex flex-wrap gap-2" role="tablist" aria-label="Choisir une saison">
              {work.seasons.map((season) => {
                const isActive = activeSeason?.slug === season.slug;
                return (
                  <button
                    key={season.slug}
                    type="button"
                    className={`filter-chip ${isActive ? "active" : ""}`}
                    aria-pressed={isActive}
                    onClick={() => setSelectedSeason(season.slug)}
                  >
                    {season.name.replace(/_/g, " ")}
                  </button>
                );
              })}
            </div>
          )}
        </div>

        {activeSeason && (
          <EpisodeList
            episodes={activeSeason.episodes}
            hrefFor={(ep) =>
              `/watch/episode/${slug}?ep=${encodeURIComponent(
                ep.slug,
              )}&s=${encodeURIComponent(activeSeason.slug)}`
            }
          />
        )}
      </div>
    </div>
  );
}
