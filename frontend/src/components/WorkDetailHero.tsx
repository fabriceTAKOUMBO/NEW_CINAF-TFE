/**
 * ============================================================
 * CINAF v2 — Hero cinématographique de détail (WorkDetailHero)
 * ============================================================
 * Bannière d'en-tête immersive affichée au sommet des fiches films et séries.
 * 
 * Conception & Responsive :
 * - Inspiré des interfaces VOD modernes (fond dégradé sombre de prestige).
 * - Affiche grand format à gauche avec ombre portée.
 * - Titre mis en valeur avec ombre portée pour une lisibilité parfaite.
 * - Ligne de métadonnées sous forme de pastilles dorées translucides (HD, durée, saisons, etc.).
 * - Badge de studio (`StudioChip`) cliquable si l'œuvre est rattachée à un producteur.
 * - Boutons d'action contextuels injectés via la prop `actions` (Regarder, Bande-annonce, etc.).
 * - Disposition en flux normal pour s'adapter dynamiquement sur mobile sans coupure.
 */

import Link from "next/link";
import type { ReactNode } from "react";
import type { DiscoverWork } from "@/lib/api";
import Poster from "./Poster";
import StudioChip from "./StudioChip";

/**
 * Propriétés attendues par le composant `WorkDetailHero`.
 */
interface WorkDetailHeroProps {
  /** L'œuvre complète à présenter */
  work: DiscoverWork;
  /** Lien de retour vers le catalogue ou la catégorie parente */
  backHref: string;
  /** Texte du lien de retour (ex: "Retour aux films") */
  backLabel: string;
  /** Liste des pastilles de métadonnées (ex: ["Film", "HD", "2h 15min"]) */
  metaItems: string[];
  /** Éléments JSX contenant les boutons d'action d'appel (Regarder, Bande-annonce) */
  actions: ReactNode;
}

/**
 * Bannière héro cinématographique pour les fiches descriptives d'œuvres.
 * 
 * @param props - Propriétés du composant
 * @returns La section hero plein cadre
 */
export default function WorkDetailHero({
  work,
  backHref,
  backLabel,
  metaItems,
  actions,
}: WorkDetailHeroProps) {
  const isSerie = work.kind === "serie";

  return (
    // Section en flux normal adaptatif grandissant avec le contenu
    <section
      className="hero-banner d-flex flex-column"
      style={{
        backgroundImage:
          "linear-gradient(135deg, #1a1a1a 0%, #2a2010 55%, #3d2f15 100%)",
      }}
    >
      <div className="container d-flex flex-column flex-grow-1">
        {/* Fil d'Ariane / bouton de retour supérieur */}
        <div className="pt-4">
          <Link
            href={backHref}
            style={{ color: "var(--cinaf-text-muted)", textDecoration: "none" }}
          >
            <i className="bi bi-arrow-left me-1" />
            {backLabel}
          </Link>
        </div>

        {/* Bloc principal avec affiche et métadonnées alignées en bas */}
        <div className="row g-4 align-items-end mt-auto pt-4 pb-5">
          <div className="col-6 col-sm-4 col-md-4 col-lg-3">
            <div style={{ maxWidth: 260 }}>
              <Poster title={work.title} src={work.poster} ratio="portrait" />
            </div>
          </div>

          <div className="col-12 col-md-8 col-lg-9">
            {/* Badge de format (Série ou Film) */}
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

            {/* Titre principal de l'œuvre */}
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

            {/* Badge studio si rattaché */}
            {work.studio && (
              <div className="mb-3">
                <StudioChip studio={work.studio} />
              </div>
            )}

            {/* Boutons d'action (CTA) */}
            <div className="d-flex flex-wrap gap-2">{actions}</div>
          </div>
        </div>
      </div>
    </section>
  );
}
