/**
 * ============================================================
 * CINAF v2 — Écran « page introuvable » (NotFoundView)
 * ============================================================
 * Affiché quand une adresse ne mène à rien :
 * - route inexistante : rendu par `app/not-found.tsx`, avec une vraie
 *   réponse HTTP 404 ;
 * - film, série ou studio inconnu : rendu par les pages de fiche et de
 *   lecture quand l'API répond 404 (slug erroné, ancien lien…).
 *
 * Plutôt qu'une impasse, l'écran propose une recherche et des chemins de
 * retour (lien contextuel, accueil, catalogue). Styles : `.error-page*`
 * dans `globals.css`, partagés avec `ContentWithdrawnView`.
 */

import Link from "next/link";
import SearchBar from "./SearchBar";

/**
 * Propriétés attendues par le composant `NotFoundView`.
 */
interface NotFoundViewProps {
  /** Titre principal (défaut : page introuvable générique) */
  title?: string;
  /** Explication affichée sous le titre */
  message?: string;
  /** Lien de retour propre au contexte, ex. « /films » */
  backHref?: string;
  /** Libellé du lien de retour, ex. « Voir tous les films » */
  backLabel?: string;
}

/**
 * Écran 404 aux couleurs de CINAF : code géant, explication, recherche et
 * liens de navigation.
 *
 * @param props - Propriétés du composant
 * @returns La section plein écran de la page introuvable
 */
export default function NotFoundView({
  title = "Cette page est introuvable",
  message = "La page que vous cherchez n'existe pas ou a été déplacée. Vérifiez l'adresse, ou retrouvez vos films et séries depuis le catalogue.",
  backHref,
  backLabel,
}: NotFoundViewProps) {
  const hasContextLink = Boolean(backHref && backLabel);

  return (
    <section className="error-page">
      <div className="error-page-inner">
        <div className="error-page-code" aria-hidden="true">
          404
        </div>
        <p className="error-page-eyebrow">Page introuvable</p>
        <h1 className="error-page-title">{title}</h1>
        <p className="error-page-text">{message}</p>

        <div className="error-page-search">
          <SearchBar />
        </div>

        {/* Le lien contextuel (ex. « Voir tous les films ») passe en premier
            quand il existe ; sinon, l'accueil est l'action principale. */}
        <div className="error-page-actions">
          {hasContextLink && (
            <Link href={backHref as string} className="btn btn-cinaf px-4">
              <i className="bi bi-arrow-left me-1" />
              {backLabel}
            </Link>
          )}
          <Link href="/" className={`btn ${hasContextLink ? "btn-cinaf-outline" : "btn-cinaf"} px-4`}>
            <i className="bi bi-house-door me-1" />
            Retour à l’accueil
          </Link>
          <Link href="/catalogue" className="btn btn-cinaf-outline px-4">
            <i className="bi bi-collection-play me-1" />
            Parcourir le catalogue
          </Link>
        </div>
      </div>
    </section>
  );
}
