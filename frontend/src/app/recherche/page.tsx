"use client";

// ============================================================
// CINAF v2 — Page Recherche
// Cette page permet de rechercher du contenu dans tout le catalogue.
// Elle gère les recherches asynchrones pour les films et les séries.
// ============================================================

import { Suspense, useEffect, useState, useCallback } from "react";
import { useSearchParams } from "next/navigation";
import {
  catalogue,
  studios,
  type Film,
  type Serie,
  type StudioPublic,
} from "@/lib/api";
import ContentGrid from "@/components/ContentGrid";
import StudiosGrid from "@/components/StudiosGrid";
import SearchBar from "@/components/SearchBar";

// Enveloppe Suspense pour useSearchParams (requis par Next.js 14 App Router)
export default function RecherchePage() {
  return (
    <Suspense fallback={<div className="d-flex justify-content-center py-5"><div className="spinner-border text-warning" /></div>}>
      <RechercheContent />
    </Suspense>
  );
}

function RechercheContent() {
  const searchParams = useSearchParams();
  // Récupération du paramètre de recherche 'q' depuis l'URL
  const q = searchParams.get("q") || "";

  // États pour la requête, les résultats (films/séries/studios) et l'indicateur de chargement
  const [query, setQuery] = useState(q);
  const [films, setFilms] = useState<Film[]>([]);
  const [series, setSeries] = useState<Serie[]>([]);
  const [studioResults, setStudioResults] = useState<StudioPublic[]>([]);
  const [loading, setLoading] = useState(false);

  /**
   * Fonction de recherche principale.
   * Utilise Promise.allSettled pour lancer les recherches films, séries et
   * studios en parallèle — un échec sur l'un n'empêche pas l'affichage des autres.
   */
  const doSearch = useCallback(async (searchQuery: string) => {
    if (!searchQuery.trim()) {
      setFilms([]);
      setSeries([]);
      setStudioResults([]);
      return;
    }

    setLoading(true);
    try {
      const [filmsRes, seriesRes, studiosRes] = await Promise.allSettled([
        catalogue.searchFilms({ q: searchQuery }),
        catalogue.searchSeries({ q: searchQuery }),
        studios.search(searchQuery),
      ]);

      if (filmsRes.status === "fulfilled") setFilms(filmsRes.value.data);
      if (seriesRes.status === "fulfilled") setSeries(seriesRes.value.data);
      if (studiosRes.status === "fulfilled") setStudioResults(studiosRes.value);
    } catch {
      // Les erreurs individuelles sont gérées par le mécanisme de Promise.allSettled
    } finally {
      setLoading(false);
    }
  }, []);

  /**
   * Déclenche une recherche automatique si un paramètre 'q' est présent à l'initialisation.
   */
  useEffect(() => {
    if (q) {
      setQuery(q);
      doSearch(q);
    }
  }, [q, doSearch]);

  /**
   * Met à jour l'état local et relance la recherche lors d'un changement dans la barre de recherche.
   */
  const handleSearch = (val: string) => {
    setQuery(val);
    doSearch(val);
  };

  // Calcul du nombre total de résultats trouvés (films + séries + studios)
  const totalResults = films.length + series.length + studioResults.length;

  return (
    <div className="container py-4">
      <h2 className="section-title">Recherche</h2>

      {/* Barre de recherche intégrée (inline) avec focus automatique */}
      <div className="mb-4">
        <SearchBar
          mode="inline"
          defaultValue={query}
          onChange={handleSearch}
          placeholder="Rechercher un film, une série, un acteur..."
          autoFocus
        />
      </div>

      {/* Affichage conditionnel selon l'état de la recherche */}
      {loading ? (
        /* État : Chargement en cours */
        <div className="text-center py-5">
          <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} role="status">
            <span className="visually-hidden">Recherche en cours...</span>
          </div>
        </div>
      ) : query.trim() === "" ? (
        /* État : Recherche vide / initiale */
        <div className="text-center py-5">
          <i className="bi bi-search" style={{ fontSize: "4rem", color: "var(--cinaf-text-muted)" }} />
          <h4 className="mt-3" style={{ color: "var(--cinaf-text)" }}>
            Que cherchez-vous ?
          </h4>
          <p style={{ color: "var(--cinaf-text-muted)" }}>
            Tapez le nom d&apos;un film, d&apos;une série ou d&apos;un artiste.
          </p>
        </div>
      ) : totalResults === 0 ? (
        /* État : Aucun résultat trouvé */
        <div className="text-center py-5">
          <i className="bi bi-emoji-frown" style={{ fontSize: "3rem", color: "var(--cinaf-text-muted)" }} />
          <h4 className="mt-3" style={{ color: "var(--cinaf-text)" }}>
            Aucun résultat pour &laquo;{query}&raquo;
          </h4>
          <p style={{ color: "var(--cinaf-text-muted)" }}>
            Vérifiez l&apos;orthographe ou essayez d&apos;autres mots-clés.
          </p>
        </div>
      ) : (
        /* État : Résultats trouvés (Films et/ou Séries) */
        <>
          <p style={{ color: "var(--cinaf-text-muted)", fontSize: "0.85rem" }}>
            {totalResults} résultat{totalResults !== 1 ? "s" : ""} pour &laquo;{query}&raquo;
          </p>

          {/* Section dédiée aux Films trouvés */}
          {films.length > 0 && (
            <section className="mb-5">
              <h5 className="mb-3" style={{ color: "var(--cinaf-gold)", fontWeight: 600 }}>
                Films ({films.length} résultat{films.length !== 1 ? "s" : ""})
              </h5>
              <ContentGrid items={films} type="film" />
            </section>
          )}

          {/* Section dédiée aux Séries trouvées */}
          {series.length > 0 && (
            <section className="mb-5">
              <h5 className="mb-3" style={{ color: "var(--cinaf-gold)", fontWeight: 600 }}>
                Séries ({series.length} résultat{series.length !== 1 ? "s" : ""})
              </h5>
              <ContentGrid items={series} type="serie" />
            </section>
          )}

          {/* Section dédiée aux Studios trouvés (max 20 côté backend) */}
          {studioResults.length > 0 && (
            <section className="mb-5">
              <h5 className="mb-3" style={{ color: "var(--cinaf-gold)", fontWeight: 600 }}>
                <i className="bi bi-collection-fill me-2" />
                Studios ({studioResults.length} résultat
                {studioResults.length !== 1 ? "s" : ""})
              </h5>
              <StudiosGrid studios={studioResults} />
            </section>
          )}
        </>
      )}
    </div>
  );
}
