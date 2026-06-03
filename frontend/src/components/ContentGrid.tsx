// ============================================================
// CINAF v2 — Grille responsive de ContentCard
// ============================================================

import type { Film, Serie } from "@/lib/api";
import ContentCard from "./ContentCard";

interface ContentGridProps {
  items: (Film | Serie)[];
  type: "film" | "serie";
}

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
      {/* Itération sur les éléments pour générer les colonnes de la grille */}
      {items.map((item) => (
        <div key={item.id} className="col-6 col-md-4 col-lg-3">
          <ContentCard content={item} type={type} />
        </div>
      ))}
    </div>
  );
}
