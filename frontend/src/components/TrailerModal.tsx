"use client";

/**
 * ============================================================
 * CINAF v2 — Pop-up de bande-annonce (TrailerModal)
 * ============================================================
 * Fenêtre modale dédiée à la lecture de la bande-annonce d'une œuvre, ouverte
 * depuis le hero des fiches films et séries (`WorkDetailHero`). La
 * bande-annonce est un manifeste HLS Bunny (`DiscoverWork.trailerUrl`), lu par
 * le même lecteur que les vidéos (`HlsPlayer`).
 *
 * Choix de conception :
 * - Modale pilotée par React, sans le JavaScript de Bootstrap : l'état `open`
 *   du parent est l'unique source de vérité, aucune désynchronisation possible
 *   avec une instance `bootstrap.Modal`. Seules les classes CSS `.modal*` de
 *   Bootstrap sont réutilisées (même approche que `WithdrawalDialog`).
 * - Rendue dans `document.body` via un portail : elle passe toujours au-dessus
 *   de la navbar sticky, quel que soit le conteneur qui l'appelle.
 * - Arrêt garanti à la fermeture : le lecteur n'existe que tant que la modale
 *   est ouverte. Fermer la modale démonte `HlsPlayer`, qui détruit son
 *   instance hls.js, met la vidéo en pause et libère sa source : plus de son
 *   ni de téléchargement de segments en arrière-plan.
 * - Fermeture par le bouton ✕, la touche Échap ou un clic hors du lecteur.
 *   Pendant l'ouverture, le défilement de la page est bloqué et le focus
 *   clavier reste dans la modale ; à la fermeture, le focus revient sur le
 *   bouton qui a ouvert la modale.
 */

import { useEffect, useRef } from "react";
import { createPortal } from "react-dom";
import HlsPlayer from "./HlsPlayer";

/**
 * Propriétés attendues par le composant `TrailerModal`.
 */
interface TrailerModalProps {
  /** État d'affichage de la modale (true = ouverte) */
  open: boolean;
  /** URL du manifeste HLS de la bande-annonce */
  src: string;
  /** Titre lisible de l'œuvre, affiché dans l'en-tête de la modale */
  title: string;
  /** Image affichée par le lecteur avant le démarrage de la lecture */
  poster?: string;
  /** Fonction de rappel invoquée pour fermer la modale */
  onClose: () => void;
}

/**
 * Pop-up de lecture de la bande-annonce d'une œuvre.
 *
 * @param props - Propriétés de la modale
 * @returns La modale rendue dans `document.body`, ou `null` si elle est fermée
 */
export default function TrailerModal({ open, src, title, poster, onClose }: TrailerModalProps) {
  const portalRootRef = useRef<HTMLDivElement>(null);
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  // Vrai si le dernier appui de souris a commencé sur le fond de la modale.
  const pressStartedOnOverlay = useRef(false);

  // Ouverture : bloque le défilement de la page, place le focus sur ✕ et l'y
  // retient. Fermeture (ou démontage de la page) : tout est rétabli et le
  // focus revient à l'élément qui avait ouvert la modale.
  useEffect(() => {
    if (!open) return;
    const previouslyFocused = document.activeElement as HTMLElement | null;

    // Blocage sur <html> et non sur <body> : globals.css pose
    // `overflow-x: hidden` sur les deux, ce qui fait de <html> le conteneur
    // qui défile — un `overflow: hidden` sur <body> ne bloquerait rien. La
    // largeur de la barre de défilement masquée est compensée pour que la
    // page ne se décale pas derrière le fond.
    const root = document.documentElement;
    const previousOverflow = root.style.overflow;
    const previousPaddingRight = root.style.paddingRight;
    const scrollbarWidth = window.innerWidth - root.clientWidth;
    root.style.overflow = "hidden";
    if (scrollbarWidth > 0) root.style.paddingRight = `${scrollbarWidth}px`;

    // Le reste de la page devient inerte (ni focus, ni clic, ignoré des
    // lecteurs d'écran) : la tabulation ne peut plus quitter la modale. Un
    // simple renvoi du focus ne suffit pas : le navigateur ferait d'abord
    // défiler la page masquée jusqu'à l'élément extérieur visité.
    const inerted = Array.from(document.body.children).filter(
      (el) => el !== portalRootRef.current && !el.hasAttribute("inert"),
    );
    inerted.forEach((el) => el.setAttribute("inert", ""));

    closeButtonRef.current?.focus();

    return () => {
      // `inert` d'abord retiré : un élément inerte ne peut pas recevoir le focus.
      inerted.forEach((el) => el.removeAttribute("inert"));
      root.style.overflow = previousOverflow;
      root.style.paddingRight = previousPaddingRight;
      previouslyFocused?.focus();
    };
  }, [open]);

  // Touche Échap. Effet séparé : `onClose` peut changer d'identité à chaque
  // rendu du parent sans relancer le blocage du défilement ni le focus.
  useEffect(() => {
    if (!open) return;
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [open, onClose]);

  if (!open) return null;

  return createPortal(
    // Conteneur unique : c'est le seul enfant de <body> laissé actif.
    <div ref={portalRootRef}>
      {/* Fond obscurci, plus opaque qu'un dialogue classique (ambiance salle) */}
      <div className="modal-backdrop fade show" style={{ opacity: 0.85 }} />

      {/* Un clic ne ferme la modale que s'il commence ET finit sur le fond :
          glisser la barre de progression de la vidéo puis relâcher en dehors
          ne doit pas fermer la bande-annonce. */}
      <div
        className="modal fade show d-block"
        role="dialog"
        aria-modal="true"
        aria-labelledby="trailer-modal-title"
        tabIndex={-1}
        onMouseDown={(e) => {
          pressStartedOnOverlay.current = e.target === e.currentTarget;
        }}
        onClick={(e) => {
          if (pressStartedOnOverlay.current && e.target === e.currentTarget) onClose();
        }}
      >
        {/* Largeur bornée par la hauteur de l'écran : la vidéo 16:9 et son
            en-tête restent entièrement visibles, même sur un écran bas. */}
        <div
          className="modal-dialog modal-dialog-centered modal-xl"
          style={{ maxWidth: "min(1140px, calc((100vh - 9rem) * 16 / 9))" }}
        >
          <div className="modal-content">
            <div className="modal-header border-0 py-2">
              <h5 id="trailer-modal-title" className="modal-title">
                <i className="bi bi-film me-2" style={{ color: "var(--cinaf-gold)" }} />
                Bande-annonce — {title}
              </h5>
              <button
                ref={closeButtonRef}
                type="button"
                className="btn-close btn-close-white"
                aria-label="Fermer la bande-annonce"
                onClick={onClose}
              />
            </div>
            <div className="modal-body p-0">
              <HlsPlayer src={src} autoplay poster={poster} />
            </div>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}
