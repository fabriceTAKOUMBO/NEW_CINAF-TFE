"use client";

// ============================================================
// CINAF v2 — Tuile studio publique (vue "chaîne YouTube")
// ============================================================

import Link from "next/link";
import type { StudioPublic } from "@/lib/api";

interface StudioCardProps {
  studio: StudioPublic;
}

/**
 * Calcule les initiales (1 ou 2 caractères majuscules) d'un nom de studio
 * pour le placeholder du logo lorsqu'aucun `logoUrl` n'est défini.
 * Ex. "Studio Nollywood Lagos" → "SN", "ABC" → "AB".
 */
function studioInitials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return "?";
  if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
  return (words[0][0] + words[1][0]).toUpperCase();
}

/**
 * Tronque proprement une description au plus près d'un nombre de caractères
 * donné, en ajoutant un caractère ellipse (…) si une coupe a été effectuée.
 */
function truncate(text: string, max = 100): string {
  if (text.length <= max) return text;
  return text.slice(0, max).trimEnd() + "…";
}

/**
 * Tuile studio réutilisable :
 *   - Logo carré 1:1 en haut (placeholder doré aux initiales si null).
 *   - Nom du studio + compteur « X films · Y séries ».
 *   - Description tronquée à ~100 caractères.
 *
 * La carte entière est cliquable → `/studios/{slug}`.
 */
export default function StudioCard({ studio }: StudioCardProps) {
  const filmsLabel = `${studio.publishedFilmsCount} film${studio.publishedFilmsCount > 1 ? "s" : ""}`;
  const seriesLabel = `${studio.publishedSeriesCount} série${studio.publishedSeriesCount > 1 ? "s" : ""}`;
  // Pluriel français : 0 et 1 prennent « abonné », 2+ prennent « abonnés ».
  // `toLocaleString('fr-FR')` ajoute l'espace insécable comme séparateur de
  // milliers (ex. 1 234) pour rester aligné avec les conventions typo FR.
  const subscribersLabel = `${studio.subscribersCount.toLocaleString("fr-FR")} ${
    studio.subscribersCount <= 1 ? "abonné" : "abonnés"
  }`;

  return (
    <Link
      href={`/studios/${studio.slug}`}
      className="text-decoration-none d-block h-100"
      style={{ color: "inherit" }}
    >
      <div className="content-card h-100 p-3 d-flex flex-column">
        {/* Logo carré 1:1 — placeholder doré si pas de logoUrl */}
        <div
          style={{
            aspectRatio: "1 / 1",
            width: "100%",
            borderRadius: 8,
            overflow: "hidden",
            background:
              "linear-gradient(135deg, #1a1a1a 0%, #2a2010 60%, #3d2f15 100%)",
            border: "1px solid var(--cinaf-border)",
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            marginBottom: "0.75rem",
          }}
          aria-label={`Logo ${studio.name}`}
        >
          {studio.logoUrl ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={studio.logoUrl}
              alt={studio.name}
              loading="lazy"
              style={{ width: "100%", height: "100%", objectFit: "cover" }}
            />
          ) : (
            <span
              style={{
                fontSize: "clamp(1.8rem, 5vw, 3rem)",
                fontWeight: 800,
                color: "var(--cinaf-gold)",
                letterSpacing: "0.05em",
              }}
            >
              {studioInitials(studio.name)}
            </span>
          )}
        </div>

        {/* Nom + compteur + description */}
        <h6
          className="mb-1 text-truncate"
          style={{ color: "var(--cinaf-text)", fontWeight: 700 }}
          title={studio.name}
        >
          {studio.name}
        </h6>
        <p
          className="mb-1 small"
          style={{ color: "var(--cinaf-gold)", fontSize: "0.8rem" }}
        >
          {filmsLabel} · {seriesLabel}
        </p>
        {/* Compteur d'abonnés sur sa propre ligne (icône cloche+gens dorée). */}
        <p
          className="mb-2 small d-flex align-items-center gap-1"
          style={{ color: "var(--cinaf-gold)", fontSize: "0.8rem" }}
        >
          <i className="bi bi-people-fill" aria-hidden="true" />
          <span>{subscribersLabel}</span>
        </p>
        {studio.description && (
          <p
            className="mb-0 small"
            style={{
              color: "var(--cinaf-text-muted)",
              fontSize: "0.8rem",
              lineHeight: 1.4,
            }}
          >
            {truncate(studio.description, 100)}
          </p>
        )}
      </div>
    </Link>
  );
}
