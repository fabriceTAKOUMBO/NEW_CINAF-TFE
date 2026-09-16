"use client";

// ============================================================
// CINAF v2 — Page Séries (catalogue Bunny filtré sur kind=serie)
// ============================================================

import { Suspense } from "react";
import WorksListView from "@/components/WorksListView";

/**
 * Page publique listant spécifiquement les séries et feuilletons du catalogue CINAF.
 * Délègue l'affichage et la pagination à `WorksListView` avec le filtre `kind="serie"`.
 * 
 * @returns La page de navigation des séries.
 */
export default function SeriesPage() {
  return (
    <Suspense
      fallback={
        <div className="d-flex justify-content-center py-5">
          <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} />
        </div>
      }
    >
      <WorksListView
        title="Séries"
        icon="bi-collection-play"
        kind="serie"
        basePath="/series"
        searchPlaceholder="Rechercher une série…"
      />
    </Suspense>
  );
}
