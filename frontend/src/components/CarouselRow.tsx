"use client";

// ============================================================
// CINAF v2 — Rangée de contenus défilable horizontalement.
// Enveloppe générique (façon cinaf.tv) : en-tête (icône + titre +
// badge + « Voir tout » + flèches) puis conteneur `.carousel-scroll`.
// Agnostique du contenu : les enfants (WorkCard, StudioCard…) sont
// passés via `children`, chacun dans son propre wrapper de largeur.
// ============================================================

import { useRef, type ReactNode } from "react";
import Link from "next/link";

interface CarouselRowProps {
  title: string;
  /** Icône Bootstrap Icons (ex. "bi-film"). */
  icon?: string;
  /** Compteur total affiché dans un badge doré à côté du titre. */
  badge?: number;
  /** Destination du lien « Voir tout » (masqué si absent). */
  viewAllHref?: string;
  /** Libellé du lien « Voir tout ». */
  viewAllLabel?: string;
  children: ReactNode;
}

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
   * Défilement horizontal fluide de 80 % de la largeur visible,
   * dans la direction demandée (identique à ContentCarousel).
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

      {/* Conteneur défilable (scroll-snap géré par la classe CSS) */}
      <div className="carousel-scroll" ref={scrollRef}>
        {children}
      </div>
    </section>
  );
}
