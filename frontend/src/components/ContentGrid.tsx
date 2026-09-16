/**
 * ============================================================
 * CINAF v2 — Grille responsive de cartes de contenus (ContentGrid)
 * ============================================================
 * Dispose une collection de films ou de séries dans une grille adaptative :
 * - 2 colonnes sur smartphone (col-6)
 * - 3 colonnes sur tablette (col-md-4)
 * - 4 colonnes sur grand écran (col-lg-3)
 * - Gère l'affichage d'un écran vide centré avec icône de pellicule si la liste est vide.
 */

import type { Film, Serie } from "@/lib/api";
import ContentCard from "./ContentCard";

/**
 * Propriétés attendues par le composant `ContentGrid`.
 */
interface ContentGridProps {
  /** Liste des œuvres (films ou séries) */
  items: (Film | Serie)[];
  /** Type de contenu à présenter */
  type: "film" | "serie";
}

/**
 * Grille adaptative Bootstrap présentant des cartes de contenu.
 * 
 * @param props - Propriétés du composant
 * @returns La grille de cartes ou l'indicateur vide
 */
export default function ContentGrid({ items, type }: ContentGridProps) {
  // Affichage d'un état vide si aucun contenu n'est trouvé
  if (items.length === 0) {
    return (
      <div className="text-center py-5">
        <i
          className="bi bi-camera-reels"
          style={{ fontSize: "3rem", color: "var(--cinaf-text-muted)" }}
        />
        <p className="mt-3" style={{ color: "var(--cinaf-text-muted)" }}>
          Aucun contenu disponible
        </p>
      </div>
    );
  }

  return (
    <div className="row g-3">
      {/* Génération dynamique des colonnes de la grille */}
      {items.map((item) => (
        <div key={item.id} className="col-6 col-md-4 col-lg-3">
          <ContentCard content={item} type={type} />
        </div>
      ))}
    </div>
  );
}
