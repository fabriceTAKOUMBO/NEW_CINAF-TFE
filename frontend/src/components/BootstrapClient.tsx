"use client";

// ============================================================
// CINAF v2 — Chargement de Bootstrap (Côté Client)
// ============================================================

import { useEffect } from "react";

export default function BootstrapClient() {
  useEffect(() => {
    // Importation dynamique du bundle JS de Bootstrap pour activer les composants interactifs
    import("bootstrap/dist/js/bootstrap.bundle.min.js");
  }, []);

  return null;
}
