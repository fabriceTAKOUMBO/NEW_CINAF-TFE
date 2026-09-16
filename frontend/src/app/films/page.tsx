"use client";

// ============================================================
// CINAF v2 — Page Films (catalogue Bunny filtré sur kind=film)
// ============================================================

import { Suspense } from "react";
import WorksListView from "@/components/WorksListView";

/**
 * Page publique listant spécifiquement les longs métrages et films du catalogue CINAF.
 * Délègue l'affichage et la pagination à `WorksListView` avec le filtre `kind="film"`.
 * 
 * @returns La page de navigation des films.
 */
export default function FilmsPage() {
  return (
    <Suspense
      fallback={
        <div className="d-flex justify-content-center py-5">
          <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} />
        </div>
      }
    >
      <WorksListView
        title="Films"
        icon="bi-film"
        kind="film"
        basePath="/films"
        searchPlaceholder="Rechercher un film…"
      />
    </Suspense>
  );
}
