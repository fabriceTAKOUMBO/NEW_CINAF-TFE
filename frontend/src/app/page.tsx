"use client";

// ============================================================
// CINAF v2 — Page d'accueil (alimentée par le catalogue Bunny)
// ============================================================

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  discover,
  studios,
  type DiscoverWorkSummary,
  type StudioPublic,
} from "@/lib/api";
import WorkCard from "@/components/WorkCard";
import StudiosGrid from "@/components/StudiosGrid";
import PlaceholderPoster from "@/components/PlaceholderPoster";

const HOME_LIMIT = 12;
// Nombre de studios affichés sur la home (1 ligne de 4 colonnes desktop ×2).
const HOME_STUDIOS_LIMIT = 8;

export default function Home() {
  const [films, setFilms] = useState<DiscoverWorkSummary[]>([]);
  const [series, setSeries] = useState<DiscoverWorkSummary[]>([]);
  const [studiosList, setStudiosList] = useState<StudioPublic[]>([]);
  const [filmsTotal, setFilmsTotal] = useState(0);
  const [seriesTotal, setSeriesTotal] = useState(0);
  const [loading, setLoading] = useState(true);

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

  const heroWork = series[0] || films[0] || null;

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

  return (
    <div>
      {/* Hero CINAF */}
      {heroWork && <CinafHero work={heroWork} />}

      <div className="container py-5">
        {/* Films */}
        {films.length > 0 && (
          <section className="mb-5">
            <div className="d-flex align-items-center justify-content-between mb-3">
              <h4 className="section-title mb-0" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
                <i className="bi bi-film me-2" style={{ color: "var(--cinaf-gold)" }} />
                Films
                <span
                  className="badge ms-2"
                  style={{ background: "var(--cinaf-gold)", color: "#000", fontSize: "0.7rem" }}
                >
                  {filmsTotal}
                </span>
              </h4>
              <Link
                href="/films"
                style={{ color: "var(--cinaf-gold)", fontSize: "0.85rem", fontWeight: 500 }}
              >
                Voir tout <i className="bi bi-arrow-right" />
              </Link>
            </div>
            <div className="row g-3">
              {films.slice(0, 8).map((w) => (
                <div key={w.slug} className="col-6 col-md-3 col-lg-2">
                  <WorkCard work={w} />
                </div>
              ))}
            </div>
          </section>
        )}

        {/* Séries */}
        {series.length > 0 && (
          <section className="mb-5">
            <div className="d-flex align-items-center justify-content-between mb-3">
              <h4 className="section-title mb-0" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
                <i className="bi bi-collection-play me-2" style={{ color: "var(--cinaf-gold)" }} />
                Séries
                <span
                  className="badge ms-2"
                  style={{ background: "var(--cinaf-gold)", color: "#000", fontSize: "0.7rem" }}
                >
                  {seriesTotal}
                </span>
              </h4>
              <Link
                href="/series"
                style={{ color: "var(--cinaf-gold)", fontSize: "0.85rem", fontWeight: 500 }}
              >
                Voir tout <i className="bi bi-arrow-right" />
              </Link>
            </div>
            <div className="row g-3">
              {series.slice(0, 8).map((w) => (
                <div key={w.slug} className="col-6 col-md-3 col-lg-2">
                  <WorkCard work={w} />
                </div>
              ))}
            </div>
          </section>
        )}

        {/* Studios — vue "façon chaîne YouTube".
            Si zéro studio public, la section n'est pas affichée. */}
        {studiosList.length > 0 && (
          <section className="mb-5">
            <div className="d-flex align-items-center justify-content-between mb-3">
              <h4
                className="section-title mb-0"
                style={{ color: "var(--cinaf-text)", fontWeight: 700 }}
              >
                <i
                  className="bi bi-collection-fill me-2"
                  style={{ color: "var(--cinaf-gold)" }}
                />
                Studios
              </h4>
              <Link
                href="/studios"
                style={{
                  color: "var(--cinaf-gold)",
                  fontSize: "0.85rem",
                  fontWeight: 500,
                }}
              >
                Voir tous les studios <i className="bi bi-arrow-right" />
              </Link>
            </div>
            <StudiosGrid studios={studiosList} />
          </section>
        )}

        {/* Empty state */}
        {films.length === 0 && series.length === 0 && (
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

function CinafHero({ work }: { work: DiscoverWorkSummary }) {
  const href = work.kind === "serie" ? `/series/${work.slug}` : `/films/${work.slug}`;
  return (
    <section
      style={{
        background:
          "linear-gradient(180deg, rgba(10,10,10,0.4) 0%, rgba(10,10,10,1) 100%), linear-gradient(135deg, #1a1a1a 0%, #2a2010 60%, #3d2f15 100%)",
        padding: "4rem 0 3rem",
        borderBottom: "1px solid var(--cinaf-border)",
      }}
    >
      <div className="container">
        <div className="row g-4 align-items-center">
          <div className="col-12 col-md-3 col-lg-2">
            <PlaceholderPoster title={work.title} ratio="portrait" />
          </div>
          <div className="col-12 col-md-9 col-lg-10">
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
            <h1 style={{ color: "#fff", fontWeight: 800, fontSize: "clamp(1.8rem, 4vw, 3rem)" }}>
              {work.title.replace(/_/g, " ")}
            </h1>
            <p style={{ color: "var(--cinaf-text-muted)", maxWidth: 640 }}>
              Le cinéma africain à portée de clic. Films, séries, et productions originales en
              streaming HD adaptatif.
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
        </div>
      </div>
    </section>
  );
}
