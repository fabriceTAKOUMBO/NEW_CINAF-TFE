"use client";

// ============================================================
// CINAF v2 — Carte de contenu (film ou serie)
// ============================================================

import Link from "next/link";
import type { Film, Serie } from "@/lib/api";
import StarRating from "./StarRating";

interface ContentCardProps {
  content: Film | Serie;
  type: "film" | "serie";
}

/**
 * Type guard permettant de vérifier si le contenu est un Film.
 * Cela permet d'accéder aux propriétés spécifiques (ex: durée, réalisateurs) en toute sécurité.
 */
function isFilm(content: Film | Serie): content is Film {
  return "duration" in content && "directors" in content;
}

export default function ContentCard({ content, type }: ContentCardProps) {
  // Détermination de l'URL de destination selon le type de contenu
  const href = type === "film" ? `/films/${content.id}` : `/series/${content.id}`;
  // Récupération du premier genre pour l'affichage rapide
  const mainGenre = content.genres?.[0]?.name;

  return (
    <Link href={href} className="text-decoration-none">
      <div className="content-card">
        {/* Poster */}
        <div className="content-card-poster">
          {content.poster ? (
            <img
              src={content.poster}
              alt={content.title}
              loading="lazy"
              className="content-card-img"
            />
          ) : (
            <div className="content-card-placeholder">
              <i className="bi bi-film" style={{ fontSize: "2rem", color: "var(--cinaf-text-muted)" }} />
            </div>
          )}

          {/* Overlay bas */}
          <div className="content-card-overlay">
            <h6 className="content-card-title">{content.title}</h6>
            <div className="d-flex align-items-center gap-2 flex-wrap">
              <span className="content-card-year">{content.year}</span>
              {mainGenre && (
                <span className="content-card-genre">{mainGenre}</span>
              )}
              {isFilm(content) && content.duration > 0 && (
                <span className="content-card-duration">
                  {content.duration} min
                </span>
              )}
            </div>
            {content.avgRating != null && content.avgRating > 0 && (
              <div className="mt-1">
                <StarRating rating={content.avgRating} size="0.7rem" />
              </div>
            )}
          </div>
        </div>
      </div>
    </Link>
  );
}
