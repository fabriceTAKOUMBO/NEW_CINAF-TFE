"use client";

// ============================================================
// CINAF v2 — Page Catalogue (vue unifiée Films + Séries)
// Onglets Tous / Films / Séries (filtre via ?kind=)
// ============================================================

import { Suspense } from "react";
import WorksListView from "@/components/WorksListView";

/**
 * Page catalogue unifiée de CINAF.
 * 
 * Rôle :
 * - Présente l'ensemble des films et séries avec bascule par onglets (`showKindTabs`).
 * - Gère la pagination et la recherche plein texte via le composant réutilisable `WorksListView`.
 * 
 * @returns La vue catalogue complète.
 */
export default function CataloguePage() {
  return (
    <Suspense
      fallback={
        <div className="d-flex justify-content-center py-5">
          <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} />
        </div>
      }
    >
      <WorksListView
        title="Catalogue"
        icon="bi-grid-3x3-gap-fill"
        kind={null}
        showKindTabs
        basePath="/catalogue"
        searchPlaceholder="Rechercher un film ou une série…"
      />
    </Suspense>
  );
}
