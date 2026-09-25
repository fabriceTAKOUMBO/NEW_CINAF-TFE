/**
 * ============================================================
 * CINAF v2 — Page 404 globale (App Router)
 * ============================================================
 * Next.js rend ce fichier pour toute adresse qui ne correspond à aucune
 * route (réponse HTTP 404), à la place de sa page anglaise par défaut. Elle
 * s'affiche dans le layout racine : la navbar et le footer restent donc
 * disponibles pour poursuivre la visite.
 */

import type { Metadata } from "next";
import NotFoundView from "@/components/NotFoundView";

/** Titre de l'onglet : « Page introuvable | CINAF » (modèle du layout racine). */
export const metadata: Metadata = {
  title: "Page introuvable",
};

/**
 * Page introuvable de l'application.
 *
 * @returns L'écran 404 aux couleurs de CINAF
 */
export default function NotFound() {
  return <NotFoundView />;
}
