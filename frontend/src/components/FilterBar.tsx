"use client";

/**
 * ============================================================
 * CINAF v2 — Barre de filtres multi-critères (FilterBar)
 * ============================================================
 * Composant de filtrage rapide du catalogue permettant à l'utilisateur de combiner :
 * - Le type de contenu : Tous, Films, ou Séries.
 * - Le genre cinématographique : Pastilles horizontales défilables (Action, Drame, etc.).
 * - L'année de sortie : Menu déroulant généré de l'année actuelle jusqu'à 1960.
 * - Support de la désélection : Cliquer sur un genre actif le désactive immédiatement.
 */

import type { Genre } from "@/lib/api";

/**
 * Propriétés attendues par le composant `FilterBar`.
 */
interface FilterBarProps {
  /** Liste complète des genres disponibles */
  genres: Genre[];
  /** Slug du genre sélectionné (ou undefined si aucun) */
  selectedGenre?: string;
  /** Année de sortie sélectionnée (ou undefined si aucune) */
  selectedYear?: number;
  /** Type de contenu sélectionné ("film", "serie" ou undefined) */
  selectedType?: string;
  /** Callback invoqué lors du changement de genre */
  onGenreChange: (slug: string | undefined) => void;
  /** Callback invoqué lors du changement d'année */
  onYearChange: (year: number | undefined) => void;
  /** Callback invoqué lors du changement de type de contenu */
  onTypeChange: (type: string | undefined) => void;
}

/**
 * Barre d'outils de filtrage combiné pour les catalogues.
 * 
 * @param props - Propriétés du composant
 * @returns La barre de filtres interactive
 */
export default function FilterBar({
  genres,
  selectedGenre,
  selectedYear,
  selectedType,
  onGenreChange,
  onYearChange,
  onTypeChange,
}: FilterBarProps) {
  // Génération dynamique de la liste des années (de l'année courante à 1960)
  const currentYear = new Date().getFullYear();
  const years: number[] = [];
  for (let y = currentYear; y >= 1960; y--) {
    years.push(y);
  }

  return (
    <div className="filter-bar d-flex flex-wrap align-items-center gap-3 py-3">
      {/* Sélecteur de type : permet de basculer entre Films, Séries ou Tous */}
      <div className="btn-group btn-group-sm" role="group">
        <button
          type="button"
          className={`btn ${!selectedType ? "btn-cinaf" : "btn-cinaf-outline"}`}
          onClick={() => onTypeChange(undefined)}
        >
          Tous
        </button>
        <button
          type="button"
          className={`btn ${selectedType === "film" ? "btn-cinaf" : "btn-cinaf-outline"}`}
          onClick={() => onTypeChange("film")}
        >
          Films
        </button>
        <button
          type="button"
          className={`btn ${selectedType === "serie" ? "btn-cinaf" : "btn-cinaf-outline"}`}
          onClick={() => onTypeChange("serie")}
        >
          Séries
        </button>
      </div>

      {/* Liste horizontale des genres sous forme de puces (chips) cliquables */}
      <div
        className="d-flex gap-2 flex-wrap"
        style={{ maxWidth: "100%", overflow: "auto" }}
      >
        <button
          className={`filter-chip ${!selectedGenre ? "active" : ""}`}
          onClick={() => onGenreChange(undefined)}
        >
          Tous les genres
        </button>
        {genres.map((g) => (
          <button
            key={g.id}
            className={`filter-chip ${selectedGenre === g.slug ? "active" : ""}`}
            onClick={() =>
              // Si l'on reclique sur le genre déjà sélectionné, on le retire
              onGenreChange(selectedGenre === g.slug ? undefined : g.slug)
            }
          >
            {g.name}
          </button>
        ))}
      </div>

      {/* Menu déroulant pour filtrer par année de sortie */}
      <select
        className="form-select form-select-sm"
        style={{ width: "auto", minWidth: 120 }}
        value={selectedYear ?? ""}
        onChange={(e) =>
          onYearChange(e.target.value ? Number(e.target.value) : undefined)
        }
      >
        <option value="">Toutes les années</option>
        {years.map((y) => (
          <option key={y} value={y}>
            {y}
          </option>
        ))}
      </select>
    </div>
  );
}
