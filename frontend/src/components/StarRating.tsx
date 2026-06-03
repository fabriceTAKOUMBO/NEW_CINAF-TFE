// ============================================================
// CINAF v2 — Affichage note en etoiles (lecture seule)
// ============================================================

interface StarRatingProps {
  rating: number; // 0 a 5
  size?: string;
}

export default function StarRating({ rating, size = "0.85rem" }: StarRatingProps) {
  const stars = [];
  // On s'assure que la note est comprise entre 0 et 5
  const clamped = Math.max(0, Math.min(5, rating));
  // Nombre d'étoiles pleines
  const full = Math.floor(clamped);
  // Presence d'une demi-étoile
  const half = clamped - full >= 0.5;

  // Génération des 5 icônes d'étoiles
  for (let i = 0; i < 5; i++) {
    if (i < full) {
      // Étoile pleine
      stars.push(
        <i
          key={i}
          className="bi bi-star-fill"
          style={{ color: "var(--cinaf-gold)", fontSize: size }}
        />
      );
    } else if (i === full && half) {
      // Demi-étoile
      stars.push(
        <i
          key={i}
          className="bi bi-star-half"
          style={{ color: "var(--cinaf-gold)", fontSize: size }}
        />
      );
    } else {
      // Étoile vide
      stars.push(
        <i
          key={i}
          className="bi bi-star"
          style={{ color: "var(--cinaf-text-muted)", fontSize: size }}
        />
      );
    }
  }

  return (
    <span className="d-inline-flex align-items-center gap-1">
      {stars}
      {/* Affichage de la note numérique à côté des étoiles */}
      <span style={{ fontSize: size, color: "var(--cinaf-text-muted)", marginLeft: 4 }}>
        {clamped.toFixed(1)}
      </span>
    </span>
  );
}
