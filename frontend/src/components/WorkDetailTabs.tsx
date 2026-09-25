"use client";

/**
 * ============================================================
 * CINAF v2 — Onglets de la fiche d'œuvre (WorkDetailTabs)
 * ============================================================
 * Partie basse des fiches films et séries, calquée sur cinaf.tv :
 *
 * - « Épisodes » : sélecteur de saison (si plusieurs), grille de cartes
 *   (vignette 16:9, numéro, titre, durée), 12 cartes affichées puis
 *   « Voir N épisodes de plus ». Masqué pour un film mono-partie.
 * - « Contenu associé » : « Vous aimerez aussi », œuvres du même format
 *   chargées à la demande via `discover.list` (l'œuvre courante exclue).
 * - « Détails » : titre original, année, pays, genres, réalisation, casting,
 *   durée / saisons et **studio éditeur** — le studio reste ainsi visible
 *   même si le visiteur ne remonte pas au hero.
 *
 * Les lignes dont la donnée est absente ne sont pas rendues.
 */

import Link from "next/link";
import { useEffect, useState } from "react";
import { discover, type DiscoverEpisode, type DiscoverWork, type DiscoverWorkSummary } from "@/lib/api";
import WorkCard from "./WorkCard";
import PlaceholderPoster from "./PlaceholderPoster";
import { formatDuration } from "./WorkDetailHero";

type Tab = "episodes" | "related" | "details";

/** Nombre de cartes d'épisodes visibles avant « Voir plus ». */
const EPISODES_PAGE = 12;
/** Nombre de suggestions dans « Vous aimerez aussi ». */
const RELATED_LIMIT = 12;

/**
 * Nom d'épisode lisible à partir du nom de dossier Bunny importé
 * (« 1a1eca89…_EP_4 » → « Épisode 4 », « MON_TITRE » → « MON TITRE »).
 */
function episodeDisplayName(raw: string, number: number): string {
  const cleaned = raw
    .replace(/^[0-9a-f]{32}[_-]?/i, "") // hash technique en préfixe
    .replace(/_/g, " ")
    .trim();
  if (cleaned === "" || /^EP(?:ISODE)?\s*\d+$/i.test(cleaned)) {
    return `Épisode ${number}`;
  }
  return cleaned;
}

/**
 * Propriétés attendues par le composant `WorkDetailTabs`.
 */
interface WorkDetailTabsProps {
  /** L'œuvre affichée */
  work: DiscoverWork;
  /** Construit l'URL de lecture d'un épisode d'une saison donnée */
  hrefFor: (seasonSlug: string, ep: DiscoverEpisode) => string;
}

/**
 * Onglets « Épisodes / Contenu associé / Détails » d'une fiche d'œuvre.
 *
 * @param props - Propriétés du composant
 * @returns La zone d'onglets et son contenu actif
 */
export default function WorkDetailTabs({ work, hrefFor }: WorkDetailTabsProps) {
  const isSerie = work.kind === "serie";
  const totalEpisodes = work.seasons.reduce((n, s) => n + s.episodes.length, 0);
  // Un film mono-partie n'a pas d'onglet Épisodes : on ouvre sur les détails.
  const hasEpisodesTab = isSerie || totalEpisodes > 1;

  const [tab, setTab] = useState<Tab>(hasEpisodesTab ? "episodes" : "details");
  const [seasonSlug, setSeasonSlug] = useState(work.seasons[0]?.slug ?? "");
  const [shown, setShown] = useState(EPISODES_PAGE);
  const [related, setRelated] = useState<DiscoverWorkSummary[] | null>(null);

  const season = work.seasons.find((s) => s.slug === seasonSlug) ?? work.seasons[0];

  // Suggestions chargées une seule fois, à la première ouverture de l'onglet.
  useEffect(() => {
    if (tab !== "related" || related !== null) return;
    let cancelled = false;
    discover
      .list({ kind: work.kind, limit: RELATED_LIMIT + 1 })
      .then((res) => {
        if (cancelled) return;
        setRelated(res.data.filter((w) => w.slug !== work.slug).slice(0, RELATED_LIMIT));
      })
      .catch(() => {
        if (!cancelled) setRelated([]);
      });
    return () => {
      cancelled = true;
    };
  }, [tab, related, work.kind, work.slug]);

  const tabs: Array<{ key: Tab; label: string }> = [
    ...(hasEpisodesTab ? [{ key: "episodes" as Tab, label: "Épisodes" }] : []),
    { key: "related", label: "Contenu associé" },
    { key: "details", label: "Détails" },
  ];

  return (
    <div className="container py-4">
      <div className="work-tabs" role="tablist">
        {tabs.map((t) => (
          <button
            key={t.key}
            type="button"
            role="tab"
            aria-selected={tab === t.key}
            className={`work-tab ${tab === t.key ? "active" : ""}`}
            onClick={() => setTab(t.key)}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* ---------------- Épisodes ---------------- */}
      {tab === "episodes" && season && (
        <div>
          {work.seasons.length > 1 && (
            <div className="d-flex flex-wrap gap-2 mb-3" role="tablist" aria-label="Choisir une saison">
              {work.seasons.map((s) => (
                <button
                  key={s.slug}
                  type="button"
                  className={`filter-chip ${s.slug === season.slug ? "active" : ""}`}
                  aria-pressed={s.slug === season.slug}
                  onClick={() => {
                    setSeasonSlug(s.slug);
                    setShown(EPISODES_PAGE);
                  }}
                >
                  {s.name.replace(/_/g, " ")}
                </button>
              ))}
            </div>
          )}

          {season.episodes.length === 0 ? (
            <p style={{ color: "var(--cinaf-text-muted)" }}>Aucun épisode disponible.</p>
          ) : (
            <div className="row g-3">
              {season.episodes.slice(0, shown).map((ep, idx) => {
                const number = ep.number ?? idx + 1;
                const name = episodeDisplayName(ep.name, number);
                // Sans titre propre, « Épisode N » suffit : pas de ligne numéro en doublon.
                const hasOwnTitle = name !== `Épisode ${number}`;
                return (
                  <div key={ep.slug} className="col-6 col-md-4 col-lg-3">
                    <Link href={hrefFor(season.slug, ep)} className="episode-card">
                      <div className="episode-card-thumb">
                        {work.poster ? (
                          // eslint-disable-next-line @next/next/no-img-element
                          <img src={work.poster} alt="" loading="lazy" />
                        ) : (
                          <PlaceholderPoster title={name} ratio="wide" />
                        )}
                        <span className="episode-card-play">
                          <i className="bi bi-play-circle-fill" />
                        </span>
                      </div>
                      <div className="episode-card-body">
                        {hasOwnTitle && (
                          <div className="episode-card-number">
                            {isSerie ? `Ép. ${number}` : `Partie ${number}`}
                          </div>
                        )}
                        <div className="episode-card-title" title={name}>{name}</div>
                        {ep.duration ? (
                          <div className="episode-card-meta">{formatDuration(ep.duration)}</div>
                        ) : null}
                      </div>
                    </Link>
                  </div>
                );
              })}
            </div>
          )}

          {season.episodes.length > shown && (
            <div className="text-center mt-4">
              <button
                type="button"
                className="btn btn-cinaf-outline"
                onClick={() => setShown((n) => n + EPISODES_PAGE)}
              >
                Voir {season.episodes.length - shown} épisode
                {season.episodes.length - shown > 1 ? "s" : ""} de plus
              </button>
            </div>
          )}
        </div>
      )}

      {/* ---------------- Contenu associé ---------------- */}
      {tab === "related" && (
        <div>
          <h5 className="mb-3" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
            Vous aimerez aussi
          </h5>
          {related === null ? (
            <div className="text-center py-4">
              <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} role="status" />
            </div>
          ) : related.length === 0 ? (
            <p style={{ color: "var(--cinaf-text-muted)" }}>Aucune suggestion pour le moment.</p>
          ) : (
            <div className="row g-3">
              {related.map((w) => (
                <div key={w.slug} className="col-6 col-sm-4 col-md-3 col-lg-2">
                  <WorkCard work={w} />
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* ---------------- Détails ---------------- */}
      {tab === "details" && (
        <dl className="work-details">
          <dt>Titre original</dt>
          <dd>{work.title.replace(/_/g, " ")}</dd>

          <dt>Format</dt>
          <dd>{isSerie ? "Série" : "Film"}</dd>

          {work.year ? (
            <>
              <dt>Année</dt>
              <dd>{work.year}</dd>
            </>
          ) : null}

          {isSerie ? (
            <>
              <dt>Saisons</dt>
              <dd>
                {work.nbSeasons ?? work.seasons.length} · {totalEpisodes} épisode
                {totalEpisodes > 1 ? "s" : ""}
              </dd>
            </>
          ) : work.duration ? (
            <>
              <dt>Durée</dt>
              <dd>{formatDuration(work.duration)}</dd>
            </>
          ) : null}

          {work.countries?.length ? (
            <>
              <dt>Pays</dt>
              <dd>{work.countries.join(", ")}</dd>
            </>
          ) : null}

          {work.genres?.length ? (
            <>
              <dt>Genres</dt>
              <dd>{work.genres.join(", ")}</dd>
            </>
          ) : null}

          {work.directors?.length ? (
            <>
              <dt>Réalisation</dt>
              <dd>{work.directors.join(", ")}</dd>
            </>
          ) : null}

          {work.cast?.length ? (
            <>
              <dt>Casting</dt>
              <dd>{work.cast.join(", ")}</dd>
            </>
          ) : null}

          {work.studio && (
            <>
              <dt>Studio</dt>
              <dd>
                <Link href={`/studios/${work.studio.slug}`} style={{ color: "var(--cinaf-gold)" }}>
                  {work.studio.name}
                </Link>
              </dd>
            </>
          )}
        </dl>
      )}
    </div>
  );
}
