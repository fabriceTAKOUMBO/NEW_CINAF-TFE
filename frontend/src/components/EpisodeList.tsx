/**
 * ============================================================
 * CINAF v2 — Liste soignée d'épisodes (EpisodeList)
 * ============================================================
 * Composant de présentation détaillée des épisodes pour les pages de séries ou
 * films divisés en plusieurs parties (façon cinaf.tv).
 * 
 * Éléments affichés pour chaque épisode :
 * - Pastille dorée avec numéro d'épisode (`startNumber + idx`).
 * - Miniature grand angle (`PlaceholderPoster` au ratio 16:9).
 * - Titre de l'épisode avec icône de lecture.
 * - Bouton d'action directe "Regarder" renvoyant vers le lecteur via `hrefFor(ep)`.
 * - Défilement vertical contraint (`maxHeight: 640px`) au-delà de 8 épisodes pour
 *   garder la page fluide et ergonomique.
 */

import Link from "next/link";
import type { DiscoverEpisode } from "@/lib/api";
import PlaceholderPoster from "./PlaceholderPoster";

/**
 * Propriétés attendues par le composant `EpisodeList`.
 */
interface EpisodeListProps {
  /** Liste ordonnée des épisodes de la saison */
  episodes: DiscoverEpisode[];
  /** Fonction construisant l'URL de lecture pour l'épisode donné */
  hrefFor: (ep: DiscoverEpisode) => string;
  /** Index de départ pour la numérotation visuelle (défaut: 1) */
  startNumber?: number;
}

/**
 * Liste verticale interactive d'épisodes de série.
 * 
 * @param props - Propriétés du composant
 * @returns La liste d'épisodes ou un message informatif si vide
 */
export default function EpisodeList({
  episodes,
  hrefFor,
  startNumber = 1,
}: EpisodeListProps) {
  if (episodes.length === 0) {
    return (
      <p style={{ color: "var(--cinaf-text-muted)" }}>Aucun épisode disponible.</p>
    );
  }

  // Au-delà de 8 épisodes, on borne la hauteur pour éviter un défilement infini de page
  const scroll = episodes.length > 8;

  return (
    <div
      className="d-flex flex-column gap-2"
      style={
        scroll ? { maxHeight: 640, overflowY: "auto", paddingRight: 4 } : undefined
      }
    >
      {episodes.map((ep, idx) => (
        <div
          key={ep.slug}
          className="d-flex align-items-center gap-3 p-2"
          style={{
            background: "var(--cinaf-surface)",
            border: "1px solid var(--cinaf-border)",
            borderRadius: 8,
          }}
        >
          {/* Numéro de l'épisode dans la saison */}
          <span
            className="d-inline-flex align-items-center justify-content-center flex-shrink-0"
            style={{
              width: 36,
              height: 36,
              borderRadius: 8,
              background: "var(--cinaf-gold-light)",
              color: "var(--cinaf-gold)",
              fontWeight: 700,
            }}
          >
            {startNumber + idx}
          </span>

          {/* Miniature large 16:9 masquée sur mobile très étroit */}
          <div className="d-none d-sm-block flex-shrink-0" style={{ width: 120 }}>
            <PlaceholderPoster title={ep.name.replace(/_/g, " ")} ratio="wide" />
          </div>

          {/* Titre nettoyé de l'épisode */}
          <span
            className="flex-grow-1 text-truncate"
            style={{ color: "var(--cinaf-text)", fontWeight: 500 }}
            title={ep.name.replace(/_/g, " ")}
          >
            <i className="bi bi-play-circle me-2" style={{ color: "var(--cinaf-gold)" }} />
            {ep.name.replace(/_/g, " ")}
          </span>

          {/* Bouton de lancement de la lecture */}
          <Link
            href={hrefFor(ep)}
            className="btn btn-sm btn-cinaf-outline flex-shrink-0"
          >
            <i className="bi bi-play-fill me-1" />
            Regarder
          </Link>
        </div>
      ))}
    </div>
  );
}
