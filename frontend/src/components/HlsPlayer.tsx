"use client";

// ============================================================
// CINAF v2 — Lecteur HLS (hls.js avec fallback Safari natif et MP4)
// ============================================================

import { useEffect, useRef, useState } from "react";
import Hls, { ErrorTypes } from "hls.js";

interface HlsPlayerProps {
  /** URL du master.m3u8 (HLS adaptatif). */
  src?: string | null;
  /** URL d'un MP4 brut, utilisée si HLS échoue ou n'est pas disponible. */
  fallbackMp4?: string | null;
  autoplay?: boolean;
  poster?: string;
}

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

    // Défense en profondeur : `src` peut être une chaîne vide renvoyée par
    // un backend dont la résolution Bunny a échoué. On normalise via trim()
    // pour traiter "" et "   " comme absent et éviter `<video src="">` qui
    // affiche les contrôles natifs avec timeline figée à 0:00.
    const trimmedSrc = typeof src === "string" ? src.trim() : "";
    const trimmedMp4 = typeof fallbackMp4 === "string" ? fallbackMp4.trim() : "";

    // Aucune source HLS exploitable → MP4 direct si dispo
    if (!trimmedSrc && trimmedMp4) {
      video.src = trimmedMp4;
      setUsingMp4(true);
      return;
    }

    if (!trimmedSrc) {
      setError("Aucune source vidéo prête pour cet épisode.");
      return;
    }

    // Safari/iOS : lecteur HLS natif
    if (video.canPlayType("application/vnd.apple.mpegurl")) {
      video.src = trimmedSrc;
      return;
    }

    // Chrome/Firefox : hls.js
    if (Hls.isSupported()) {
      const hls = new Hls({ lowLatencyMode: false });
      hls.loadSource(trimmedSrc);
      hls.attachMedia(video);

      hls.on(Hls.Events.ERROR, (_event, data) => {
        if (!data.fatal) return;
        // Erreurs réseau (ex: 404 sur master.m3u8) → bascule MP4 si dispo
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

    // Pas de support HLS du tout → MP4 fallback ou erreur
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
