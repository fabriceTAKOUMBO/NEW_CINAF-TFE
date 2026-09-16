"use client";

/**
 * ============================================================
 * CINAF v2 — Carte d'œuvre pour le catalogue Bunny (WorkCard)
 * ============================================================
 * Représente un film ou une série dans les grilles et carrousels de découverte.
 * 
 * Conception :
 * - Intègre le composant `Poster` en format portrait avec ratio d'aspect cinématographique.
 * - Arbore une pastille supérieure droite indiquant le type : "Film" (doré) ou "Série" (bleu ciel).
 * - Titre de l'œuvre nettoyé (remplacement des underscores issus du nom de dossier Bunny).
 * - Fonction utilitaire exportée `routeFor(work)` calculant l'URL cible canonique.
 */

import Link from "next/link";
import type { DiscoverWorkSummary } from "@/lib/api";
import Poster from "./Poster";

/**
 * Propriétés attendues par le composant `WorkCard`.
 */
interface WorkCardProps {
  /** Résumé des informations de l'œuvre */
  work: DiscoverWorkSummary;
  /** Permet de surcharger l'URL de redirection par défaut */
  hrefOverride?: string;
}

/**
 * Carte interactive représentant une œuvre du catalogue.
 * 
 * @param props - Propriétés du composant
 * @returns La carte cliquable avec affiche et badge de format
 */
export default function WorkCard({ work, hrefOverride }: WorkCardProps) {
  const href = hrefOverride ?? routeFor(work);

  return (
    <Link href={href} className="d-block text-decoration-none" style={{ color: "inherit" }}>
      <div style={{ position: "relative" }}>
        <Poster title={work.title} src={work.poster} ratio="portrait" />
        <span
          style={{
            position: "absolute",
            top: 6,
            right: 6,
            background: "rgba(0,0,0,0.7)",
            color: work.kind === "serie" ? "#8ad" : "var(--cinaf-gold)",
            fontSize: "0.65rem",
            padding: "2px 8px",
            borderRadius: 12,
            fontWeight: 700,
            textTransform: "uppercase",
            letterSpacing: "0.05em",
          }}
        >
          {work.kind === "serie" ? "Série" : "Film"}
        </span>
      </div>
      <p
        className="mt-2 mb-0 small text-truncate"
        style={{ color: "var(--cinaf-text)" }}
        title={work.title}
      >
        {work.title.replace(/_/g, " ")}
      </p>
    </Link>
  );
}

/**
 * Détermine la route de consultation détaillée selon le type d'œuvre.
 * 
 * @param work - Le résumé de l'œuvre
 * @returns Le chemin d'accès relatif (ex: "/films/mon-film" ou "/series/ma-serie")
 */
export function routeFor(work: DiscoverWorkSummary): string {
  return work.kind === "serie" ? `/series/${work.slug}` : `/films/${work.slug}`;
}
