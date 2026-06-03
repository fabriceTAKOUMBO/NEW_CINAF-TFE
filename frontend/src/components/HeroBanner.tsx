"use client";

// ============================================================
// CINAF v2 — Hero Banner (mise en avant)
// ============================================================

import Link from "next/link";
import type { Film, Serie } from "@/lib/api";

interface HeroBannerProps {
  content: Film | Serie;
  type: "film" | "serie";
}

export default function HeroBanner({ content, type }: HeroBannerProps) {
  // Construction des liens vers la fiche et vers le lecteur
  const href = type === "film" ? `/films/${content.id}` : `/series/${content.id}`;
  const watchHref = type === "film" ? `/watch/film/${content.id}` : `/series/${content.id}`;

  // Tronquer le synopsis à environ 160 caractères pour l'affichage dans la bannière
  const shortSynopsis =
    content.synopsis && content.synopsis.length > 160
      ? content.synopsis.slice(0, 160).trimEnd() + "..."
      : content.synopsis;

  return (
    <section
      className="hero-banner"
      style={{
        backgroundImage: content.poster
          ? `url(${content.poster})`
          : undefined,
      }}
    >
      <div className="hero-banner-overlay">
        <div className="container h-100 d-flex align-items-end pb-5">
          <div style={{ maxWidth: 650 }}>
            <h1
              style={{
                color: "#fff",
                fontWeight: 800,
                fontSize: "clamp(1.8rem, 4vw, 3rem)",
                lineHeight: 1.15,
                textShadow: "0 2px 20px rgba(0,0,0,0.7)",
              }}
            >
              {content.title}
            </h1>
            
            {/* Informations secondaires : Année, Durée, Genres */}
            <div
              className="d-flex align-items-center gap-3 mt-2 mb-3 flex-wrap"
              style={{ color: "var(--cinaf-text-muted)", fontSize: "0.9rem" }}
            >
              <span>{content.year}</span>
              {"duration" in content && (content as Film).duration > 0 && (
                <span>{(content as Film).duration} min</span>
              )}
              {content.genres?.slice(0, 3).map((g) => (
                <span
                  key={g.id}
                  style={{
                    backgroundColor: "var(--cinaf-gold-light)",
                    color: "var(--cinaf-gold)",
                    padding: "2px 10px",
                    borderRadius: "20px",
                    fontSize: "0.75rem",
                    fontWeight: 600,
                  }}
                >
                  {g.name}
                </span>
              ))}
            </div>
            
            {/* Affichage du synopsis court */}
            {shortSynopsis && (
              <p
                style={{
                  color: "var(--cinaf-text)",
                  fontSize: "0.95rem",
                  lineHeight: 1.5,
                  textShadow: "0 1px 8px rgba(0,0,0,0.5)",
                }}
              >
                {shortSynopsis}
              </p>
            )}
            
            {/* Boutons d'action */}
            <div className="d-flex gap-3 mt-3">
              <Link href={watchHref} className="btn btn-cinaf px-4">
                <i className="bi bi-play-fill me-1" />
                Regarder
              </Link>
              <Link href={href} className="btn btn-cinaf-outline px-4">
                <i className="bi bi-info-circle me-1" />
                Voir la fiche
              </Link>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
