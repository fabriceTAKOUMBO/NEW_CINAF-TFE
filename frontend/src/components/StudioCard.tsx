"use client";

/**
 * ============================================================
 * CINAF v2 — Tuile de studio public (StudioCard)
 * ============================================================
 * Carte interactive représentant un studio de production cinématographique
 * (équivalent d'une fiche de chaîne de créateur style YouTube).
 * 
 * Conception et éléments visuels :
 * - Logo carré 1:1 : Affiche l'image de marque du studio (`logoUrl`), ou un placeholder
 *   doré arborant les initiales calculées dynamiquement (`studioInitials`).
 * - Métadonnées de contenu : Compteurs de films et de séries publiés.
 * - Compteur d'abonnés : Affiche le nombre de personnes suivant la chaîne (follow gratuit)
 *   formaté selon les règles typographiques françaises (séparateur d'espace insécable).
 * - Description : Texte de présentation tronqué avec ellipse de courtoisie.
 * - Navigation : Toute la carte est cliquable et mène vers la page chaîne `/studios/{slug}`.
 */

import Link from "next/link";
import type { StudioPublic } from "@/lib/api";

/**
 * Propriétés attendues par le composant `StudioCard`.
 */
interface StudioCardProps {
  /** Les données publiques du studio à afficher */
  studio: StudioPublic;
}

/**
 * Calcule les initiales (1 ou 2 caractères majuscules) à partir du nom d'un studio
 * afin de générer un avatar textuel harmonieux lorsque aucun logo n'est téléversé.
 * 
 * @param name - Le nom complet du studio (ex: "Studio Nollywood Lagos")
 * @returns Les initiales en majuscules (ex: "SN")
 */
function studioInitials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return "?";
  if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
  return (words[0][0] + words[1][0]).toUpperCase();
}

/**
 * Tronque proprement une chaîne de texte au seuil spécifié en ajoutant une ellipse (…).
 * 
 * @param text - Le texte d'origine
 * @param max - Le nombre maximal de caractères souhaité (défaut: 100)
 * @returns La chaîne tronquée
 */
function truncate(text: string, max = 100): string {
  if (text.length <= max) return text;
  return text.slice(0, max).trimEnd() + "…";
}

/**
 * Composant de carte studio pour les grilles d'exploration publique.
 * 
 * @param props - Propriétés du composant
 * @returns La carte cliquable stylisée
 */
export default function StudioCard({ studio }: StudioCardProps) {
  const filmsLabel = `${studio.publishedFilmsCount} film${studio.publishedFilmsCount > 1 ? "s" : ""}`;
  const seriesLabel = `${studio.publishedSeriesCount} série${studio.publishedSeriesCount > 1 ? "s" : ""}`;
  
  // Pluriel français : 0 et 1 prennent « abonné », 2+ prennent « abonnés ».
  // `toLocaleString('fr-FR')` ajoute l'espace insécable comme séparateur de
  // milliers (ex. 1 234) pour respecter les conventions typographiques françaises.
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
        {/* Logo carré 1:1 avec placeholder doré calculé sur les initiales si absent */}
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

        {/* Nom du studio, volume de production et communauté */}
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
        {/* Compteur d'abonnés avec icône communautaire */}
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
