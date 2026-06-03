"use client";

// ============================================================
// CINAF v2 — Card pour une œuvre du catalogue Bunny
// ============================================================

import Link from "next/link";
import type { DiscoverWorkSummary } from "@/lib/api";
import PlaceholderPoster from "./PlaceholderPoster";

interface WorkCardProps {
  work: DiscoverWorkSummary;
  /** Force la destination si on ne veut pas que kind décide. */
  hrefOverride?: string;
}

export default function WorkCard({ work, hrefOverride }: WorkCardProps) {
  const href = hrefOverride ?? routeFor(work);

  return (
    <Link href={href} className="d-block text-decoration-none" style={{ color: "inherit" }}>
      <div style={{ position: "relative" }}>
        <PlaceholderPoster title={work.title} ratio="portrait" />
        <span
          style={{
            position: "absolute",
            top: 6,
            right: 6,
            background: "rgba(0,0,0,0.7)",
            color: work.kind === "serie" ? "#8ad" : "var(--cinaf-gold)",
            fontSize: "0.65rem",
            padding: "2px 8px",
            borderRadius: 12,
            fontWeight: 700,
            textTransform: "uppercase",
            letterSpacing: "0.05em",
          }}
        >
          {work.kind === "serie" ? "Série" : "Film"}
        </span>
      </div>
      <p
        className="mt-2 mb-0 small text-truncate"
        style={{ color: "var(--cinaf-text)" }}
        title={work.title}
      >
        {work.title.replace(/_/g, " ")}
      </p>
    </Link>
  );
}

export function routeFor(work: DiscoverWorkSummary): string {
  return work.kind === "serie" ? `/series/${work.slug}` : `/films/${work.slug}`;
}
