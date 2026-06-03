"use client";

// ============================================================
// CINAF v2 — Zone d'upload réutilisable (studio)
// Drag & drop ou click pour sélectionner un fichier. Le fichier
// est délégué au UploadProvider qui gère la progression et
// l'auto-PATCH en arrière-plan via UploadTray. La page peut
// continuer à éditer ses autres champs librement.
//
// La prop `target` détermine à la fois :
//   - le chemin Bunny (studios/{slug}/{film|serie-slug}/...)
//   - le champ DB à PATCH après upload (poster, bunnyVideoId, …)
// ============================================================

import { useCallback, useId, useRef, useState } from "react";
import type { StudioUploadTarget, UploadResult } from "@/lib/api";
import { targetKind, useUploads } from "@/lib/upload-context";

interface UploadDropzoneProps {
  target: StudioUploadTarget;
  /** Callback exécuté après upload réussi (rafraîchit l'état local). */
  onUploaded?: (result: UploadResult) => void;
  accept?: string;
  /** Texte court à afficher au-dessus de la zone. */
  label?: string;
  /** Bouton/preview supplémentaire à droite (ex: aperçu courant). */
  extra?: React.ReactNode;
  /** Désactive la zone (target pas encore prêt, ex: film pas encore créé). */
  disabled?: boolean;
}

const DEFAULT_ACCEPT: Record<"video" | "image", string> = {
  video: "video/mp4,video/quicktime,video/webm",
  image: "image/jpeg,image/png,image/webp",
};

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

  const kind = targetKind(target);

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

  function onInputChange(e: React.ChangeEvent<HTMLInputElement>) {
    const f = e.target.files?.[0];
    if (f) handleFile(f);
    e.target.value = "";
  }

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
