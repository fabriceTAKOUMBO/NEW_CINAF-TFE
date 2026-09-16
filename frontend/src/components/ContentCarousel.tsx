"use client";

/**
 * ============================================================
 * CINAF v2 — Carrousel horizontal de contenus (ContentCarousel)
 * ============================================================
 * Carrousel de vignettes spécialisé pour afficher une rangée de films ou de séries
 * (ex: "Tendances du moment", "Nouvelles sorties", "Recommandations").
 * 
 * Fonctionnalités :
 * - Navigation fluide vers la gauche ou la droite via `scrollRef`.
 * - Lien direct "Voir tout" pointant vers la section complète du catalogue.
 * - Ne rend rien si la liste d'éléments fournie est vide.
 */

import { useRef } from "react";
import type { Film, Serie } from "@/lib/api";
import ContentCard from "./ContentCard";

/**
 * Propriétés attendues par le composant `ContentCarousel`.
 */
interface ContentCarouselProps {
  /** Titre de la section de carrousel (ex: "Tendances") */
  title: string;
  /** Tableau de films ou de séries à afficher */
  items: (Film | Serie)[];
  /** Type d'éléments contenus dans la rangée */
  type: "film" | "serie";
  /** Lien optionnel vers la vue complète */
  viewAllHref?: string;
}

/**
 * Carrousel horizontal de contenus vidéo avec boutons de défilement.
 * 
 * @param props - Propriétés du composant
 * @returns La section carrousel ou `null` si la liste est vide
 */
export default function ContentCarousel({
  title,
  items,
  type,
  viewAllHref,
}: ContentCarouselProps) {
  // Référence vers le conteneur défilable
  const scrollRef = useRef<HTMLDivElement>(null);

  /**
   * Gère le défilement horizontal fluide du carrousel.
   * 
   * @param direction - Direction du défilement ('left' ou 'right')
   */
  const scroll = (direction: "left" | "right") => {
    if (!scrollRef.current) return;
    
    // Défilement de 80% de la largeur visible du conteneur
    const amount = scrollRef.current.clientWidth * 0.8;
    
    scrollRef.current.scrollBy({
      left: direction === "left" ? -amount : amount,
      behavior: "smooth",
    });
  };

  // Si pas d'articles, masquer la section
  if (items.length === 0) return null;

  return (
    <section className="carousel-section">
      {/* En-tête : Titre et boutons de navigation */}
      <div className="d-flex align-items-center justify-content-between mb-3">
        <h4
          style={{
            color: "var(--cinaf-gold)",
            fontWeight: 700,
            fontSize: "1.3rem",
            margin: 0,
          }}
        >
          {title}
        </h4>
        <div className="d-flex align-items-center gap-2">
          {viewAllHref && (
            <a
              href={viewAllHref}
              style={{
                color: "var(--cinaf-gold)",
                fontSize: "0.85rem",
                fontWeight: 500,
                textDecoration: "none",
              }}
            >
              Voir tout <i className="bi bi-arrow-right" />
            </a>
          )}
          <button
            className="btn btn-sm carousel-arrow"
            onClick={() => scroll("left")}
            aria-label="Défiler vers la gauche"
          >
            <i className="bi bi-chevron-left" />
          </button>
          <button
            className="btn btn-sm carousel-arrow"
            onClick={() => scroll("right")}
            aria-label="Défiler vers la droite"
          >
            <i className="bi bi-chevron-right" />
          </button>
        </div>
      </div>

      {/* Conteneur de défilement horizontal */}
      <div className="carousel-scroll" ref={scrollRef}>
        {items.map((item) => (
          <div key={item.id} className="carousel-item-wrapper">
            <ContentCard content={item} type={type} />
          </div>
        ))}
      </div>
    </section>
  );
}
