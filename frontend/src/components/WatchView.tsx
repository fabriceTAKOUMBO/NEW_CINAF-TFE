"use client";

// ============================================================
// CINAF v2 — Vue de lecture mutualisée pour /watch/film et /watch/episode.
// Charge le détail depuis le catalogue Bunny et instancie HlsPlayer.
// ============================================================

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

interface WatchViewProps {
  /** Slug de l'œuvre (Bunny). */
  slug: string;
  /** Type attendu : "film" ou "episode". Sert au libellé "Retour" et navigation suivante. */
  context: "film" | "episode";
}

export default function WatchView({ slug, context }: WatchViewProps) {
  const router = useRouter();
  const search = useSearchParams();
  const { isAuthenticated, isLoading: authLoading } = useAuth();

  const seasonSlug = search.get("s") || "";
  const episodeSlug = search.get("ep") || "";

  const [work, setWork] = useState<DiscoverWork | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  /** null = check en cours, true/false = résultat du backend /can-play. */
  const [canPlay, setCanPlay] = useState<boolean | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.push(
        `/login?next=${encodeURIComponent(
          `/watch/${context}/${slug}?ep=${episodeSlug}&s=${seasonSlug}`,
        )}`,
      );
    }
  }, [authLoading, isAuthenticated, router, slug, context, episodeSlug, seasonSlug]);

  useEffect(() => {
    if (!isAuthenticated) return;
    let cancelled = false;
    setLoading(true);
    setCanPlay(null);

    // 1. Récupère l'œuvre. 2. Vérifie l'abo via le backend (source de vérité,
    //    évite le user du context qui peut être figé après assignation d'abo
    //    par l'admin sans relogin).
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
        const status = (err as { statusCode?: number })?.statusCode;
        const msg = (err as { message?: string })?.message ?? "Erreur inconnue";
        setError(status === 404 ? "Œuvre introuvable." : msg);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [slug, isAuthenticated]);

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

  if (authLoading || !isAuthenticated || loading) {
    return (
      <div className="watch-page d-flex align-items-center justify-content-center" style={{ minHeight: "60vh" }}>
        <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} role="status" />
      </div>
    );
  }

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

  // Restriction : seuls les abonnés actifs peuvent lire. Le catalogue reste public.
  // On se base sur le résultat du backend /can-play (source de vérité), pas sur
  // user.hasActiveSubscription du context React qui peut être figé.
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

      <div className="watch-layout">
        <div className="watch-player-col watch-player-col-full">
          <HlsPlayer src={episode.hlsUrl} fallbackMp4={episode.mp4Url} autoplay />

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
