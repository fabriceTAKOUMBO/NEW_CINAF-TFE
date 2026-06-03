// ============================================================
// CINAF v2 — Grille responsive de StudioCard
// ============================================================

import type { StudioPublic } from "@/lib/api";
import StudioCard from "./StudioCard";

interface StudiosGridProps {
  studios: StudioPublic[];
  /** Affiche un spinner doré centré à la place de la grille si `true`. */
  loading?: boolean;
}

/**
 * Grille responsive de studios publics :
 *   - 2 colonnes sur mobile (xs)
 *   - 3 colonnes sur tablette (md)
 *   - 4 colonnes sur desktop (lg+)
 *
 * Si la liste est vide ET que `loading=false`, le composant ne rend rien :
 * la décision d'afficher un empty state appartient à la page parente.
 */
export default function StudiosGrid({ studios, loading = false }: StudiosGridProps) {
  if (loading) {
    return (
      <div className="text-center py-5">
        <div
          className="spinner-border"
          style={{ color: "var(--cinaf-gold)" }}
          role="status"
        >
          <span className="visually-hidden">Chargement des studios…</span>
        </div>
      </div>
    );
  }

  if (studios.length === 0) return null;

  return (
    <div className="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3">
      {studios.map((s) => (
        <div key={s.id} className="col">
          <StudioCard studio={s} />
        </div>
      ))}
    </div>
  );
}
