"use client";

// ============================================================
// CINAF v2 — Page d'accueil (alimentée par le catalogue Bunny)
// Refonte inspirée de cinaf.tv : bannière image → filtres →
// rangées horizontales (carrousels) Films / Séries → Studios.
// La charte graphique (thème sombre + accents dorés) est conservée.
// ============================================================

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import {
  discover,
  studios,
  type DiscoverWorkSummary,
  type StudioPublic,
} from "@/lib/api";
import WorkCard from "@/components/WorkCard";
import CarouselRow from "@/components/CarouselRow";
import StudioCard from "@/components/StudioCard";
import Poster from "@/components/Poster";

// On charge ~15 œuvres par type pour remplir les carrousels (cf. cinaf.tv).
const HOME_LIMIT = 15;
// Studios présentés dans la rangée horizontale « chaîne ».
const HOME_STUDIOS_LIMIT = 12;

// Filtre de type appliqué aux rangées de contenus (studios non concernés).
type ContentFilter = "all" | "film" | "serie";

/**
 * Page d'accueil de la plateforme CINAF.
 * 
 * Fonctionnalités :
 * - Charge en parallèle via `Promise.allSettled` les films découverts (`discover.list`),
 *   les séries (`discover.list`), et la liste des studios partenaires (`studios.list`).
 * - Affiche une bannière héroïque cinématographique (`CinafHero`) mettant en avant la première
 *   œuvre majeure du catalogue.
 * - Propose des filtres rapides (« Tout », « Films », « Séries ») pour moduler les carrousels visibles.
 * - Présente des carrousels horizontaux fluides (`CarouselRow`) pour parcourir les productions.
 * - Offre un carrousel dédié aux chaînes de studios pour découvrir les producteurs indépendants.
 * - Gère l'état de chargement initial et l'état vide en l'absence temporaire de contenu.
 * 
 * @returns La page d'accueil interactive de CINAF.
 */
export default function Home() {
  const [films, setFilms] = useState<DiscoverWorkSummary[]>([]);
  const [series, setSeries] = useState<DiscoverWorkSummary[]>([]);
  const [studiosList, setStudiosList] = useState<StudioPublic[]>([]);
  const [filmsTotal, setFilmsTotal] = useState(0);
  const [seriesTotal, setSeriesTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<ContentFilter>("all");

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    Promise.allSettled([
      discover.list({ kind: "film", limit: HOME_LIMIT }),
      discover.list({ kind: "serie", limit: HOME_LIMIT }),
      studios.list({ itemsPerPage: HOME_STUDIOS_LIMIT }),
    ])
      .then(([filmsRes, seriesRes, studiosRes]) => {
        if (cancelled) return;
        if (filmsRes.status === "fulfilled") {
          setFilms(filmsRes.value.data);
          setFilmsTotal(filmsRes.value.total);
        }
        if (seriesRes.status === "fulfilled") {
          setSeries(seriesRes.value.data);
          setSeriesTotal(seriesRes.value.total);
        }
        if (studiosRes.status === "fulfilled") {
          setStudiosList(studiosRes.value.data);
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  // Contenu mis en avant dans la bannière : 1re série sinon 1er film.
  const heroWork = useMemo(() => series[0] || films[0] || null, [series, films]);

  const showFilms = filter === "all" || filter === "film";
  const showSeries = filter === "all" || filter === "serie";

  if (loading) {
    return (
      <div
        className="d-flex align-items-center justify-content-center"
        style={{ minHeight: "60vh" }}
      >
        <div className="text-center">
          <div
            className="spinner-border mb-3"
            style={{ color: "var(--cinaf-gold)", width: "3rem", height: "3rem" }}
            role="status"
          >
            <span className="visually-hidden">Chargement...</span>
          </div>
          <p style={{ color: "var(--cinaf-text-muted)" }}>Chargement du catalogue...</p>
        </div>
      </div>
    );
  }

  const hasContent = films.length > 0 || series.length > 0;

  return (
    <div>
      {/* Bannière image (jamais vidéo) sur le contenu à la une */}
      {heroWork && <CinafHero work={heroWork} />}

      <div className="container py-5">
        {/* Barre de filtres par type — Tout / Films / Séries.
            L'API discover ne fournit pas de genre : on s'en tient au type. */}
        {hasContent && (
          <div className="d-flex flex-wrap gap-2 mb-4" role="tablist" aria-label="Filtrer par type">
            {(
              [
                { id: "all", label: "Tout" },
                { id: "film", label: "Films" },
                { id: "serie", label: "Séries" },
              ] as { id: ContentFilter; label: string }[]
            ).map((chip) => (
              <button
                key={chip.id}
                type="button"
                className={`filter-chip ${filter === chip.id ? "active" : ""}`}
                aria-pressed={filter === chip.id}
                onClick={() => setFilter(chip.id)}
              >
                {chip.label}
              </button>
            ))}
          </div>
        )}

        {/* Rangée Films */}
        {showFilms && films.length > 0 && (
          <CarouselRow title="Films" icon="bi-film" badge={filmsTotal} viewAllHref="/films">
            {films.map((w) => (
              <div key={w.slug} className="carousel-item-wrapper">
                <WorkCard work={w} />
              </div>
            ))}
          </CarouselRow>
        )}

        {/* Rangée Séries */}
        {showSeries && series.length > 0 && (
          <CarouselRow
            title="Séries"
            icon="bi-collection-play"
            badge={seriesTotal}
            viewAllHref="/series"
          >
            {series.map((w) => (
              <div key={w.slug} className="carousel-item-wrapper">
                <WorkCard work={w} />
              </div>
            ))}
          </CarouselRow>
        )}

        {/* Rangée Studios — vue « chaîne ». Indépendante du filtre de type.
            Chaque tuile conserve le nb de vidéos publiées et d'abonnés. */}
        {studiosList.length > 0 && (
          <CarouselRow
            title="Studios"
            icon="bi-collection-fill"
            viewAllHref="/studios"
            viewAllLabel="Voir tous les studios"
          >
            {studiosList.map((s) => (
              // Les tuiles studio sont plus larges que les vignettes d'œuvres.
              <div key={s.id} style={{ flex: "0 0 240px", scrollSnapAlign: "start" }}>
                <StudioCard studio={s} />
              </div>
            ))}
          </CarouselRow>
        )}

        {/* Empty state */}
        {!hasContent && (
          <div className="text-center py-5">
            <i
              className="bi bi-camera-reels"
              style={{ fontSize: "4rem", color: "var(--cinaf-text-muted)" }}
            />
            <h3 className="mt-3" style={{ color: "var(--cinaf-text)", fontWeight: 600 }}>
              Le catalogue arrive bientôt
            </h3>
            <p style={{ color: "var(--cinaf-text-muted)" }}>
              Nos équipes préparent les meilleurs films et séries africains pour vous.
            </p>
          </div>
        )}
      </div>
    </div>
  );
}

/**
 * Bannière « à la une » plein cadre, façon cinaf.tv, mais alimentée par
 * une IMAGE (placeholder doré) et non une vidéo. Le contenu texte est
 * ancré en bas à gauche ; l'affiche portrait apparaît à droite (desktop).
 * 
 * @param props.work - L'œuvre mise à l'honneur (film ou série).
 * @returns La section hero d'en-tête de la page d'accueil.
 */
function CinafHero({ work }: { work: DiscoverWorkSummary }) {
  const href = work.kind === "serie" ? `/series/${work.slug}` : `/films/${work.slug}`;
  return (
    <section
      className="hero-banner d-flex flex-column"
      style={{
        // Fond dégradé de marque (pas d'image de couverture côté Bunny live).
        backgroundImage:
          "linear-gradient(135deg, #1a1a1a 0%, #2a2010 55%, #3d2f15 100%)",
      }}
    >
      {/* Contenu en flux normal (pas d'overlay absolu) : la section grandit
          avec son contenu et ne déborde pas sur les sections suivantes. */}
      <div className="container d-flex flex-column flex-grow-1">
          <div className="row g-4 align-items-end mt-auto py-5 w-100">
            <div className="col-12 col-md-8 col-lg-9">
              <span
                className="badge mb-2"
                style={{
                  background: "rgba(0,0,0,0.6)",
                  color: work.kind === "serie" ? "#8ad" : "var(--cinaf-gold)",
                  fontWeight: 700,
                  textTransform: "uppercase",
                  letterSpacing: "0.1em",
                }}
              >
                {work.kind === "serie" ? "Série" : "Film"} · à la une
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
              <p style={{ color: "var(--cinaf-text-muted)", maxWidth: 640 }}>
                Le cinéma africain à portée de clic. Films, séries et productions
                originales en streaming HD adaptatif.
              </p>
              <div className="d-flex gap-2 mt-3">
                <Link href={href} className="btn btn-cinaf px-4">
                  <i className="bi bi-info-circle me-1" />
                  Voir la fiche
                </Link>
                <Link href="/catalogue" className="btn btn-cinaf-outline px-4">
                  Parcourir le catalogue
                </Link>
              </div>
            </div>
            {/* Affiche portrait (l'« image » du hero), masquée sur mobile */}
            <div className="col-md-4 col-lg-3 d-none d-md-block">
              <div style={{ maxWidth: 220, marginLeft: "auto" }}>
                <Poster title={work.title} src={work.poster} ratio="portrait" />
              </div>
            </div>
          </div>
        </div>
    </section>
  );
}
