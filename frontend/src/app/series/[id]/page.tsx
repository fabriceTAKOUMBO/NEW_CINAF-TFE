"use client";

// ============================================================
// CINAF v2 — Détail Série (alimenté par catalogue Bunny)
// Design calqué sur la page titre de cinaf.tv : hero plein cadre
// (WorkDetailHero) puis onglets Épisodes (sélecteur de saison, grille)
// / Contenu associé / Détails (WorkDetailTabs). Le studio éditeur reste
// visible sur la fiche.
// ============================================================

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { discover, type DiscoverWork } from "@/lib/api";
import WorkDetailHero from "@/components/WorkDetailHero";
import WorkDetailTabs from "@/components/WorkDetailTabs";
import NotFoundView from "@/components/NotFoundView";
import ContentWithdrawnView, { withdrawnInfoFrom, type WithdrawnInfo } from "@/components/ContentWithdrawnView";

/**
 * Fiche détaillée d'une série télévisée.
 * 
 * Fonctionnalités :
 * - Charge l'arborescence complète de la série (saisons et épisodes) via son slug (`discover.get`).
 * - Redirige vers `/films/[id]` si l'œuvre est identifiée comme un film.
 * - Affiche le hero cinématographique (`WorkDetailHero`) avec bouton « Lire S.1 Ép.1 ».
 * - Délègue aux onglets (`WorkDetailTabs`) le sélecteur de saison, la grille
 *   d'épisodes, le contenu associé et les détails.
 * - Série retirée de la plateforme (API 410) → écran « contenu retiré » ;
 *   série inconnue (API 404) → écran « page introuvable ».
 *
 * @returns La vue détaillée de la série.
 */
export default function SerieDetailPage() {
  const params = useParams();
  const slug = params.id as string;

  const [work, setWork] = useState<DiscoverWork | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [withdrawn, setWithdrawn] = useState<WithdrawnInfo | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    setNotFound(false);
    setWithdrawn(null);
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
        const gone = withdrawnInfoFrom(err, "serie");
        if (gone) {
          setWithdrawn(gone);
          return;
        }
        if ((err as { statusCode?: number })?.statusCode === 404) {
          setNotFound(true);
          return;
        }
        setError((err as { message?: string })?.message ?? "Erreur inconnue");
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

  if (withdrawn) {
    return <ContentWithdrawnView {...withdrawn} />;
  }

  if (notFound) {
    return (
      <NotFoundView
        title="Cette série est introuvable"
        message="Aucune série ne correspond à cette adresse sur CINAF. Le lien est peut-être incomplet, ou la série a changé d'adresse."
        backHref="/series"
        backLabel="Voir toutes les séries"
      />
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

  const firstSeason = work.seasons[0];
  const firstEpisode = firstSeason?.episodes[0];
  const hrefFor = (seasonSlug: string, epSlug: string) =>
    `/watch/episode/${slug}?ep=${encodeURIComponent(epSlug)}&s=${encodeURIComponent(seasonSlug)}`;

  // Libellé façon cinaf.tv : « Lire S.1 Ép.1 » (numéros réels si connus).
  const playLabel = firstEpisode
    ? `Lire S.${firstSeasonNumber(firstSeason.name)} Ép.${firstEpisode.number ?? 1}`
    : "Regarder";

  return (
    <div>
      <WorkDetailHero
        work={work}
        backHref="/series"
        backLabel="Retour aux séries"
        playHref={firstEpisode && firstSeason ? hrefFor(firstSeason.slug, firstEpisode.slug) : null}
        playLabel={playLabel}
      />
      <WorkDetailTabs work={work} hrefFor={(seasonSlug, ep) => hrefFor(seasonSlug, ep.slug)} />
    </div>
  );
}

/** Extrait le numéro de saison d'un nom (« SAISON_2 », « Saison 2 », « S3 »), 1 par défaut. */
function firstSeasonNumber(name: string): number {
  const m = name.match(/(\d+)/);
  return m ? Number(m[1]) : 1;
}
