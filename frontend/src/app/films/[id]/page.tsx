"use client";

// ============================================================
// CINAF v2 — Détail Film (alimenté par catalogue Bunny)
// Films courts/teasers (œuvre flat) — bouton "Regarder" direct
// vers le 1er épisode disponible.
// ============================================================

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { discover, type DiscoverWork } from "@/lib/api";
import PlaceholderPoster from "@/components/PlaceholderPoster";

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

  return (
    <div className="container py-4">
      <Link
        href="/films"
        className="d-inline-block mb-3"
        style={{ color: "var(--cinaf-text-muted)", textDecoration: "none" }}
      >
        <i className="bi bi-arrow-left me-1" />
        Retour aux films
      </Link>

      <div className="row g-4 mb-4">
        <div className="col-12 col-md-4 col-lg-3">
          <PlaceholderPoster title={work.title} ratio="portrait" />
        </div>
        <div className="col-12 col-md-8 col-lg-9">
          <h1 style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
            {work.title.replace(/_/g, " ")}
          </h1>
          <p style={{ color: "var(--cinaf-text-muted)" }}>
            {allEpisodes.length === 1
              ? "Film disponible en streaming HD adaptatif."
              : `${allEpisodes.length} épisode${allEpisodes.length > 1 ? "s" : ""} disponible${allEpisodes.length > 1 ? "s" : ""} en streaming HD adaptatif.`}
          </p>

          {/* Zone "Publié par" — cliquable vers la chaîne studio publique
              (style YouTube "channel preview"). Visible uniquement si
              l'œuvre porte une référence studio (mode catalogue DB). */}
          {work.studio && (
            <Link
              href={`/studios/${work.studio.slug}`}
              className="d-inline-flex align-items-center gap-2 mb-3 px-3 py-2"
              style={{
                color: "var(--cinaf-text)",
                textDecoration: "none",
                background: "var(--cinaf-surface)",
                border: "1px solid var(--cinaf-border)",
                borderRadius: 8,
              }}
            >
              {work.studio.logoUrl ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={work.studio.logoUrl}
                  alt={work.studio.name}
                  width={32}
                  height={32}
                  style={{ borderRadius: 6, objectFit: "cover" }}
                />
              ) : (
                <span
                  className="d-inline-flex align-items-center justify-content-center"
                  style={{
                    width: 32,
                    height: 32,
                    borderRadius: 6,
                    background: "var(--cinaf-gold)",
                    color: "var(--cinaf-bg)",
                    fontWeight: 700,
                    fontSize: 14,
                  }}
                >
                  {work.studio.name.substring(0, 2).toUpperCase()}
                </span>
              )}
              <span>
                <span style={{ color: "var(--cinaf-text-muted)", fontSize: 13 }}>
                  Publié par
                </span>{" "}
                <span style={{ fontWeight: 600 }}>{work.studio.name}</span>
              </span>
              <i className="bi bi-chevron-right ms-1" style={{ color: "var(--cinaf-gold)" }} />
            </Link>
          )}

          {firstEpisode && firstSeason && (
            <Link
              href={`/watch/film/${slug}?ep=${encodeURIComponent(
                firstEpisode.slug,
              )}&s=${encodeURIComponent(firstSeason.slug)}`}
              className="btn btn-cinaf px-4"
            >
              <i className="bi bi-play-fill me-1" />
              Regarder
            </Link>
          )}
        </div>
      </div>

      {/* Liste des épisodes si l'œuvre en contient plus d'un — couvre les
          films courts à plusieurs parties (ex. teasers BA_xxx) ET les œuvres
          multi-épisodes type LE_PROCCES (~90 épisodes) mal classifiées en
          Film mais correctement résolues par le backend depuis 2026-05-22.
          Le conteneur scrollable évite de dérouler une page interminable
          pour les longues séries. */}
      {allEpisodes.length > 1 && firstSeason && (
        <div>
          <h5 className="mb-3" style={{ color: "var(--cinaf-text-muted)" }}>
            Tous les épisodes ({allEpisodes.length})
          </h5>
          <ul
            className="list-group"
            style={{ maxHeight: 600, overflowY: "auto" }}
          >
            {work.seasons.flatMap((season) =>
              season.episodes.map((ep) => (
                <li
                  key={`${season.slug}-${ep.slug}`}
                  className="list-group-item d-flex align-items-center justify-content-between"
                  style={{
                    background: "var(--cinaf-surface)",
                    color: "var(--cinaf-text)",
                    border: "1px solid var(--cinaf-border)",
                    marginBottom: 4,
                    borderRadius: 6,
                  }}
                >
                  <span>
                    <i className="bi bi-play-circle me-2" style={{ color: "var(--cinaf-gold)" }} />
                    {ep.name.replace(/_/g, " ")}
                  </span>
                  <Link
                    href={`/watch/film/${slug}?ep=${encodeURIComponent(
                      ep.slug,
                    )}&s=${encodeURIComponent(season.slug)}`}
                    className="btn btn-sm btn-cinaf-outline"
                  >
                    Regarder
                  </Link>
                </li>
              )),
            )}
          </ul>
        </div>
      )}
    </div>
  );
}
