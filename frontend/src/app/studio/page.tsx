"use client";

// ============================================================
// CINAF v2 — Dashboard studio
// Affiche le studio courant + statistiques agrégées + listes
// courtes des derniers films/séries.
// ============================================================

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  studio,
  studioFilms,
  studioSeries,
  type StudioMeResponse,
  type StudioFilm,
  type StudioSerie,
} from "@/lib/api";
import StatusBadge from "@/components/studio/StatusBadge";

interface StatTile {
  label: string;
  value: number;
  icon: string;
  color: string;
}

/**
 * Tableau de bord d'accueil de l'espace studio (/studio).
 * 
 * Fonctionnalités :
 * - Charge les métadonnées du studio et ses statistiques agrégées via `studio.getMe`.
 * - Récupère les 5 derniers films et 5 dernières séries pour un accès rapide.
 * - Présente des tuiles récapitulatives (films publiés, brouillons, séries, abonnés, retraits).
 * - Affiche une bannière d'information si le studio est encore en attente de première modération.
 * 
 * @returns Le tableau de bord du studio.
 */
export default function StudioDashboardPage() {
  const [me, setMe] = useState<StudioMeResponse | null>(null);
  const [films, setFilms] = useState<StudioFilm[]>([]);
  const [series, setSeries] = useState<StudioSerie[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const [meRes, filmsRes, seriesRes] = await Promise.all([
          studio.getMe(),
          studioFilms.list({ page: 1, limit: 5 }),
          studioSeries.list({ page: 1, limit: 5 }),
        ]);
        if (cancelled) return;
        setMe(meRes);
        setFilms(filmsRes.data ?? []);
        setSeries(seriesRes.data ?? []);
      } catch (err: unknown) {
        if (cancelled) return;
        setError(
          (err as { message?: string })?.message ?? "Impossible de charger le studio.",
        );
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  if (loading) {
    return (
      <div className="text-center py-5">
        <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} />
      </div>
    );
  }

  if (error || !me) {
    return (
      <div
        className="alert"
        style={{ background: "#2a1414", color: "#ff8a8a", border: "1px solid #5a2020" }}
      >
        <i className="bi bi-exclamation-triangle me-2" />
        {error ?? "Aucun studio trouvé pour ce compte."}
      </div>
    );
  }

  // `subscribersCount` peut être absent si le backend n'a pas encore été
  // déployé avec la nouvelle clé (ou en cache navigateur) — fallback à 0
  // pour ne pas planter l'affichage.
  const subscribersCount = me.stats.subscribersCount ?? 0;

  const tiles: StatTile[] = [
    { label: "Films", value: me.stats.totalFilms, icon: "bi-film", color: "var(--cinaf-gold)" },
    { label: "Films publiés", value: me.stats.publishedFilms, icon: "bi-broadcast", color: "#8fd68f" },
    { label: "Brouillons films", value: me.stats.draftFilms, icon: "bi-pencil-square", color: "#bfc7cf" },
    { label: "Séries", value: me.stats.totalSeries, icon: "bi-collection-play", color: "var(--cinaf-gold)" },
    { label: "Séries publiées", value: me.stats.publishedSeries, icon: "bi-broadcast-pin", color: "#8fd68f" },
    // Compteur d'abonnés à la chaîne (follow gratuit style YouTube),
    // distinct des abonnements payants Stripe. Couleur dorée volontaire
    // pour la mettre en avant comme KPI principal pour le studio.
    { label: "Abonnés", value: subscribersCount, icon: "bi-people-fill", color: "var(--cinaf-gold)" },
    { label: "Retraits en attente", value: me.stats.pendingWithdrawals, icon: "bi-hourglass-split", color: "#f0c080" },
  ];

  return (
    <div>
      {/* Bandeau studio non validé */}
      {me.studio.isValidated === false && (
        <div
          className="d-flex align-items-start gap-2 p-3 mb-3"
          style={{
            background: "rgba(160,120,48,0.15)",
            border: "1px solid rgba(240,192,128,0.4)",
            borderRadius: 10,
            color: "#f0c080",
          }}
        >
          <i
            className="bi bi-info-circle-fill mt-1"
            style={{ fontSize: "1.1rem" }}
          />
          <div>
            <strong>Studio en attente de validation.</strong> Votre premier
            contenu publié sera examiné par un administrateur avant d&apos;être
            mis en ligne. Une fois ce premier contenu approuvé, vos
            publications suivantes seront immédiates.
          </div>
        </div>
      )}

      {/* En-tête studio */}
      <div
        className="d-flex flex-wrap align-items-center gap-3 p-3 mb-4"
        style={{
          background: "var(--cinaf-surface)",
          border: "1px solid var(--cinaf-border)",
          borderRadius: 10,
        }}
      >
        <div
          style={{
            width: 64,
            height: 64,
            borderRadius: 12,
            background: me.studio.logoUrl
              ? `url(${me.studio.logoUrl}) center/cover`
              : "linear-gradient(135deg, var(--cinaf-gold), #8a6420)",
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            color: "#000",
            fontWeight: 800,
            fontSize: "1.5rem",
            flex: "0 0 auto",
          }}
        >
          {!me.studio.logoUrl && me.studio.name.slice(0, 2).toUpperCase()}
        </div>
        <div className="flex-grow-1">
          <h2
            className="mb-1"
            style={{ color: "var(--cinaf-text)", fontWeight: 700 }}
          >
            {me.studio.name}
          </h2>
          {me.studio.description && (
            <p className="mb-0" style={{ color: "var(--cinaf-text-muted)" }}>
              {me.studio.description}
            </p>
          )}
        </div>
        <span
          className="badge"
          style={{
            background: me.studio.isActive ? "rgba(31,99,55,0.18)" : "rgba(122,32,32,0.20)",
            color: me.studio.isActive ? "#8fd68f" : "#ff8a8a",
            border: `1px solid ${me.studio.isActive ? "#8fd68f" : "#ff8a8a"}`,
          }}
        >
          {me.studio.isActive ? "Actif" : "Inactif"}
        </span>
      </div>

      {/* Grille de stats */}
      <div className="row g-3 mb-4">
        {tiles.map((t) => (
          <div key={t.label} className="col-6 col-md-4 col-lg-2">
            <div
              className="p-3 h-100 d-flex flex-column"
              style={{
                background: "var(--cinaf-surface)",
                border: "1px solid var(--cinaf-border)",
                borderRadius: 10,
              }}
            >
              <i className={`bi ${t.icon} mb-2`} style={{ color: t.color, fontSize: "1.3rem" }} />
              <div
                style={{
                  color: "var(--cinaf-text)",
                  fontSize: "1.6rem",
                  fontWeight: 700,
                  lineHeight: 1.1,
                }}
              >
                {t.value}
              </div>
              <div className="small" style={{ color: "var(--cinaf-text-muted)" }}>
                {t.label}
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Listes courtes */}
      <div className="row g-3">
        <div className="col-12 col-lg-6">
          <DashboardList
            title="Derniers films"
            href="/studio/films"
            createHref="/studio/films/new"
            items={films.map((f) => ({
              id: f.id,
              title: f.title,
              status: f.status,
              meta: `${f.year}`,
              editHref: `/studio/films/${f.id}/edit`,
            }))}
            emptyLabel="Aucun film pour le moment."
          />
        </div>
        <div className="col-12 col-lg-6">
          <DashboardList
            title="Dernières séries"
            href="/studio/series"
            createHref="/studio/series/new"
            items={series.map((s) => ({
              id: s.id,
              title: s.title,
              status: s.status,
              meta: `${s.year}`,
              editHref: `/studio/series/${s.id}/edit`,
            }))}
            emptyLabel="Aucune série pour le moment."
          />
        </div>
      </div>
    </div>
  );
}

interface DashboardListItem {
  id: string;
  title: string;
  status: StudioFilm["status"];
  meta: string;
  editHref: string;
}

/**
 * Composant de liste résumée pour les derniers films ou séries sur le tableau de bord studio.
 * 
 * @param props.title - Titre de la section (ex: "Derniers films").
 * @param props.href - Lien pour voir l'ensemble des éléments.
 * @param props.createHref - Lien direct pour initier une nouvelle création.
 * @param props.items - Liste des éléments à lister.
 * @param props.emptyLabel - Libellé alternatif si aucun élément n'existe.
 */
function DashboardList({
  title,
  href,
  createHref,
  items,
  emptyLabel,
}: {
  title: string;
  href: string;
  createHref: string;
  items: DashboardListItem[];
  emptyLabel: string;
}) {
  return (
    <div
      className="p-3 h-100"
      style={{
        background: "var(--cinaf-surface)",
        border: "1px solid var(--cinaf-border)",
        borderRadius: 10,
      }}
    >
      <div className="d-flex align-items-center justify-content-between mb-3">
        <h5 className="mb-0" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
          {title}
        </h5>
        <div className="d-flex gap-2">
          <Link href={createHref} className="btn btn-sm" style={{ background: "var(--cinaf-gold)", color: "#000", fontWeight: 600 }}>
            <i className="bi bi-plus-lg me-1" />
            Nouveau
          </Link>
          <Link href={href} className="btn btn-sm btn-outline-secondary">
            Voir tout
          </Link>
        </div>
      </div>
      {items.length === 0 ? (
        <div className="text-center py-3" style={{ color: "var(--cinaf-text-muted)" }}>
          {emptyLabel}
        </div>
      ) : (
        <ul className="list-unstyled mb-0">
          {items.map((it) => (
            <li
              key={it.id}
              className="d-flex align-items-center gap-2 py-2"
              style={{ borderBottom: "1px solid var(--cinaf-border)" }}
            >
              <Link
                href={it.editHref}
                className="flex-grow-1 text-decoration-none"
                style={{ color: "var(--cinaf-text)" }}
              >
                {it.title}
                <span className="ms-2 small" style={{ color: "var(--cinaf-text-muted)" }}>
                  {it.meta}
                </span>
              </Link>
              <StatusBadge status={it.status} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
