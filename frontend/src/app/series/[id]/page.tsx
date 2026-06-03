"use client";

// ============================================================
// CINAF v2 — Détail Série (alimenté par catalogue Bunny)
// Affiche un accordéon des saisons + bouton "Regarder épisode N"
// vers /watch/episode/[slug]
// ============================================================

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { discover, type DiscoverWork } from "@/lib/api";
import PlaceholderPoster from "@/components/PlaceholderPoster";

export default function SerieDetailPage() {
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

  return (
    <div className="container py-4">
      <Link
        href="/series"
        className="d-inline-block mb-3"
        style={{ color: "var(--cinaf-text-muted)", textDecoration: "none" }}
      >
        <i className="bi bi-arrow-left me-1" />
        Retour aux séries
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
            {work.seasons.length} saison{work.seasons.length > 1 ? "s" : ""} ·{" "}
            {totalEpisodes} épisode{totalEpisodes > 1 ? "s" : ""}
          </p>

          {/* Zone "Publié par" — cliquable vers la chaîne studio publique.
              Identique au pattern de la page film. Visible uniquement si
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
              href={`/watch/episode/${slug}?ep=${encodeURIComponent(
                firstEpisode.slug,
              )}&s=${encodeURIComponent(firstSeason.slug)}`}
              className="btn btn-cinaf px-4"
            >
              <i className="bi bi-play-fill me-1" />
              Regarder le premier épisode
            </Link>
          )}
        </div>
      </div>

      <div className="accordion" id="seasonsAccordion">
        {work.seasons.map((season, idx) => (
          <div
            key={season.slug}
            className="accordion-item"
            style={{
              background: "var(--cinaf-surface)",
              border: "1px solid var(--cinaf-border)",
              marginBottom: 8,
              borderRadius: 8,
              overflow: "hidden",
            }}
          >
            <h2 className="accordion-header" id={`heading-${season.slug}`}>
              <button
                className={`accordion-button ${idx === 0 ? "" : "collapsed"}`}
                type="button"
                data-bs-toggle="collapse"
                data-bs-target={`#collapse-${season.slug}`}
                aria-expanded={idx === 0}
                aria-controls={`collapse-${season.slug}`}
                style={{
                  background: "var(--cinaf-surface-2)",
                  color: "var(--cinaf-text)",
                  fontWeight: 600,
                }}
              >
                {season.name.replace(/_/g, " ")}
                <span className="badge bg-secondary ms-3">{season.episodes.length} ép.</span>
              </button>
            </h2>
            <div
              id={`collapse-${season.slug}`}
              className={`accordion-collapse collapse ${idx === 0 ? "show" : ""}`}
              aria-labelledby={`heading-${season.slug}`}
              data-bs-parent="#seasonsAccordion"
            >
              <div className="accordion-body p-0">
                <ul className="list-group list-group-flush">
                  {season.episodes.length === 0 && (
                    <li
                      className="list-group-item"
                      style={{
                        background: "var(--cinaf-surface)",
                        color: "var(--cinaf-text-muted)",
                      }}
                    >
                      Aucun épisode disponible.
                    </li>
                  )}
                  {season.episodes.map((ep) => (
                    <li
                      key={ep.slug}
                      className="list-group-item d-flex align-items-center justify-content-between"
                      style={{
                        background: "var(--cinaf-surface)",
                        color: "var(--cinaf-text)",
                        border: 0,
                        borderTop: "1px solid var(--cinaf-border)",
                      }}
                    >
                      <span>
                        <i
                          className="bi bi-play-circle me-2"
                          style={{ color: "var(--cinaf-gold)" }}
                        />
                        {ep.name.replace(/_/g, " ")}
                      </span>
                      <Link
                        href={`/watch/episode/${slug}?ep=${encodeURIComponent(
                          ep.slug,
                        )}&s=${encodeURIComponent(season.slug)}`}
                        className="btn btn-sm btn-cinaf-outline"
                      >
                        Regarder
                      </Link>
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
