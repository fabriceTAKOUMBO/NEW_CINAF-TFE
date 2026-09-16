/**
 * ============================================================
 * CINAF v2 — Badge de statut générique (StatusBadge)
 * ============================================================
 * Composant d'affichage visuel standardisé pour les statuts d'entités :
 * - Statut de contenu (`ContentStatus`) : DRAFT, PUBLISHED, WITHDRAWN, PENDING_APPROVAL
 * - Statut de demande de retrait (`WithdrawalStatus`) : PENDING, APPROVED, REJECTED
 * 
 * Associe une palette de couleurs contrastée et accessible (fond translucide, texte coloré, bordure)
 * pour identifier l'état d'un coup d'œil dans les tableaux et fiches de gestion.
 */

import type { ContentStatus, WithdrawalStatus } from "@/lib/api";

/** Type d'union regroupant tous les statuts affichables par le composant */
type AnyStatus = ContentStatus | WithdrawalStatus;

/**
 * Propriétés attendues par le composant `StatusBadge`.
 */
interface StatusBadgeProps {
  /** Le code de statut à afficher */
  status: AnyStatus;
}

/**
 * Table de correspondance entre chaque code de statut et son style visuel (fond, texte, bordure, libellé).
 */
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

/**
 * Badge visuel stylisé pour l'affichage des statuts de contenus et demandes de retrait.
 * 
 * @param props - Propriétés du badge contenant le `status`
 * @returns L'élément HTML `<span>` badge stylisé
 */
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
