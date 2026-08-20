// ============================================================
// CINAF v2 — Liste d'épisodes soignée (pages détail film/série).
// Inspiré de cinaf.tv : miniature large + numéro + titre + « Regarder ».
// Réutilisée pour un film multi-parties et pour chaque saison de série.
// ============================================================

import Link from "next/link";
import type { DiscoverEpisode } from "@/lib/api";
import PlaceholderPoster from "./PlaceholderPoster";

interface EpisodeListProps {
  episodes: DiscoverEpisode[];
  /** Construit l'URL de lecture pour un épisode donné (capture la saison). */
  hrefFor: (ep: DiscoverEpisode) => string;
  /** Numéro du premier épisode affiché (défaut 1). */
  startNumber?: number;
}

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

  // Au-delà de 8 épisodes, on borne la hauteur pour éviter une page interminable
  // (ex. œuvres ~90 épisodes mal classées en film).
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
          {/* Numéro d'épisode */}
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

          {/* Miniature large (placeholder), masquée sur très petit écran */}
          <div className="d-none d-sm-block flex-shrink-0" style={{ width: 120 }}>
            <PlaceholderPoster title={ep.name.replace(/_/g, " ")} ratio="wide" />
          </div>

          {/* Titre de l'épisode */}
          <span
            className="flex-grow-1 text-truncate"
            style={{ color: "var(--cinaf-text)", fontWeight: 500 }}
            title={ep.name.replace(/_/g, " ")}
          >
            <i className="bi bi-play-circle me-2" style={{ color: "var(--cinaf-gold)" }} />
            {ep.name.replace(/_/g, " ")}
          </span>

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
