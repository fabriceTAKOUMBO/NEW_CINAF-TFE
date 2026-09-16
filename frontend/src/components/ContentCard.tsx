"use client";

/**
 * ============================================================
 * CINAF v2 — Carte de contenu multimédia (ContentCard)
 * ============================================================
 * Composant de présentation visuelle pour un film ou une série dans les listes.
 * 
 * Fonctionnalités & Affichage :
 * - Type Guard TypeScript (`isFilm`) pour distinguer les propriétés spécifiques aux films (durée, etc.).
 * - Vignette d'affiche avec placeholder par défaut si aucune image n'est renseignée.
 * - Volet superposé (overlay) avec effet dégradé au survol dévoilant le titre,
 *   l'année, le genre principal, la durée en minutes et la note moyenne en étoiles.
 * - Lien cliquable redirigeant vers `/films/{id}` ou `/series/{id}`.
 */

import Link from "next/link";
import type { Film, Serie } from "@/lib/api";
import StarRating from "./StarRating";

/**
 * Propriétés attendues par le composant `ContentCard`.
 */
interface ContentCardProps {
  /** L'objet film ou série complet */
  content: Film | Serie;
  /** Le type de média pour générer l'URL appropriée */
  type: "film" | "serie";
}

/**
 * Type guard permettant de vérifier si le contenu est un Film.
 * Permet d'accéder aux propriétés spécifiques (durée, réalisateurs) en toute sécurité TypeScript.
 * 
 * @param content - L'élément à tester
 * @returns `true` si le contenu dispose des attributs d'un film
 */
function isFilm(content: Film | Serie): content is Film {
  return "duration" in content && "directors" in content;
}

/**
 * Carte de contenu vidéo avec vignette, métadonnées et note.
 * 
 * @param props - Propriétés du composant
 * @returns La carte cliquable
 */
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
            // eslint-disable-next-line @next/next/no-img-element
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

          {/* Volet d'informations superposé en bas de carte */}
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
