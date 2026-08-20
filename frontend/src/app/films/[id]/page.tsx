"use client";

// ============================================================
// CINAF v2 — Détail Film (alimenté par catalogue Bunny)
// Films courts/teasers (œuvre flat) — bouton "Regarder" direct
// vers le 1er épisode disponible. Design inspiré de cinaf.tv :
// hero cinématographique + liste d'épisodes soignée.
// ============================================================

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { discover, type DiscoverWork } from "@/lib/api";
import WorkDetailHero from "@/components/WorkDetailHero";
import EpisodeList from "@/components/EpisodeList";

export default function FilmDetailPage() {
  const params = useParams();
  const slug = params.id as string;

  const [work, setWork] = useState<DiscoverWork | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    discover
      .get(slug)
      .then((w) => {
        if (cancelled) return;
        if (w.kind === "serie") {
          setError("redirect-serie");
        } else {
          setWork(w);
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const status = (err as { statusCode?: number })?.statusCode;
        const msg = (err as { message?: string })?.message ?? "Erreur inconnue";
        setError(status === 404 ? "Film introuvable." : msg);
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

  if (error === "redirect-serie") {
    return (
      <div className="container py-5 text-center">
        <p style={{ color: "var(--cinaf-text-muted)" }}>Cette œuvre est une série.</p>
        <Link href={`/series/${slug}`} className="btn btn-cinaf">
          Voir la fiche série
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
          {error ?? "Film introuvable."}
        </h3>
        <Link href="/films" className="btn btn-cinaf-outline mt-3">
          <i className="bi bi-arrow-left me-1" />
          Retour aux films
        </Link>
      </div>
    );
  }

  const allEpisodes = work.seasons.flatMap((s) => s.episodes);
  const firstEpisode = allEpisodes[0];
  const firstSeason = work.seasons[0];

  // Pastilles de métadonnées (données réellement disponibles côté Bunny).
  const metaItems = ["HD"];
  if (allEpisodes.length > 1) metaItems.push(`${allEpisodes.length} épisodes`);

  // Numéro de départ par saison (les films sont généralement mono-saison,
  // mais certaines œuvres multi-parties sont classées Film).
  let running = 1;
  const seasonBlocks = work.seasons.map((season) => {
    const start = running;
    running += season.episodes.length;
    return { season, start };
  });

  return (
    <div>
      <WorkDetailHero
        work={work}
        backHref="/films"
        backLabel="Retour aux films"
        metaItems={metaItems}
        actions={
          firstEpisode && firstSeason ? (
            <Link
              href={`/watch/film/${slug}?ep=${encodeURIComponent(
                firstEpisode.slug,
              )}&s=${encodeURIComponent(firstSeason.slug)}`}
              className="btn btn-cinaf px-4"
            >
              <i className="bi bi-play-fill me-1" />
              Regarder
            </Link>
          ) : null
        }
      />

      {/* Liste des épisodes si l'œuvre en contient plus d'un — couvre les
          films courts à plusieurs parties ET les œuvres multi-épisodes mal
          classifiées en Film mais correctement résolues par le backend. */}
      {allEpisodes.length > 1 && (
        <div className="container py-5">
          <h4 className="mb-3" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
            <i className="bi bi-collection-play me-2" style={{ color: "var(--cinaf-gold)" }} />
            Épisodes
            <span
              className="badge ms-2"
              style={{ background: "var(--cinaf-gold)", color: "#000", fontSize: "0.7rem" }}
            >
              {allEpisodes.length}
            </span>
          </h4>

          {seasonBlocks.map(({ season, start }) => (
            <div key={season.slug} className="mb-3">
              {work.seasons.length > 1 && (
                <h6 className="mb-2" style={{ color: "var(--cinaf-text-muted)" }}>
                  {season.name.replace(/_/g, " ")}
                </h6>
              )}
              <EpisodeList
                episodes={season.episodes}
                startNumber={start}
                hrefFor={(ep) =>
                  `/watch/film/${slug}?ep=${encodeURIComponent(
                    ep.slug,
                  )}&s=${encodeURIComponent(season.slug)}`
                }
              />
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
