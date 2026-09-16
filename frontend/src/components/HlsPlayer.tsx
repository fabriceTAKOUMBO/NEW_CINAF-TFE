"use client";

/**
 * ============================================================
 * CINAF v2 — Lecteur HLS (hls.js avec fallback Safari natif et MP4)
 * ============================================================
 * Ce composant gère la lecture des flux vidéos encodés en HTTP Live Streaming (HLS)
 * avec une stratégie de résilience multi-niveaux :
 * 
 * Stratégie de compatibilité & fallback :
 * 1. Détection Safari / iOS : Utilisation du décodeur HLS natif Apple (`application/vnd.apple.mpegurl`).
 * 2. Détection Chrome / Firefox / Edge : Initialisation de la librairie JavaScript `hls.js`.
 * 3. En cas d'erreur fatale réseau (ex: playlist HLS non encore générée par l'encodeur CDN) :
 *    Bascule transparente et instantanée sur le fichier vidéo MP4 de secours (`fallbackMp4`).
 * 4. Gestion des sources vides ou invalides pour éviter le gel du lecteur à 0:00.
 */

import { useEffect, useRef, useState } from "react";
import Hls, { ErrorTypes } from "hls.js";

/**
 * Propriétés attendues par le composant `HlsPlayer`.
 */
interface HlsPlayerProps {
  /** URL du manifeste master.m3u8 (HLS à débit adaptatif) */
  src?: string | null;
  /** URL directe d'un fichier MP4 brut, utilisée si le flux HLS est indisponible ou en erreur */
  fallbackMp4?: string | null;
  /** Lecture automatique au chargement */
  autoplay?: boolean;
  /** Image d'affiche (poster) affichée avant le déclenchement de la lecture */
  poster?: string;
}

/**
 * Lecteur vidéo HLS résilient avec bascule automatique.
 * 
 * @param props - Propriétés du lecteur
 * @returns Le lecteur vidéo HTML5 avec gestionnaire d'erreurs et indicateur visuel de fallback
 */
export default function HlsPlayer({
  src,
  fallbackMp4,
  autoplay = false,
  poster,
}: HlsPlayerProps) {
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [usingMp4, setUsingMp4] = useState(false);

  useEffect(() => {
    const video = videoRef.current;
    if (!video) return;

    setError(null);
    setUsingMp4(false);

    // Normalisation des URLs (retrait des espaces superflus) pour éviter les src vides qui bloquent les contrôles natifs
    const trimmedSrc = typeof src === "string" ? src.trim() : "";
    const trimmedMp4 = typeof fallbackMp4 === "string" ? fallbackMp4.trim() : "";

    // Cas 1 : Aucune source HLS fournie mais un fichier MP4 est présent → bascule immédiate en MP4
    if (!trimmedSrc && trimmedMp4) {
      video.src = trimmedMp4;
      setUsingMp4(true);
      return;
    }

    // Cas 2 : Aucune source vidéo exploitable du tout
    if (!trimmedSrc) {
      setError("Aucune source vidéo prête pour cet épisode.");
      return;
    }

    // Cas 3 : Navigateurs Apple (Safari / iOS) disposant d'un support natif HLS
    if (video.canPlayType("application/vnd.apple.mpegurl")) {
      video.src = trimmedSrc;
      return;
    }

    // Cas 4 : Navigateurs modernes supportant MediaSource Extensions (MSE) via hls.js
    if (Hls.isSupported()) {
      const hls = new Hls({ lowLatencyMode: false });
      hls.loadSource(trimmedSrc);
      hls.attachMedia(video);

      hls.on(Hls.Events.ERROR, (_event, data) => {
        if (!data.fatal) return;
        // En cas d'erreur fatale réseau (ex: 404 sur master.m3u8), bascule automatique sur le MP4 de secours
        if (data.type === ErrorTypes.NETWORK_ERROR && trimmedMp4) {
          hls.destroy();
          video.src = trimmedMp4;
          setUsingMp4(true);
          if (autoplay) video.play().catch(() => {});
        } else {
          setError(`Erreur de lecture HLS : ${data.details}`);
        }
      });

      return () => {
        hls.destroy();
      };
    }

    // Cas 5 : Aucun support HLS mais fichier MP4 présent
    if (trimmedMp4) {
      video.src = trimmedMp4;
      setUsingMp4(true);
    } else {
      setError("Votre navigateur ne supporte pas la lecture HLS.");
    }
  }, [src, fallbackMp4, autoplay]);

  return (
    <div
      style={{
        position: "relative",
        width: "100%",
        paddingTop: "56.25%",
        backgroundColor: "#000",
        borderRadius: 8,
        overflow: "hidden",
      }}
    >
      <video
        ref={videoRef}
        controls
        autoPlay={autoplay}
        playsInline
        poster={poster}
        style={{
          position: "absolute",
          inset: 0,
          width: "100%",
          height: "100%",
          background: "#000",
        }}
      />
      {error && (
        <div
          style={{
            position: "absolute",
            inset: 0,
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            background: "rgba(0,0,0,0.85)",
            color: "#fff",
            padding: "1rem",
            textAlign: "center",
          }}
        >
          <div>
            <i
              className="bi bi-exclamation-triangle d-block mb-2"
              style={{ fontSize: "2rem", color: "var(--cinaf-gold)" }}
            />
            {error}
          </div>
        </div>
      )}
      {usingMp4 && !error && (
        <div
          style={{
            position: "absolute",
            top: 8,
            right: 8,
            background: "rgba(0,0,0,0.6)",
            color: "var(--cinaf-gold)",
            padding: "2px 8px",
            borderRadius: 4,
            fontSize: "0.75rem",
          }}
        >
          MP4
        </div>
      )}
    </div>
  );
}
