"use client";

/**
 * ============================================================
 * CINAF v2 — Composant Affiche (Poster)
 * ============================================================
 * Affiche l'image de couverture d'un contenu avec mécanisme de bascule robuste :
 * - Charge l'image depuis l'URL CDN spécifiée (`src`).
 * - En cas d'URL absente ou d'erreur réseau/404 (`onError`), bascule instantanément
 *   sur une affiche stylisée de substitution (`PlaceholderPoster`).
 * - Supporte trois ratios d'aspect standards :
 *   - "portrait" : 2/3 (standard affiches cinéma).
 *   - "square" : 1/1 (logos de studios et carrés).
 *   - "wide" : 16/9 (vignettes d'épisodes et bannières horizontales).
 */

import { useState } from "react";
import PlaceholderPoster from "./PlaceholderPoster";

/**
 * Propriétés attendues par le composant `Poster`.
 */
interface PosterProps {
  /** Titre de l'œuvre (utilisé pour le texte alternatif et les initiales du placeholder) */
  title: string;
  /** URL CDN de l'affiche, ou null/undefined si inexistante */
  src?: string | null;
  /** Format d'affichage souhaité ("portrait" par défaut, "square" ou "wide") */
  ratio?: "portrait" | "square" | "wide";
}

/**
 * Affiche de film/série résiliente avec fallback automatique.
 * 
 * @param props - Propriétés du composant
 * @returns La balise `<img>` chargée ou le composant `PlaceholderPoster`
 */
export default function Poster({ title, src, ratio = "portrait" }: PosterProps) {
  const [failed, setFailed] = useState(false);

  // Si l'URL n'est pas fournie ou que le chargement a échoué, afficher le placeholder généré
  if (!src || failed) {
    return <PlaceholderPoster title={title} ratio={ratio} />;
  }

  // Calcul du ratio CSS correspondant
  const aspect = ratio === "wide" ? "16 / 9" : ratio === "square" ? "1 / 1" : "2 / 3";

  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={src}
      alt={title.replace(/_/g, " ")}
      loading="lazy"
      onError={() => setFailed(true)}
      style={{
        aspectRatio: aspect,
        width: "100%",
        objectFit: "cover",
        borderRadius: 8,
        border: "1px solid var(--cinaf-border)",
        display: "block",
        background: "var(--cinaf-surface)",
      }}
    />
  );
}
