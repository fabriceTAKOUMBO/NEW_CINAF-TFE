"use client";

// ============================================================
// CINAF v2 — Barre de filtres (genres, annee, type)
// ============================================================

import type { Genre } from "@/lib/api";

interface FilterBarProps {
  genres: Genre[];
  selectedGenre?: string;
  selectedYear?: number;
  selectedType?: string;
  onGenreChange: (slug: string | undefined) => void;
  onYearChange: (year: number | undefined) => void;
  onTypeChange: (type: string | undefined) => void;
}

export default function FilterBar({
  genres,
  selectedGenre,
  selectedYear,
  selectedType,
  onGenreChange,
  onYearChange,
  onTypeChange,
}: FilterBarProps) {
  // Génération dynamique de la liste des années (du présent jusqu'à 1960)
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
          Series
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
              // Si on reclique sur le genre actif, on désactive le filtre
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
          // Conversion de la valeur string en number pour l'API
          onYearChange(e.target.value ? Number(e.target.value) : undefined)
        }
      >
        <option value="">Toutes les annees</option>
        {years.map((y) => (
          <option key={y} value={y}>
            {y}
          </option>
        ))}
      </select>
    </div>
  );
}
