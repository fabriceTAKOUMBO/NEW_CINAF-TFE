// ============================================================
// CINAF v2 — Affiche placeholder dorée (avant que les vrais
// posters soient uploadés sur Bunny pour chaque œuvre).
// ============================================================

interface PlaceholderPosterProps {
  title: string;
  /** "portrait" (2/3) ou "square" (1/1) ou "wide" (16/9). */
  ratio?: "portrait" | "square" | "wide";
}

export default function PlaceholderPoster({
  title,
  ratio = "portrait",
}: PlaceholderPosterProps) {
  const aspect =
    ratio === "wide" ? "16 / 9" : ratio === "square" ? "1 / 1" : "2 / 3";

  // Initiales (max 3 caractères) en grand au centre
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
