/**
 * ============================================================
 * CINAF v2 — Composant de Pagination (Pagination)
 * ============================================================
 * Barre de pagination responsive stylisée aux couleurs sombres et dorées de CINAF.
 * 
 * Algorithme d'affichage intelligent :
 * - Si le nombre total de pages est <= 1, le composant ne rend rien.
 * - Si le nombre total de pages est <= 7, tous les numéros de page sont affichés.
 * - Si le nombre total de pages est > 7, un système d'ellipses ("...") est généré :
 *   - Toujours la page 1.
 *   - Une ellipse si la page courante est > 3.
 *   - La page courante ainsi que ses voisines immédiates (page - 1, page + 1).
 *   - Une ellipse si la page courante est < totalPages - 2.
 *   - Toujours la dernière page.
 * - Boutons Précédent / Suivant désactivés aux extrémités.
 */

/**
 * Propriétés attendues par le composant `Pagination`.
 */
interface PaginationProps {
  /** Numéro de la page actuellement affichée (index 1) */
  currentPage: number;
  /** Nombre total d'éléments dans la collection */
  totalItems: number;
  /** Nombre d'éléments affichés par page (défaut: 20) */
  itemsPerPage?: number;
  /** Callback exécuté lors de la sélection d'une nouvelle page */
  onPageChange: (page: number) => void;
}

/**
 * Composant de pagination avec réduction intelligente par ellipses.
 * 
 * @param props - Propriétés du composant
 * @returns La barre de navigation pagination Bootstrap stylisée
 */
export default function Pagination({
  currentPage,
  totalItems,
  itemsPerPage = 20,
  onPageChange,
}: PaginationProps) {
  // Calcul du nombre total de pages
  const totalPages = Math.max(1, Math.ceil(totalItems / itemsPerPage));

  // Si une seule page ou moins, la pagination est inutile
  if (totalPages <= 1) return null;

  // Calcul de la liste ordonnée des numéros et séparateurs
  const pages: (number | "...")[] = [];
  if (totalPages <= 7) {
    for (let i = 1; i <= totalPages; i++) pages.push(i);
  } else {
    pages.push(1);
    
    if (currentPage > 3) pages.push("...");
    
    const start = Math.max(2, currentPage - 1);
    const end = Math.min(totalPages - 1, currentPage + 1);
    for (let i = start; i <= end; i++) pages.push(i);
    
    if (currentPage < totalPages - 2) pages.push("...");
    
    pages.push(totalPages);
  }

  return (
    <nav aria-label="Navigation des pages" className="d-flex justify-content-center mt-4">
      <ul className="pagination cinaf-pagination">
        {/* Bouton Page précédente */}
        <li className={`page-item ${currentPage <= 1 ? "disabled" : ""}`}>
          <button
            className="page-link"
            onClick={() => onPageChange(currentPage - 1)}
            disabled={currentPage <= 1}
            aria-label="Page précédente"
          >
            <i className="bi bi-chevron-left" />
          </button>
        </li>
        
        {/* Numéros de page et ellipses */}
        {pages.map((p, i) =>
          p === "..." ? (
            <li key={`ellipsis-${i}`} className="page-item disabled">
              <span className="page-link">...</span>
            </li>
          ) : (
            <li key={p} className={`page-item ${p === currentPage ? "active" : ""}`}>
              <button className="page-link" onClick={() => onPageChange(p)}>
                {p}
              </button>
            </li>
          )
        )}
        
        {/* Bouton Page suivante */}
        <li className={`page-item ${currentPage >= totalPages ? "disabled" : ""}`}>
          <button
            className="page-link"
            onClick={() => onPageChange(currentPage + 1)}
            disabled={currentPage >= totalPages}
            aria-label="Page suivante"
          >
            <i className="bi bi-chevron-right" />
          </button>
        </li>
      </ul>
    </nav>
  );
}
