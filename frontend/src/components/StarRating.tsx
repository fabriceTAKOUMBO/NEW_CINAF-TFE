/**
 * ============================================================
 * CINAF v2 — Affichage de note en étoiles (StarRating)
 * ============================================================
 * Composant de présentation visuelle de score d'évaluation sur 5 étoiles (lecture seule).
 * 
 * Logique de découpage :
 * - Borne la valeur entre 0 et 5 (`Math.max(0, Math.min(5, rating))`).
 * - Calcule les étoiles pleines (`bi-star-fill` dorées).
 * - Calcule l'éventuelle demi-étoile (`bi-star-half` dorée si le reste décimal est >= 0.5).
 * - Complète avec des étoiles vides (`bi-star` grisées).
 * - Affiche la note numérique formatée à 1 décimale à droite.
 */

/**
 * Propriétés attendues par le composant `StarRating`.
 */
interface StarRatingProps {
  /** Note sur une échelle de 0 à 5 */
  rating: number;
  /** Taille des étoiles et du texte en unité CSS (défaut: "0.85rem") */
  size?: string;
}

/**
 * Afficheur d'évaluation sous forme d'étoiles dorées.
 * 
 * @param props - Propriétés du composant (`rating`, `size`)
 * @returns La rangée d'étoiles avec la note numérique
 */
export default function StarRating({ rating, size = "0.85rem" }: StarRatingProps) {
  const stars = [];
  // Sécurisation de la note entre 0 et 5
  const clamped = Math.max(0, Math.min(5, rating));
  // Nombre d'étoiles pleines
  const full = Math.floor(clamped);
  // Détection de demi-étoile
  const half = clamped - full >= 0.5;

  // Génération des 5 étoiles
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
      {/* Note numérique décimale affichée à côté */}
      <span style={{ fontSize: size, color: "var(--cinaf-text-muted)", marginLeft: 4 }}>
        {clamped.toFixed(1)}
      </span>
    </span>
  );
}
