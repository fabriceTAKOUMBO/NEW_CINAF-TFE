"use client";

/**
 * ============================================================
 * CINAF v2 — Zone d'upload réutilisable (UploadDropzone)
 * ============================================================
 * Ce composant permet aux producteurs d'importer des fichiers (affiches ou vidéos)
 * par glisser-déposer (Drag & Drop) ou clic standard.
 * 
 * Fonctionnement découplé et asynchrone :
 * 1. La sélection d'un fichier délègue la tâche au contexte global `useUploads().enqueue()`.
 * 2. L'upload se poursuit en arrière-plan sans bloquer la page ni la navigation.
 * 3. La barre d'état globale `<UploadTray>` prend le relais pour afficher l'avancement.
 * 4. La prop `target` configure la destination BunnyCDN et déclenche le patch automatique en DB.
 * 5. Le callback `onUploaded(result)` permet à la page appelante de rafraîchir son aperçu local.
 */

import { useCallback, useId, useRef, useState } from "react";
import type { StudioUploadTarget, UploadResult } from "@/lib/api";
import { targetKind, useUploads } from "@/lib/upload-context";

/**
 * Propriétés attendues par le composant `UploadDropzone`.
 */
interface UploadDropzoneProps {
  /** Cible d'upload définissant l'entité (film, série, épisode) et le rôle (poster, trailer, video) */
  target: StudioUploadTarget;
  /** Fonction de rappel invoquée lors de la finalisation réussie de l'upload et de son enregistrement */
  onUploaded?: (result: UploadResult) => void;
  /** Chaîne de types MIME acceptés (ex: "image/jpeg,image/png") */
  accept?: string;
  /** Libellé textuel affiché au-dessus de la zone */
  label?: string;
  /** Élément JSX optionnel rendu à droite de la zone (ex: miniature ou aperçu actuel) */
  extra?: React.ReactNode;
  /** Désactive l'interaction (ex: si l'entité parente n'a pas encore été sauvegardée en base) */
  disabled?: boolean;
}

/** Types MIME par défaut acceptés pour les vidéos et les images */
const DEFAULT_ACCEPT: Record<"video" | "image", string> = {
  video: "video/mp4,video/quicktime,video/webm",
  image: "image/jpeg,image/png,image/webp",
};

/**
 * Zone de glisser-déposer pour téléversement de médias avec feedback immédiat.
 * 
 * @param props - Propriétés du composant
 * @returns La zone de drop interactive
 */
export default function UploadDropzone({
  target,
  onUploaded,
  accept,
  label,
  extra,
  disabled = false,
}: UploadDropzoneProps) {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const inputId = useId();
  const { enqueue } = useUploads();
  const [isDragOver, setIsDragOver] = useState(false);
  const [lastFileName, setLastFileName] = useState<string | null>(null);
  const [hint, setHint] = useState<string | null>(null);

  // Détection automatique du type de média (image pour affiche, vidéo pour trailer/film)
  const kind = targetKind(target);

  /**
   * Traite le fichier sélectionné et l'ajoute à la file d'attente d'upload.
   */
  const handleFile = useCallback(
    (file: File) => {
      if (disabled) return;
      setLastFileName(file.name);
      enqueue(file, target, {
        onComplete: (result) => onUploaded?.(result),
      });
      setHint("Upload démarré en arrière-plan. Suivez l'avancement en bas à droite.");
      window.setTimeout(() => setHint(null), 6000);
    },
    [enqueue, target, onUploaded, disabled],
  );

  /**
   * Gestionnaire d'événement de sélection via l'explorateur de fichiers natif.
   */
  function onInputChange(e: React.ChangeEvent<HTMLInputElement>) {
    const f = e.target.files?.[0];
    if (f) handleFile(f);
    e.target.value = "";
  }

  /**
   * Gestionnaire d'événement de dépôt par glisser-déposer.
   */
  function onDrop(e: React.DragEvent<HTMLDivElement>) {
    e.preventDefault();
    setIsDragOver(false);
    if (disabled) return;
    const f = e.dataTransfer.files?.[0];
    if (f) handleFile(f);
  }

  return (
    <div>
      {label && (
        <label
          htmlFor={inputId}
          className="form-label"
          style={{ color: "var(--cinaf-text)", fontWeight: 600 }}
        >
          {label}
        </label>
      )}
      <div className="d-flex gap-3 align-items-stretch">
        <div
          onClick={() => !disabled && inputRef.current?.click()}
          onDragOver={(e) => {
            e.preventDefault();
            if (!disabled) setIsDragOver(true);
          }}
          onDragLeave={() => setIsDragOver(false)}
          onDrop={onDrop}
          role="button"
          tabIndex={disabled ? -1 : 0}
          onKeyDown={(e) => {
            if (disabled) return;
            if (e.key === "Enter" || e.key === " ") inputRef.current?.click();
          }}
          aria-disabled={disabled}
          style={{
            flex: 1,
            background: isDragOver ? "rgba(200,168,75,0.10)" : "var(--cinaf-surface)",
            border: `2px dashed ${isDragOver ? "var(--cinaf-gold)" : "var(--cinaf-border)"}`,
            borderRadius: 8,
            padding: "1.25rem 1rem",
            cursor: disabled ? "not-allowed" : "pointer",
            opacity: disabled ? 0.55 : 1,
            transition: "background 0.15s, border-color 0.15s, opacity 0.15s",
            color: "var(--cinaf-text-muted)",
            textAlign: "center",
          }}
        >
          <i
            className={`bi ${kind === "video" ? "bi-camera-reels" : "bi-image"} d-block mb-1`}
            style={{ fontSize: "1.5rem", color: "var(--cinaf-gold)" }}
          />
          <div style={{ fontWeight: 600, color: "var(--cinaf-text)" }}>
            Cliquez ou glissez un fichier {kind === "video" ? "vidéo" : "image"}
          </div>
          <div className="small mt-1">
            {kind === "video"
              ? "MP4, MOV, WEBM — max 3 Go"
              : "JPG, PNG, WEBP — max 10 Mo"}
          </div>
          {lastFileName && (
            <div className="small mt-2" style={{ color: "var(--cinaf-gold)" }}>
              <i className="bi bi-paperclip me-1" />
              {lastFileName}
            </div>
          )}
          <input
            id={inputId}
            ref={inputRef}
            type="file"
            accept={accept ?? DEFAULT_ACCEPT[kind]}
            onChange={onInputChange}
            disabled={disabled}
            style={{ display: "none" }}
          />
        </div>
        {extra && <div style={{ flex: "0 0 auto" }}>{extra}</div>}
      </div>

      {hint && (
        <div
          className="alert mt-2 mb-0 py-2 small d-flex align-items-center gap-2"
          style={{
            background: "rgba(31,99,55,0.12)",
            color: "#8fd68f",
            border: "1px solid rgba(143,214,143,0.35)",
          }}
        >
          <i className="bi bi-cloud-arrow-up-fill" />
          <span>{hint}</span>
        </div>
      )}
    </div>
  );
}
