"use client";

/**
 * ============================================================
 * CINAF v2 — Hero cinématographique de détail (WorkDetailHero)
 * ============================================================
 * Bannière d'en-tête des fiches films et séries, calquée sur la page titre
 * de cinaf.tv :
 * - Backdrop plein cadre : l'affiche, agrandie et floutée, sert de fond
 *   (aucun visuel paysage n'existe côté données), fondu vers le fond du site.
 * - Affiche portrait à gauche, texte à droite.
 * - Ligne de métadonnées séparées par des puces « • » (HD, format, genres,
 *   année, saisons / durée) — chaque information absente est simplement omise.
 * - Action principale « Lire S.1 Ép.1 » (ou « Regarder » pour un film),
 *   bande-annonce optionnelle lue dans un pop-up dédié (`TrailerModal`),
 *   mention « Inclus dans CINAF PREMIUM ».
 * - Synopsis sous les actions, puis pastille studio (`StudioChip`) : le
 *   studio ayant publié l'œuvre reste toujours visible sur la fiche.
 */

import Link from "next/link";
import { useState } from "react";
import type { DiscoverWork } from "@/lib/api";
import Poster from "./Poster";
import StudioChip from "./StudioChip";
import TrailerModal from "./TrailerModal";

/**
 * Propriétés attendues par le composant `WorkDetailHero`.
 */
interface WorkDetailHeroProps {
  /** L'œuvre complète à présenter */
  work: DiscoverWork;
  /** Lien de retour vers le catalogue ou la catégorie parente */
  backHref: string;
  /** Texte du lien de retour (ex: "Retour aux films") */
  backLabel: string;
  /** URL de lecture du premier épisode / de l'œuvre, ou null si rien n'est lisible */
  playHref: string | null;
  /** Libellé du bouton de lecture (ex: "Lire S.1 Ép.1") */
  playLabel: string;
}

/** Formate une durée en minutes façon cinaf.tv : « 1 h 32 min », « 27 min ». */
export function formatDuration(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  if (h === 0) return `${m} min`;
  return m === 0 ? `${h} h` : `${h} h ${m} min`;
}

/**
 * Bannière héro cinématographique pour les fiches descriptives d'œuvres.
 *
 * @param props - Propriétés du composant
 * @returns La section hero plein cadre
 */
export default function WorkDetailHero({
  work,
  backHref,
  backLabel,
  playHref,
  playLabel,
}: WorkDetailHeroProps) {
  const isSerie = work.kind === "serie";
  const [showTrailer, setShowTrailer] = useState(false);
  const title = work.title.replace(/_/g, " ");

  // Ligne de métadonnées : uniquement ce qui est réellement renseigné.
  const meta: string[] = ["HD", isSerie ? "Série" : "Film"];
  if (work.genres?.length) meta.push(work.genres.join(" • "));
  if (work.year) meta.push(String(work.year));
  if (isSerie) {
    const n = work.nbSeasons ?? work.seasons.length;
    if (n > 0) meta.push(`${n} saison${n > 1 ? "s" : ""}`);
  } else if (work.duration) {
    meta.push(formatDuration(work.duration));
  }

  return (
    <section className="work-hero">
      {/* Backdrop : affiche floutée + fondu vers le fond de page */}
      {work.poster && (
        <div
          className="work-hero-backdrop"
          style={{ backgroundImage: `url(${work.poster})` }}
          aria-hidden="true"
        />
      )}
      <div className="work-hero-fade" aria-hidden="true" />

      <div className="container position-relative">
        <div className="pt-4">
          <Link
            href={backHref}
            style={{ color: "var(--cinaf-text-muted)", textDecoration: "none" }}
          >
            <i className="bi bi-arrow-left me-1" />
            {backLabel}
          </Link>
        </div>

        <div className="row g-4 align-items-end pt-4 pb-5">
          <div className="col-6 col-sm-4 col-lg-3">
            <div className="work-hero-poster">
              <Poster title={title} src={work.poster} ratio="portrait" />
            </div>
          </div>

          <div className="col-12 col-sm-8 col-lg-9">
            <h1 className="work-hero-title">{title}</h1>

            {/* Métadonnées séparées par des puces, comme sur cinaf.tv */}
            <p className="work-hero-meta">
              {meta.map((item, i) => (
                <span key={`${item}-${i}`}>
                  {i > 0 && <span className="work-hero-dot">•</span>}
                  {item}
                </span>
              ))}
            </p>

            <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
              {playHref && (
                <Link href={playHref} className="btn btn-cinaf px-4">
                  <i className="bi bi-play-fill me-1" />
                  {playLabel}
                </Link>
              )}
              {work.trailerUrl && (
                <button
                  type="button"
                  className="btn btn-cinaf-outline px-4"
                  onClick={() => setShowTrailer(true)}
                  aria-haspopup="dialog"
                >
                  <i className="bi bi-film me-1" />
                  Bande-annonce
                </button>
              )}
            </div>

            <p className="work-hero-premium">
              <i className="bi bi-star-fill me-1" />
              Inclus dans CINAF PREMIUM
            </p>

            {work.synopsis && <p className="work-hero-synopsis">{work.synopsis}</p>}

            {/* Le studio éditeur reste visible sur toutes les fiches */}
            {work.studio && (
              <div className="mt-3">
                <StudioChip studio={work.studio} />
              </div>
            )}
          </div>
        </div>

        {/* Bande-annonce (HLS Bunny Storage) lue dans un pop-up dédié */}
        {work.trailerUrl && (
          <TrailerModal
            open={showTrailer}
            src={work.trailerUrl}
            title={title}
            poster={work.poster ?? undefined}
            onClose={() => setShowTrailer(false)}
          />
        )}
      </div>
    </section>
  );
}
