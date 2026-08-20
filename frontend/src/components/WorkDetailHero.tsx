// ============================================================
// CINAF v2 — Hero cinématographique des pages détail film/série.
// Inspiré de cinaf.tv : bannière plein cadre, affiche à gauche,
// titre + ligne de métadonnées en pastilles + chip studio + CTA.
// Alimenté par une IMAGE (placeholder doré), jamais une vidéo.
// Charte conservée (tokens + classes .hero-banner existantes).
// ============================================================

import Link from "next/link";
import type { ReactNode } from "react";
import type { DiscoverWork } from "@/lib/api";
import PlaceholderPoster from "./PlaceholderPoster";
import StudioChip from "./StudioChip";

interface WorkDetailHeroProps {
  work: DiscoverWork;
  backHref: string;
  backLabel: string;
  /** Pastilles de métadonnées (ex. « Série », « HD », « 2 saisons · 24 épisodes »). */
  metaItems: string[];
  /** Boutons d'action (Regarder…) rendus sous les métadonnées. */
  actions: ReactNode;
}

export default function WorkDetailHero({
  work,
  backHref,
  backLabel,
  metaItems,
  actions,
}: WorkDetailHeroProps) {
  const isSerie = work.kind === "serie";

  return (
    // Contenu en flux normal (pas d'overlay absolu) : la section grandit avec
    // son contenu et ne déborde jamais sur la section suivante en mobile.
    <section
      className="hero-banner d-flex flex-column"
      style={{
        // Fond dégradé de marque (le catalogue Bunny live n'expose pas d'image).
        backgroundImage:
          "linear-gradient(135deg, #1a1a1a 0%, #2a2010 55%, #3d2f15 100%)",
      }}
    >
      <div className="container d-flex flex-column flex-grow-1">
        {/* Fil d'Ariane / retour, ancré en haut */}
        <div className="pt-4">
          <Link
            href={backHref}
            style={{ color: "var(--cinaf-text-muted)", textDecoration: "none" }}
          >
            <i className="bi bi-arrow-left me-1" />
            {backLabel}
          </Link>
        </div>

        {/* Bloc principal ancré en bas (mt-auto) */}
        <div className="row g-4 align-items-end mt-auto pt-4 pb-5">
          <div className="col-6 col-sm-4 col-md-4 col-lg-3">
            <div style={{ maxWidth: 260 }}>
              <PlaceholderPoster title={work.title} ratio="portrait" />
            </div>
          </div>

          <div className="col-12 col-md-8 col-lg-9">
              <span
                className="badge mb-2"
                style={{
                  background: "rgba(0,0,0,0.6)",
                  color: isSerie ? "#8ad" : "var(--cinaf-gold)",
                  fontWeight: 700,
                  textTransform: "uppercase",
                  letterSpacing: "0.1em",
                }}
              >
                {isSerie ? "Série" : "Film"}
              </span>

              <h1
                style={{
                  color: "#fff",
                  fontWeight: 800,
                  fontSize: "clamp(1.8rem, 4vw, 3rem)",
                  lineHeight: 1.15,
                  textShadow: "0 2px 20px rgba(0,0,0,0.7)",
                }}
              >
                {work.title.replace(/_/g, " ")}
              </h1>

              {/* Ligne de métadonnées en pastilles dorées */}
              <div className="d-flex flex-wrap align-items-center gap-2 mt-2 mb-3">
                {metaItems.map((item) => (
                  <span
                    key={item}
                    style={{
                      backgroundColor: "var(--cinaf-gold-light)",
                      color: "var(--cinaf-gold)",
                      padding: "3px 12px",
                      borderRadius: 20,
                      fontSize: "0.8rem",
                      fontWeight: 600,
                    }}
                  >
                    {item}
                  </span>
                ))}
              </div>

              {work.studio && (
                <div className="mb-3">
                  <StudioChip studio={work.studio} />
                </div>
              )}

              <div className="d-flex flex-wrap gap-2">{actions}</div>
            </div>
          </div>
        </div>
    </section>
  );
}
