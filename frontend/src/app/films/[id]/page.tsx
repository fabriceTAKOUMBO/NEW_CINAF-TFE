"use client";

// ============================================================
// CINAF v2 — Détail Film (alimenté par catalogue Bunny)
// Design calqué sur la page titre de cinaf.tv : hero plein cadre
// (WorkDetailHero) puis onglets Épisodes / Contenu associé / Détails
// (WorkDetailTabs). Le studio éditeur reste visible sur la fiche.
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
 * Fiche détaillée d'un film.
 * 
 * Fonctionnalités :
 * - Charge les métadonnées de l'œuvre via son slug depuis l'API `discover.get`.
 * - Redirige vers `/series/[id]` si l'œuvre est identifiée comme une série.
 * - Restitue le hero cinématographique (`WorkDetailHero`) avec bouton direct "Regarder".
 * - Affiche les onglets de la fiche (`WorkDetailTabs`) : parties du film si
 *   l'œuvre est découpée, contenu associé, détails.
 * - Film retiré de la plateforme (API 410) → écran « contenu retiré » ;
 *   film inconnu (API 404) → écran « page introuvable ».
 *
 * @returns La vue détaillée du film.
 */
export default function FilmDetailPage() {
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
        if (w.kind === "serie") {
          setError("redirect-serie");
        } else {
          setWork(w);
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const gone = withdrawnInfoFrom(err, "film");
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

  if (withdrawn) {
    return <ContentWithdrawnView {...withdrawn} />;
  }

  if (notFound) {
    return (
      <NotFoundView
        title="Ce film est introuvable"
        message="Aucun film ne correspond à cette adresse sur CINAF. Le lien est peut-être incomplet, ou le film a changé d'adresse."
        backHref="/films"
        backLabel="Voir tous les films"
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
          {error ?? "Film introuvable."}
        </h3>
        <Link href="/films" className="btn btn-cinaf-outline mt-3">
          <i className="bi bi-arrow-left me-1" />
          Retour aux films
        </Link>
      </div>
    );
  }

  const firstSeason = work.seasons[0];
  const firstEpisode = firstSeason?.episodes[0];
  const hrefFor = (seasonSlug: string, epSlug: string) =>
    `/watch/film/${slug}?ep=${encodeURIComponent(epSlug)}&s=${encodeURIComponent(seasonSlug)}`;

  return (
    <div>
      <WorkDetailHero
        work={work}
        backHref="/films"
        backLabel="Retour aux films"
        playHref={firstEpisode && firstSeason ? hrefFor(firstSeason.slug, firstEpisode.slug) : null}
        playLabel="Regarder"
      />
      <WorkDetailTabs work={work} hrefFor={(seasonSlug, ep) => hrefFor(seasonSlug, ep.slug)} />
    </div>
  );
}
