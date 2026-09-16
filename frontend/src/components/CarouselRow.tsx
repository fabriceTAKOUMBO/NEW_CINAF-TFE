"use client";

/**
 * ============================================================
 * CINAF v2 — Rangée de contenus défilable (CarouselRow)
 * ============================================================
 * Enveloppe de carrousel horizontal générique s'inspirant de l'interface cinaf.tv.
 * 
 * Conception & Défilement :
 * - En-tête : Titre de section avec icône, badge de comptage optionnel, lien "Voir tout"
 *   et boutons de défilement manuel gauche/droite.
 * - Défilement fluide : La fonction `scroll(direction)` calcule un déplacement de 80%
 *   de la largeur visible pour une transition naturelle (`behavior: "smooth"`).
 * - Agnostique : Reçoit n'importe quel type d'éléments enfants (`children`), tels que
 *   `WorkCard` ou `StudioCard`.
 */

import { useRef, type ReactNode } from "react";
import Link from "next/link";

/**
 * Propriétés attendues par le composant `CarouselRow`.
 */
interface CarouselRowProps {
  /** Titre de la section de carrousel */
  title: string;
  /** Classe Bootstrap Icons optionnelle (ex: "bi-film") */
  icon?: string;
  /** Nombre total d'éléments affiché dans un badge doré à côté du titre */
  badge?: number;
  /** URL de destination du lien « Voir tout » (si non renseigné, le lien est masqué) */
  viewAllHref?: string;
  /** Libellé du lien d'exploration complète (défaut: "Voir tout") */
  viewAllLabel?: string;
  /** Éléments enfants à disposer dans le conteneur défilable */
  children: ReactNode;
}

/**
 * Conteneur de carrousel horizontal avec navigation par flèches et en-tête complet.
 * 
 * @param props - Propriétés du carrousel
 * @returns La section carrousel complète
 */
export default function CarouselRow({
  title,
  icon,
  badge,
  viewAllHref,
  viewAllLabel = "Voir tout",
  children,
}: CarouselRowProps) {
  const scrollRef = useRef<HTMLDivElement>(null);

  /**
   * Défilement horizontal fluide de 80 % de la largeur visible du conteneur.
   * 
   * @param direction - Direction du déplacement ("left" ou "right")
   */
  const scroll = (direction: "left" | "right") => {
    if (!scrollRef.current) return;
    const amount = scrollRef.current.clientWidth * 0.8;
    scrollRef.current.scrollBy({
      left: direction === "left" ? -amount : amount,
      behavior: "smooth",
    });
  };

  return (
    <section className="carousel-section">
      {/* En-tête : titre + badge à gauche, « Voir tout » + flèches à droite */}
      <div className="d-flex align-items-center justify-content-between mb-3">
        <h4
          className="mb-0"
          style={{ color: "var(--cinaf-text)", fontWeight: 700 }}
        >
          {icon && (
            <i className={`bi ${icon} me-2`} style={{ color: "var(--cinaf-gold)" }} />
          )}
          {title}
          {badge !== undefined && (
            <span
              className="badge ms-2"
              style={{ background: "var(--cinaf-gold)", color: "#000", fontSize: "0.7rem" }}
            >
              {badge}
            </span>
          )}
        </h4>
        <div className="d-flex align-items-center gap-2">
          {viewAllHref && (
            <Link
              href={viewAllHref}
              style={{ color: "var(--cinaf-gold)", fontSize: "0.85rem", fontWeight: 500 }}
            >
              {viewAllLabel} <i className="bi bi-arrow-right" />
            </Link>
          )}
          <button
            type="button"
            className="btn btn-sm carousel-arrow"
            onClick={() => scroll("left")}
            aria-label="Défiler vers la gauche"
          >
            <i className="bi bi-chevron-left" />
          </button>
          <button
            type="button"
            className="btn btn-sm carousel-arrow"
            onClick={() => scroll("right")}
            aria-label="Défiler vers la droite"
          >
            <i className="bi bi-chevron-right" />
          </button>
        </div>
      </div>

      {/* Conteneur défilable horizontalement avec scroll-snap CSS */}
      <div className="carousel-scroll" ref={scrollRef}>
        {children}
      </div>
    </section>
  );
}
