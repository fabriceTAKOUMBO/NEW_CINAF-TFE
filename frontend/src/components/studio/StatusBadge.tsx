// ============================================================
// CINAF v2 — Badge de statut générique
// Utilisé pour afficher le statut d'un contenu (DRAFT/PUBLISHED/
// WITHDRAWN) ou d'une demande de retrait (PENDING/APPROVED/REJECTED).
// ============================================================

import type { ContentStatus, WithdrawalStatus } from "@/lib/api";

type AnyStatus = ContentStatus | WithdrawalStatus;

interface StatusBadgeProps {
  status: AnyStatus;
}

const COLOR_MAP: Record<
  AnyStatus,
  { bg: string; fg: string; border: string; label: string }
> = {
  DRAFT: { bg: "rgba(108,117,125,0.15)", fg: "#bfc7cf", border: "rgba(108,117,125,0.4)", label: "Brouillon" },
  PUBLISHED: { bg: "rgba(31,99,55,0.18)", fg: "#8fd68f", border: "rgba(143,214,143,0.4)", label: "Publié" },
  WITHDRAWN: { bg: "rgba(122,32,32,0.20)", fg: "#ff8a8a", border: "rgba(255,138,138,0.4)", label: "Retiré" },
  PENDING_APPROVAL: { bg: "rgba(160,120,48,0.20)", fg: "#f0c080", border: "rgba(240,192,128,0.4)", label: "En attente d'approbation" },
  PENDING: { bg: "rgba(160,120,48,0.20)", fg: "#f0c080", border: "rgba(240,192,128,0.4)", label: "En attente" },
  APPROVED: { bg: "rgba(31,99,55,0.18)", fg: "#8fd68f", border: "rgba(143,214,143,0.4)", label: "Approuvée" },
  REJECTED: { bg: "rgba(122,32,32,0.20)", fg: "#ff8a8a", border: "rgba(255,138,138,0.4)", label: "Rejetée" },
};

export default function StatusBadge({ status }: StatusBadgeProps) {
  const c = COLOR_MAP[status];
  return (
    <span
      className="badge"
      style={{
        background: c.bg,
        color: c.fg,
        border: `1px solid ${c.border}`,
        fontSize: "0.7rem",
        fontWeight: 600,
        letterSpacing: "0.03em",
      }}
    >
      {c.label}
    </span>
  );
}
