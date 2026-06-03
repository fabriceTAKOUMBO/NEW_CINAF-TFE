"use client";

// ============================================================
// CINAF v2 — Upload Manager (contexte global)
// Permet aux uploads de continuer en arrière-plan pendant que
// l'utilisateur édite d'autres champs ou navigue entre pages
// du même domaine. Un widget <UploadTray> affiche le statut.
//
// Cycle de vie :
//   1. enqueue(file, target, opts) → renvoie un id de job
//   2. Le manager déclenche studioUploads.upload(...) avec le
//      target (type/id/purpose) et un signal d'abort
//   3. À la fin (success), le manager fait l'auto-PATCH sur
//      l'entité correspondante (champ DB déduit du target)
//   4. opts.onComplete(result) est appelé même si la page qui
//      a déclenché l'upload est démontée
//   5. La page d'édition peut écouter via useUploads() pour
//      rafraîchir son entité dès qu'un upload "matching" finit
// ============================================================

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import {
  studioFilms,
  studioSeries,
  studioUploads,
  type StudioUploadTarget,
  type UploadResult,
} from "@/lib/api";

export type UploadStatus = "uploading" | "done" | "error" | "canceled";

export interface UploadJob {
  id: string;
  fileName: string;
  fileSize: number;
  /** Cible (où uploader + quel champ DB PATCH). */
  target: StudioUploadTarget;
  status: UploadStatus;
  /** 0..100, calculé via xhr.upload.onprogress */
  progress: number;
  /** Résultat backend, présent si status === "done" */
  result?: UploadResult;
  /** Message d'erreur si status === "error" */
  error?: string;
  /** Timestamp de création (ms epoch) */
  startedAt: number;
}

/** Indique si le target attend une image (poster) ou une vidéo. */
export function targetKind(target: StudioUploadTarget): "image" | "video" {
  return target.purpose === "poster" ? "image" : "video";
}

export interface EnqueueOptions {
  /** Callback exécuté après réussite (ignore si page démontée). */
  onComplete?: (result: UploadResult) => void;
  /** Callback en cas d'erreur. */
  onError?: (message: string, statusCode?: number) => void;
}

interface UploadContextValue {
  jobs: UploadJob[];
  enqueue: (file: File, target: StudioUploadTarget, options?: EnqueueOptions) => string;
  dismiss: (jobId: string) => void;
  cancel: (jobId: string) => void;
  clearFinished: () => void;
}

const UploadContext = createContext<UploadContextValue | null>(null);

function makeId(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  return `up_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
}

/**
 * Déduit le champ DB à PATCH à partir du target. Convention :
 *   - purpose=poster   → DB.poster = result.url (URL CDN absolue)
 *   - purpose=trailer  → DB.trailerVideoId = result.path (relatif Bunny)
 *   - purpose=video    → DB.bunnyVideoId   = result.path
 */
async function runAutoPatch(target: StudioUploadTarget, result: UploadResult): Promise<void> {
  if (target.type === "film") {
    if (target.purpose === "poster") {
      await studioFilms.update(target.id, { poster: result.url });
    } else if (target.purpose === "trailer") {
      await studioFilms.update(target.id, { trailerVideoId: result.path });
    } else {
      await studioFilms.update(target.id, { bunnyVideoId: result.path });
    }
    return;
  }
  if (target.type === "serie") {
    if (target.purpose === "poster") {
      await studioSeries.update(target.id, { poster: result.url });
    } else {
      await studioSeries.update(target.id, { trailerVideoId: result.path });
    }
    return;
  }
  // episode : purpose forcément "video"
  await studioSeries.updateEpisode(target.serieId, target.seasonId, target.episodeId, {
    bunnyVideoId: result.path,
  });
}

export function UploadProvider({ children }: { children: ReactNode }) {
  const [jobs, setJobs] = useState<UploadJob[]>([]);
  const abortControllers = useRef<Map<string, AbortController>>(new Map());

  const updateJob = useCallback((id: string, patch: Partial<UploadJob>) => {
    setJobs((current) => current.map((j) => (j.id === id ? { ...j, ...patch } : j)));
  }, []);

  const dismiss = useCallback((jobId: string) => {
    setJobs((current) => current.filter((j) => j.id !== jobId));
    abortControllers.current.delete(jobId);
  }, []);

  const cancel = useCallback(
    (jobId: string) => {
      const controller = abortControllers.current.get(jobId);
      if (controller) controller.abort();
      updateJob(jobId, { status: "canceled" });
    },
    [updateJob],
  );

  const clearFinished = useCallback(() => {
    setJobs((current) => current.filter((j) => j.status === "uploading"));
  }, []);

  const enqueue = useCallback(
    (file: File, target: StudioUploadTarget, options: EnqueueOptions = {}): string => {
      const id = makeId();
      const job: UploadJob = {
        id,
        fileName: file.name,
        fileSize: file.size,
        target,
        status: "uploading",
        progress: 0,
        startedAt: Date.now(),
      };
      setJobs((current) => [job, ...current]);

      const controller = new AbortController();
      abortControllers.current.set(id, controller);

      (async () => {
        try {
          const result = await studioUploads.upload(
            file,
            target,
            (p) => updateJob(id, { progress: p }),
            controller.signal,
          );
          // Auto-PATCH avant de marquer "done" pour que toute page qui
          // refetch sur "done" voie l'état déjà à jour.
          try {
            await runAutoPatch(target, result);
          } catch (patchErr: unknown) {
            const msg =
              (patchErr as { message?: string })?.message ??
              "L'upload a réussi mais l'enregistrement a échoué.";
            updateJob(id, { status: "error", error: msg, result });
            options.onError?.(msg);
            return;
          }
          updateJob(id, { status: "done", progress: 100, result });
          options.onComplete?.(result);
        } catch (err: unknown) {
          const e = err as { message?: string; statusCode?: number };
          if (controller.signal.aborted) {
            updateJob(id, { status: "canceled" });
            return;
          }
          const msg = e.message ?? "Échec de l'upload.";
          updateJob(id, { status: "error", error: msg });
          options.onError?.(msg, e.statusCode);
        } finally {
          abortControllers.current.delete(id);
        }
      })();

      return id;
    },
    [updateJob],
  );

  const value = useMemo<UploadContextValue>(
    () => ({ jobs, enqueue, dismiss, cancel, clearFinished }),
    [jobs, enqueue, dismiss, cancel, clearFinished],
  );

  return <UploadContext.Provider value={value}>{children}</UploadContext.Provider>;
}

export function useUploads(): UploadContextValue {
  const ctx = useContext(UploadContext);
  if (!ctx) {
    throw new Error("useUploads doit être utilisé dans un <UploadProvider>");
  }
  return ctx;
}
