"use client";

/**
 * ============================================================
 * CINAF v2 — Vue de lecture vidéo mutualisée (WatchView)
 * ============================================================
 * Composant de visionnage principal partagé par les routes de lecture :
 * - `/watch/film/[id]` : lecture de long-métrage
 * - `/watch/episode/[id]` : lecture d'un épisode de série
 * 
 * Logique de contrôle d'accès et de navigation :
 * 1. Authentification obligatoire : Redirige vers `/login?next=...` si l'utilisateur n'est pas connecté.
 * 2. Vérification d'abonnement en temps réel :
 *    Interroge l'endpoint backend `/catalogue/discover/{slug}/can-play` comme source unique de vérité,
 *    évitant les états obsolètes de contexte utilisateur (ex: abonnement attribué manuellement par admin).
 * 3. Paywall d'abonnement : Si `canPlay === false`, affiche un écran de blocage élégant incitant
 *    à souscrire à un plan CINAF tout en offrant le retour à la fiche descriptive.
 * 4. Détermination de l'épisode et de la saison courante : Gère les paramètres d'URL (`?s=` et `?ep=`)
 *    et propose un bouton d'enchaînement automatique vers l'épisode suivant (`nextEpisode`).
 * 5. Intégration du lecteur adaptatif `HlsPlayer` avec gestion du fallback MP4 progressif.
 * 6. Œuvre retirée de la plateforme (API 410) → écran `ContentWithdrawnView` ;
 *    œuvre inconnue (API 404) → écran `NotFoundView`.
 */

import { useEffect, useMemo, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
import {
  discover,
  subscriptions,
  type DiscoverEpisode,
  type DiscoverSeason,
  type DiscoverWork,
} from "@/lib/api";
import { useAuth } from "@/lib/auth";
import HlsPlayer from "./HlsPlayer";
import NotFoundView from "./NotFoundView";
import ContentWithdrawnView, { withdrawnInfoFrom, type WithdrawnInfo } from "./ContentWithdrawnView";

/**
 * Propriétés attendues par le composant `WatchView`.
 */
interface WatchViewProps {
  /** Slug unique de l'œuvre sur le catalogue Bunny/DB */
  slug: string;
  /** Contexte de visionnage : 'film' pour un film unique, 'episode' pour une série TV */
  context: "film" | "episode";
}

/**
 * Page de lecture vidéo complète avec contrôle d'accès abonné et navigation d'épisodes.
 * 
 * @param props - Propriétés du composant (`slug`, `context`)
 * @returns L'interface complète de visionnage ou l'écran de restriction d'abonnement
 */
export default function WatchView({ slug, context }: WatchViewProps) {
  const router = useRouter();
  const search = useSearchParams();
  const { isAuthenticated, isLoading: authLoading } = useAuth();

  // Paramètres d'URL pour identifier la saison et l'épisode cible dans une série
  const seasonSlug = search.get("s") || "";
  const episodeSlug = search.get("ep") || "";

  const [work, setWork] = useState<DiscoverWork | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  /** Œuvre inconnue (API 404) → écran « page introuvable » */
  const [notFound, setNotFound] = useState(false);
  /** Œuvre retirée de la plateforme (API 410) → écran « contenu retiré » */
  const [withdrawn, setWithdrawn] = useState<WithdrawnInfo | null>(null);
  /** null = vérification en cours, true/false = résultat du backend /can-play */
  const [canPlay, setCanPlay] = useState<boolean | null>(null);

  // Redirection automatique vers /login si non authentifié une fois l'état d'auth chargé
  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.push(
        `/login?next=${encodeURIComponent(
          `/watch/${context}/${slug}?ep=${episodeSlug}&s=${seasonSlug}`,
        )}`,
      );
    }
  }, [authLoading, isAuthenticated, router, slug, context, episodeSlug, seasonSlug]);

  // Chargement des données de l'œuvre et vérification des droits de lecture
  useEffect(() => {
    if (!isAuthenticated) return;
    let cancelled = false;
    setLoading(true);
    setCanPlay(null);
    setError(null);
    setNotFound(false);
    setWithdrawn(null);

    // 1. Récupère l'œuvre. 2. Vérifie l'abonnement via le backend (source de vérité,
    //    évite le user du context qui peut être figé après assignation d'abo par l'admin).
    Promise.all([
      discover.get(slug),
      subscriptions.canPlay(slug).catch(() => false),
    ])
      .then(([w, allowed]) => {
        if (cancelled) return;
        setWork(w);
        setCanPlay(allowed);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        // Seule la fiche peut échouer ici (les erreurs de can-play sont
        // absorbées ci-dessus) : 410 = œuvre retirée, 404 = œuvre inconnue.
        const gone = withdrawnInfoFrom(err, context === "film" ? "film" : "serie");
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
  }, [slug, isAuthenticated, context]);

  // Calcul mémorisé de la saison active, de l'épisode sélectionné et du prochain épisode
  const { season, episode, nextEpisode } = useMemo(() => {
    if (!work) return { season: null, episode: null, nextEpisode: null };
    const s: DiscoverSeason | undefined =
      work.seasons.find((x) => x.slug === seasonSlug) || work.seasons[0];
    if (!s) return { season: null, episode: null, nextEpisode: null };
    const epIdx = s.episodes.findIndex((e) => e.slug === episodeSlug);
    const ep: DiscoverEpisode | null = epIdx >= 0 ? s.episodes[epIdx] : s.episodes[0] ?? null;
    const next: DiscoverEpisode | null =
      epIdx >= 0 && epIdx + 1 < s.episodes.length ? s.episodes[epIdx + 1] : null;
    return { season: s, episode: ep, nextEpisode: next };
  }, [work, seasonSlug, episodeSlug]);

  // Affichage de chargement pendant la vérification d'authentification ou d'accès
  if (authLoading || !isAuthenticated || loading) {
    return (
      <div className="watch-page d-flex align-items-center justify-content-center" style={{ minHeight: "60vh" }}>
        <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} role="status" />
      </div>
    );
  }

  // Œuvre retirée de la plateforme depuis que le lien a été partagé ou mis en favori.
  if (withdrawn) {
    return <ContentWithdrawnView {...withdrawn} />;
  }

  if (notFound) {
    const isFilm = context === "film";
    return (
      <NotFoundView
        title="Cette vidéo est introuvable"
        message="Aucune œuvre ne correspond à cette adresse sur CINAF. Le lien est peut-être incomplet ou erroné."
        backHref={isFilm ? "/films" : "/series"}
        backLabel={isFilm ? "Voir tous les films" : "Voir toutes les séries"}
      />
    );
  }

  // Écran d'erreur si l'œuvre ou l'épisode n'a pas pu être chargé
  if (error || !work || !episode) {
    const backRoute = context === "film" ? `/films/${slug}` : `/series/${slug}`;
    return (
      <div className="container py-5 text-center">
        <i
          className="bi bi-exclamation-triangle"
          style={{ fontSize: "3rem", color: "var(--cinaf-gold)" }}
        />
        <h3 className="mt-3" style={{ color: "var(--cinaf-text)" }}>
          {error ?? "Épisode introuvable."}
        </h3>
        <Link href={backRoute} className="btn btn-cinaf-outline mt-3">
          Retour à la fiche
        </Link>
      </div>
    );
  }

  // Écran de restriction Paywall : Seuls les abonnés avec souscription active peuvent visionner.
  if (canPlay === false) {
    const backRoute = work.kind === "serie" ? `/series/${slug}` : `/films/${slug}`;
    return (
      <div className="container py-5">
        <div className="text-center" style={{ maxWidth: 520, margin: "0 auto" }}>
          <i
            className="bi bi-lock-fill"
            style={{ fontSize: "4rem", color: "var(--cinaf-gold)" }}
          />
          <h2 className="mt-3" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
            Abonnement requis
          </h2>
          <p style={{ color: "var(--cinaf-text-muted)" }}>
            Pour regarder <strong>{work.title.replace(/_/g, " ")}</strong> et tout le catalogue
            CINAF, abonnez-vous dès maintenant.
          </p>
          <div className="d-flex gap-3 justify-content-center mt-4 flex-wrap">
            <Link href="/abonnement" className="btn btn-cinaf px-4">
              <i className="bi bi-gem me-1" />
              Voir les abonnements
            </Link>
            <Link href={backRoute} className="btn btn-cinaf-outline px-4">
              Retour à la fiche
            </Link>
          </div>
          <p className="mt-4 small" style={{ color: "var(--cinaf-text-muted)" }}>
            <i className="bi bi-info-circle me-1" />
            Vous pouvez explorer librement le catalogue, les fiches détaillées et les bandes-annonces sans abonnement.
          </p>
        </div>
      </div>
    );
  }

  const backRoute = work.kind === "serie" ? `/series/${slug}` : `/films/${slug}`;

  return (
    <div className="watch-page">
      {/* Barre supérieure avec bouton retour et rappel du titre de l'œuvre */}
      <div className="watch-topbar">
        <Link href={backRoute} className="watch-back">
          <i className="bi bi-arrow-left me-1" />
          Retour
        </Link>
        <span className="watch-title">
          {work.title.replace(/_/g, " ")}
          {season && season.slug !== "principale" && (
            <span style={{ color: "var(--cinaf-text-muted)", marginLeft: 8 }}>
              · {season.name.replace(/_/g, " ")}
            </span>
          )}
        </span>
      </div>

      {/* Zone du lecteur vidéo principal */}
      <div className="watch-layout">
        <div className="watch-player-col watch-player-col-full">
          <HlsPlayer src={episode.hlsUrl} fallbackMp4={episode.mp4Url} autoplay />

          {/* Métadonnées de l'épisode et bouton d'enchaînement vers l'épisode suivant */}
          <div className="watch-meta">
            <h4 className="watch-meta-title">{episode.name.replace(/_/g, " ")}</h4>
            {!episode.hlsUrl && episode.mp4Url && (
              <p className="watch-meta-line">
                <i className="bi bi-info-circle me-1" />
                Lecture en MP4 progressif (manifeste HLS non généré pour cet épisode).
              </p>
            )}
            {!episode.hlsUrl && !episode.mp4Url && (
              <p className="watch-meta-line" style={{ color: "#ff8a8a" }}>
                Aucune source vidéo disponible pour cet épisode.
              </p>
            )}

            {nextEpisode && (
              <div className="mt-3">
                <Link
                  href={`/watch/${context}/${slug}?ep=${encodeURIComponent(
                    nextEpisode.slug,
                  )}&s=${encodeURIComponent(season?.slug ?? "")}`}
                  className="btn btn-cinaf-outline"
                >
                  <i className="bi bi-skip-end me-1" />
                  Épisode suivant : {nextEpisode.name.replace(/_/g, " ")}
                </Link>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
