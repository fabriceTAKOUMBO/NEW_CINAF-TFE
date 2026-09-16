"use client";

/**
 * ============================================================
 * CINAF v2 — Chargement de Bootstrap côté client (BootstrapClient)
 * ============================================================
 * Ce composant utilitaire s'exécute exclusivement dans le navigateur (client-side).
 * 
 * Rôle & Fonctionnement :
 * - Effectue l'import dynamique asynchrone du bundle JavaScript pré-compilé de Bootstrap (`bootstrap.bundle.min.js`).
 * - Initialise le sous-système Popper.js et active les fonctionnalités interactives
 *   natives de Bootstrap : menus déroulants (dropdowns), volets repliables (collapse/accordions),
 *   barres de navigation responsives (navbar toggler), infobulles (tooltips) et modales.
 * - Ne génère aucun élément DOM (`return null`).
 */

import { useEffect } from "react";

/**
 * Initialiseur client Bootstrap.
 * 
 * @returns `null` car le composant n'a pas de représentation visuelle propre
 */
export default function BootstrapClient() {
  useEffect(() => {
    // Import dynamique du bundle JS complet (incluant Popper) au montage initial
    import("bootstrap/dist/js/bootstrap.bundle.min.js");
  }, []);

  return null;
}
