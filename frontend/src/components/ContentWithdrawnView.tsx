/**
 * ============================================================
 * CINAF v2 — Écran « contenu retiré » (ContentWithdrawnView)
 * ============================================================
 * Affiché à la place d'une fiche ou d'une page de lecture quand l'API répond
 * 410 Gone : l'œuvre a été publiée sur CINAF puis retirée de la plateforme
 * (demande de son studio validée par un administrateur, ou décision de
 * l'équipe). Le visiteur qui suit un ancien lien (favori, partage, moteur de
 * recherche) comprend ce qui s'est passé au lieu d'un simple « introuvable »,
 * et se voit proposer la suite de sa visite.
 *
 * Seules les œuvres retirées reçoivent un 410 : un brouillon ou un contenu en
 * attente d'approbation, jamais public, reste un 404 (`NotFoundView`).
 */

import Link from "next/link";
import type { ApiError } from "@/lib/api";

/** Type d'œuvre, qui détermine les accords du texte et le lien de retour. */
export type WithdrawnKind = "film" | "serie";

/** Informations lues dans la réponse 410 de l'API. */
export interface WithdrawnInfo {
  kind: WithdrawnKind;
  /** Titre de l'œuvre, ou null s'il n'est pas fourni */
  title: string | null;
}

/**
 * Reconnaît une erreur d'API « œuvre retirée » (HTTP 410) et en extrait le
 * type et le titre (`{status, kind, title}` renvoyé par
 * `GET /api/catalogue/discover/{slug}`).
 *
 * @param err          - Erreur levée par un appel du client `lib/api.ts`
 * @param fallbackKind - Type à retenir si la réponse ne le précise pas (celui de la page)
 * @returns Les informations de l'œuvre retirée, ou null si l'erreur n'est pas un 410
 */
export function withdrawnInfoFrom(err: unknown, fallbackKind: WithdrawnKind): WithdrawnInfo | null {
  const apiError = err as Partial<ApiError> | null | undefined;
  if (apiError?.statusCode !== 410) return null;

  const kind = apiError.data?.kind;
  const title = apiError.data?.title;
  return {
    kind: kind === "film" || kind === "serie" ? kind : fallbackKind,
    // Les titres importés de Bunny séparent les mots par « _ ».
    title: typeof title === "string" && title.trim() !== "" ? title.replace(/_/g, " ") : null,
  };
}

/**
 * Écran « contenu retiré » : explication, puis liens vers les autres films
 * ou séries, le catalogue et l'accueil.
 *
 * @param props - Type et titre de l'œuvre retirée
 * @returns La section plein écran du contenu retiré
 */
export default function ContentWithdrawnView({ kind, title }: WithdrawnInfo) {
  const isSerie = kind === "serie";

  return (
    <section className="error-page">
      <div className="error-page-inner">
        <i className="bi bi-camera-video-off error-page-icon" aria-hidden="true" />
        <p className="error-page-eyebrow">Contenu retiré</p>
        <h1 className="error-page-title">
          {title ? `« ${title} » n’est plus disponible` : "Ce contenu n’est plus disponible"}
        </h1>
        <p className="error-page-text">
          {isSerie
            ? "Cette série a été retirée de la plateforme CINAF, à la demande de son studio ou par décision de notre équipe. Elle ne peut plus être regardée."
            : "Ce film a été retiré de la plateforme CINAF, à la demande de son studio ou par décision de notre équipe. Il ne peut plus être regardé."}{" "}
          D’autres films et séries vous attendent dans le catalogue.
        </p>

        <div className="error-page-actions">
          <Link href={isSerie ? "/series" : "/films"} className="btn btn-cinaf px-4">
            <i className={`bi ${isSerie ? "bi-tv" : "bi-film"} me-1`} />
            {isSerie ? "Voir les séries" : "Voir les films"}
          </Link>
          <Link href="/catalogue" className="btn btn-cinaf-outline px-4">
            <i className="bi bi-collection-play me-1" />
            Parcourir le catalogue
          </Link>
          <Link href="/" className="btn btn-cinaf-outline px-4">
            <i className="bi bi-house-door me-1" />
            Accueil
          </Link>
        </div>
      </div>
    </section>
  );
}
