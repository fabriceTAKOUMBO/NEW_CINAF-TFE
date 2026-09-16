"use client";

// ============================================================
// CINAF v2 — Page Watch Film (lecteur HLS, alimenté par Bunny)
// ============================================================

import { Suspense } from "react";
import { useParams } from "next/navigation";
import WatchView from "@/components/WatchView";

/**
 * Page de lecture vidéo d'un film, enveloppée dans Suspense pour gérer les paramètres d'URL (`?ep=&s=`).
 */
export default function WatchFilmPage() {
  return (
    <Suspense
      fallback={
        <div className="watch-page d-flex align-items-center justify-content-center" style={{ minHeight: "60vh" }}>
          <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} />
        </div>
      }
    >
      <WatchFilmContent />
    </Suspense>
  );
}

/**
 * Composant de lecture pour un film, intégrant le lecteur HLS sécurisé `WatchView`.
 */
function WatchFilmContent() {
  const params = useParams();
  const slug = params.id as string;
  return <WatchView slug={slug} context="film" />;
}
