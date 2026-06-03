// ============================================================
// CINAF v2 — Pagination Bootstrap styled CINAF
// ============================================================

interface PaginationProps {
  currentPage: number;
  totalItems: number;
  itemsPerPage?: number;
  onPageChange: (page: number) => void;
}

export default function Pagination({
  currentPage,
  totalItems,
  itemsPerPage = 20,
  onPageChange,
}: PaginationProps) {
  // Calcul du nombre total de pages
  const totalPages = Math.max(1, Math.ceil(totalItems / itemsPerPage));

  // Si une seule page ou moins, on n'affiche pas la pagination
  if (totalPages <= 1) return null;

  // Logique pour afficher au maximum 7 numéros de page avec des ellipses si nécessaire
  const pages: (number | "...")[] = [];
  if (totalPages <= 7) {
    // Si peu de pages, on les affiche toutes
    for (let i = 1; i <= totalPages; i++) pages.push(i);
  } else {
    // Toujours afficher la première page
    pages.push(1);
    
    // Ajouter des points de suspension au début si nécessaire
    if (currentPage > 3) pages.push("...");
    
    // Pages autour de la page courante
    const start = Math.max(2, currentPage - 1);
    const end = Math.min(totalPages - 1, currentPage + 1);
    for (let i = start; i <= end; i++) pages.push(i);
    
    // Ajouter des points de suspension à la fin si nécessaire
    if (currentPage < totalPages - 2) pages.push("...");
    
    // Toujours afficher la dernière page
    pages.push(totalPages);
  }

  return (
    <nav aria-label="Navigation des pages" className="d-flex justify-content-center mt-4">
      <ul className="pagination cinaf-pagination">
        {/* Bouton Précédent */}
        <li className={`page-item ${currentPage <= 1 ? "disabled" : ""}`}>
          <button
            className="page-link"
            onClick={() => onPageChange(currentPage - 1)}
            disabled={currentPage <= 1}
          >
            <i className="bi bi-chevron-left" />
          </button>
        </li>
        
        {/* Liste des numéros de page */}
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
        
        {/* Bouton Suivant */}
        <li className={`page-item ${currentPage >= totalPages ? "disabled" : ""}`}>
          <button
            className="page-link"
            onClick={() => onPageChange(currentPage + 1)}
            disabled={currentPage >= totalPages}
          >
            <i className="bi bi-chevron-right" />
          </button>
        </li>
      </ul>
    </nav>
  );
}
