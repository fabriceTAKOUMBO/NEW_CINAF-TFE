"use client";

// ============================================================
// CINAF v2 — Modal bande-annonce (Bootstrap modal + BunnyPlayer)
// ============================================================

import { useEffect, useRef } from "react";
import BunnyPlayer from "./BunnyPlayer";

interface TrailerModalProps {
  trailerBunnyId: string;
  show: boolean;
  onClose: () => void;
}

export default function TrailerModal({
  trailerBunnyId,
  show,
  onClose,
}: TrailerModalProps) {
  // Référence vers l'élément DOM de la modale
  const modalRef = useRef<HTMLDivElement>(null);

  // Gestion du cycle de vie de la modale Bootstrap
  useEffect(() => {
    if (typeof window === "undefined") return;

    let bsModal: { show: () => void; hide: () => void; dispose: () => void } | null = null;

    // Chargement dynamique de Bootstrap
    import("bootstrap/dist/js/bootstrap.bundle.min.js").then((bs) => {
      if (!modalRef.current) return;
      
      // Instanciation de la modale Bootstrap sur l'élément DOM
      bsModal = new bs.Modal(modalRef.current, { backdrop: true, keyboard: true });

      // Synchronisation de l'événement de fermeture "physique" avec l'état React
      modalRef.current?.addEventListener("hidden.bs.modal", onClose);

      // Si la prop 'show' change, on déclenche l'affichage
      if (show && bsModal) {
        bsModal.show();
      }
    });

    // Nettoyage au démontage pour éviter les fuites de mémoire et les doublons d'instances
    return () => {
      if (bsModal) {
        try {
          bsModal.dispose();
        } catch {
          // ignore
        }
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [show]);

  return (
    <div
      className="modal fade"
      ref={modalRef}
      tabIndex={-1}
      aria-hidden="true"
    >
      <div className="modal-dialog modal-dialog-centered modal-lg">
        <div
          className="modal-content"
          style={{
            backgroundColor: "#000",
            border: "1px solid var(--cinaf-border)",
          }}
        >
          <div
            className="modal-header border-0"
            style={{ padding: "0.75rem 1rem" }}
          >
            <h6 className="modal-title" style={{ color: "var(--cinaf-text)" }}>
              <i className="bi bi-play-circle me-2" style={{ color: "var(--cinaf-gold)" }} />
              Bande-annonce
            </h6>
            <button
              type="button"
              className="btn-close btn-close-white"
              data-bs-dismiss="modal"
              aria-label="Fermer"
            />
          </div>
          <div className="modal-body p-0">
            {show && trailerBunnyId && (
              <BunnyPlayer videoId={trailerBunnyId} autoplay />
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
