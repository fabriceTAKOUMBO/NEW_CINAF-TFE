/**
 * ============================================================
 * CINAF v2 — Pastille Studio « Publié par {studio} » (StudioChip)
 * ============================================================
 * Composant de lien discret et élégant inséré dans les bannières d'en-tête
 * des films et séries pour créditer le studio créateur.
 * 
 * Comportement :
 * - Redirige l'utilisateur vers la chaîne publique du studio (`/studios/{slug}`).
 * - Affiche la miniature du logo du studio ou ses initiales en typographie dorée.
 * - Ne s'affiche que si l'œuvre est liée à un studio en base de données.
 */

import Link from "next/link";
import type { DiscoverStudioRef } from "@/lib/api";

/**
 * Propriétés attendues par le composant `StudioChip`.
 */
interface StudioChipProps {
  /** Référence publique vers le studio créateur */
  studio: DiscoverStudioRef;
}

/**
 * Pastille informative cliquable vers la chaîne d'un studio.
 * 
 * @param props - Propriétés contenant la référence du studio
 * @returns Le lien stylisé en forme de badge enrichi
 */
export default function StudioChip({ studio }: StudioChipProps) {
  return (
    <Link
      href={`/studios/${studio.slug}`}
      className="d-inline-flex align-items-center gap-2 px-3 py-2"
      style={{
        color: "var(--cinaf-text)",
        textDecoration: "none",
        background: "var(--cinaf-surface)",
        border: "1px solid var(--cinaf-border)",
        borderRadius: 8,
      }}
    >
      {studio.logoUrl ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={studio.logoUrl}
          alt={studio.name}
          width={32}
          height={32}
          style={{ borderRadius: 6, objectFit: "cover" }}
        />
      ) : (
        <span
          className="d-inline-flex align-items-center justify-content-center"
          style={{
            width: 32,
            height: 32,
            borderRadius: 6,
            background: "var(--cinaf-gold)",
            color: "var(--cinaf-bg)",
            fontWeight: 700,
            fontSize: 14,
          }}
        >
          {studio.name.substring(0, 2).toUpperCase()}
        </span>
      )}
      <span>
        <span style={{ color: "var(--cinaf-text-muted)", fontSize: 13 }}>Publié par</span>{" "}
        <span style={{ fontWeight: 600 }}>{studio.name}</span>
      </span>
      <i className="bi bi-chevron-right ms-1" style={{ color: "var(--cinaf-gold)" }} />
    </Link>
  );
}
