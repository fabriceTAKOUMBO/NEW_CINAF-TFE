/**
 * ============================================================
 * CINAF v2 — Affiche de substitution graphique (PlaceholderPoster)
 * ============================================================
 * Ce composant génère dynamiquement une affiche visuelle élégante aux couleurs
 * de la charte CINAF (fond dégradé noir/or et initiales dorées) lorsqu'aucun
 * fichier image n'est disponible sur le CDN pour une œuvre.
 * 
 * Conception :
 * - Extrait jusqu'à 3 lettres initiales du titre nettoyé pour composer le sigle central.
 * - Affiche le titre complet en petits caractères sous les initiales avec troncature.
 * - Respecte le ratio demandé ("portrait" 2:3, "square" 1:1 ou "wide" 16:9).
 */

/**
 * Propriétés attendues par le composant `PlaceholderPoster`.
 */
interface PlaceholderPosterProps {
  /** Titre de l'œuvre à afficher */
  title: string;
  /** Format de cadrage ("portrait", "square" ou "wide") */
  ratio?: "portrait" | "square" | "wide";
}

/**
 * Affiche générée synthétiquement aux couleurs de la marque CINAF.
 * 
 * @param props - Propriétés du composant
 * @returns Le conteneur visuel stylisé
 */
export default function PlaceholderPoster({
  title,
  ratio = "portrait",
}: PlaceholderPosterProps) {
  const aspect =
    ratio === "wide" ? "16 / 9" : ratio === "square" ? "1 / 1" : "2 / 3";

  // Extraction des initiales du titre (jusqu'à 3 lettres en majuscule)
  const initials = title
    .replace(/[_-]+/g, " ")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 3)
    .map((w) => w[0]?.toUpperCase())
    .join("");

  return (
    <div
      style={{
        aspectRatio: aspect,
        width: "100%",
        background:
          "linear-gradient(135deg, #1a1a1a 0%, #2a2010 60%, #3d2f15 100%)",
        border: "1px solid var(--cinaf-border)",
        borderRadius: 8,
        display: "flex",
        flexDirection: "column",
        alignItems: "center",
        justifyContent: "center",
        color: "var(--cinaf-gold)",
        padding: "0.75rem",
        textAlign: "center",
        overflow: "hidden",
      }}
      aria-label={`Affiche ${title}`}
    >
      {/* Sigle central composé des initiales */}
      <span
        style={{
          fontSize: "clamp(1.5rem, 4vw, 2.6rem)",
          fontWeight: 800,
          letterSpacing: "0.05em",
          opacity: 0.85,
        }}
      >
        {initials || "?"}
      </span>
      {/* Rappel lisible du titre complet */}
      <span
        style={{
          fontSize: "0.75rem",
          marginTop: "0.5rem",
          color: "var(--cinaf-text)",
          opacity: 0.75,
          maxWidth: "90%",
          overflow: "hidden",
          textOverflow: "ellipsis",
          whiteSpace: "nowrap",
        }}
      >
        {title.replace(/_/g, " ")}
      </span>
    </div>
  );
}
