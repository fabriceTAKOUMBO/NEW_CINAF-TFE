// ============================================================
// CINAF v2 — Chip « Publié par {studio} » (vue chaîne YouTube).
// Extrait des pages détail film/série où le même bloc était dupliqué.
// Cliquable vers la chaîne studio publique. Visible uniquement quand
// l'œuvre porte une référence studio (mode catalogue DB).
// ============================================================

import Link from "next/link";
import type { DiscoverStudioRef } from "@/lib/api";

export default function StudioChip({ studio }: { studio: DiscoverStudioRef }) {
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
