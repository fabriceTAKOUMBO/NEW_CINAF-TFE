"use client";

/**
 * ============================================================
 * CINAF v2 — Upload Manager (Contexte global d'upload d'actifs)
 * ============================================================
 * Permet aux téléversements de fichiers médias (affiches, bandes-annonces, vidéos complètes)
 * de continuer en arrière-plan sans bloquer l'interface utilisateur lorsque le créateur
 * navigue ou édite d'autres informations dans le Studio.
 * 
 * Architecture et Cycle de vie d'un téléversement :
 * 1. `enqueue(file, target, opts)` : Génère un identifiant unique de tâche et insère le travail dans la file.
 * 2. Déclenchement réseau via XMLHttpRequest (XHR) via `studioUploads.upload(...)`, permettant :
 *    - Un suivi précis de la progression (`xhr.upload.onprogress`) de 0 à 100%.
 *    - L'annulation propre à tout moment via un `AbortController`.
 * 3. En cas de succès d'upload vers Bunny Storage :
 *    - La fonction `runAutoPatch` met automatiquement à jour l'entité correspondante en base de données
 *      (ex: URL du poster, chemin relatif du trailer ou de la vidéo de film / épisode).
 * 4. Notification des callbacks :
 *    - `opts.onComplete(result)` ou `opts.onError(msg)` sont invoqués même si le composant à l'origine
 *      de l'upload a été démonté entre-temps.
 * 5. Le composant d'interface `<UploadTray>` affiche la barre d'état globale rétractable.
 */

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

/** Statut d'avancement d'un téléversement */
export type UploadStatus = "uploading" | "done" | "error" | "canceled";

/**
 * Représente un travail de téléversement en cours ou terminé.
 */
export interface UploadJob {
  /** Identifiant unique du travail généré par `makeId()` */
  id: string;
  /** Nom du fichier d'origine */
  fileName: string;
  /** Taille totale en octets */
  fileSize: number;
  /** Cible du média (type d'entité, identifiant, usage: poster/trailer/video) */
  target: StudioUploadTarget;
  /** Statut courant du traitement */
  status: UploadStatus;
  /** Pourcentage d'avancement de 0 à 100 */
  progress: number;
  /** Résultat renvoyé par le serveur BunnyCDN en cas de succès */
  result?: UploadResult;
  /** Message explicatif en cas d'échec */
  error?: string;
  /** Horodatage du lancement en millisecondes */
  startedAt: number;
}

/**
 * Détermine si la cible attendue est une image (affiche) ou une vidéo (film, épisode, bande-annonce).
 * 
 * @param target - La cible d'upload définie
 * @returns "image" si purpose est 'poster', sinon "video"
 */
export function targetKind(target: StudioUploadTarget): "image" | "video" {
  return target.purpose === "poster" ? "image" : "video";
}

/**
 * Options et callbacks optionnels passés lors de la mise en file d'un upload.
 */
export interface EnqueueOptions {
  /** Fonction appelée lors de la réussite du téléversement et du patch automatique */
  onComplete?: (result: UploadResult) => void;
  /** Fonction appelée en cas d'échec ou d'erreur réseau/serveur */
  onError?: (message: string, statusCode?: number) => void;
}

/**
 * Valeurs et actions exposées par le contexte `UploadContext`.
 */
interface UploadContextValue {
  /** Liste de tous les travaux d'upload (actifs ou terminés) */
  jobs: UploadJob[];
  /** Ajoute un nouveau fichier à la file d'upload et démarre le téléversement */
  enqueue: (file: File, target: StudioUploadTarget, options?: EnqueueOptions) => string;
  /** Supprime un travail de la liste d'affichage */
  dismiss: (jobId: string) => void;
  /** Interrompt un téléversement en cours via son AbortController */
  cancel: (jobId: string) => void;
  /** Supprime toutes les tâches terminées ou en erreur de la liste */
  clearFinished: () => void;
}

const UploadContext = createContext<UploadContextValue | null>(null);

/**
 * Génère un identifiant unique aléatoire compatible avec tous les environnements de navigation.
 * 
 * @returns Une chaîne d'identifiant unique
 */
function makeId(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  return `up_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
}

/**
 * Met à jour automatiquement l'entité concernée côté backend Symfony (via requête PATCH)
 * dès que le fichier est téléversé avec succès sur BunnyCDN.
 * 
 * Règles d'affectation des champs :
 * - `purpose = "poster"`   → Met à jour le champ `poster` avec l'URL publique CDN absolue (`result.url`).
 * - `purpose = "trailer"`  → Met à jour le champ `trailerVideoId` avec le chemin relatif Bunny (`result.path`).
 * - `purpose = "video"`    → Met à jour le champ `bunnyVideoId` avec le chemin relatif Bunny (`result.path`).
 * 
 * @param target - Informations sur l'entité ciblée (film, série ou épisode)
 * @param result - Informations retournées par le serveur d'upload
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
  // Cas d'un épisode de série (purpose nécessairement "video")
  await studioSeries.updateEpisode(target.serieId, target.seasonId, target.episodeId, {
    bunnyVideoId: result.path,
  });
}

/**
 * Fournisseur React gérant le cycle de vie de l'ensemble des téléversements de médias.
 * Maintient la liste des jobs, les instances `AbortController`, et coordonne les requêtes XHR.
 * 
 * @param children - Éléments enfants enveloppés
 */
export function UploadProvider({ children }: { children: ReactNode }) {
  // Liste ordonnée de tous les téléversements
  const [jobs, setJobs] = useState<UploadJob[]>([]);
  // Dictionnaire de contrôleurs d'annulation indexé par l'ID du job
  const abortControllers = useRef<Map<string, AbortController>>(new Map());

  /**
   * Met à jour partiellement l'état d'un job donné.
   */
  const updateJob = useCallback((id: string, patch: Partial<UploadJob>) => {
    setJobs((current) => current.map((j) => (j.id === id ? { ...j, ...patch } : j)));
  }, []);

  /**
   * Retire un job de la liste et supprime son contrôleur d'annulation s'il existe.
   */
  const dismiss = useCallback((jobId: string) => {
    setJobs((current) => current.filter((j) => j.id !== jobId));
    abortControllers.current.delete(jobId);
  }, []);

  /**
   * Interrompt immédiatement la requête réseau d'un job actif et le marque comme annulé.
   */
  const cancel = useCallback(
    (jobId: string) => {
      const controller = abortControllers.current.get(jobId);
      if (controller) controller.abort();
      updateJob(jobId, { status: "canceled" });
    },
    [updateJob],
  );

  /**
   * Nettoie la file en retirant tous les jobs qui ne sont plus en cours de transfert (done, error, canceled).
   */
  const clearFinished = useCallback(() => {
    setJobs((current) => current.filter((j) => j.status === "uploading"));
  }, []);

  /**
   * Met en file d'attente un fichier à téléverser et démarre immédiatement le transfert asynchrone.
   * 
   * @param file - Le fichier natif sélectionné par l'utilisateur
   * @param target - La cible (film, série, épisode) et l'usage (poster, trailer, video)
   * @param options - Callbacks de suivi (onComplete, onError)
   * @returns L'identifiant unique généré pour ce travail
   */
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

      // Contrôleur pour permettre l'annulation via AbortSignal
      const controller = new AbortController();
      abortControllers.current.set(id, controller);

      (async () => {
        try {
          // Étape 1 : Téléversement du binaire vers le backend/BunnyCDN
          const result = await studioUploads.upload(
            file,
            target,
            (p) => updateJob(id, { progress: p }),
            controller.signal,
          );

          // Étape 2 : Mise à jour automatique de l'entité liée en DB avant de marquer "done"
          try {
            await runAutoPatch(target, result);
          } catch (patchErr: unknown) {
            const msg =
              (patchErr as { message?: string })?.message ??
              "L'upload a réussi mais l'enregistrement en base de données a échoué.";
            updateJob(id, { status: "error", error: msg, result });
            options.onError?.(msg);
            return;
          }

          // Étape 3 : Marquer comme terminé et notifier le callback
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

  // Mémorisation de la valeur du contexte pour éviter les re-rendus inutiles
  const value = useMemo<UploadContextValue>(
    () => ({ jobs, enqueue, dismiss, cancel, clearFinished }),
    [jobs, enqueue, dismiss, cancel, clearFinished],
  );

  return <UploadContext.Provider value={value}>{children}</UploadContext.Provider>;
}

/**
 * Hook personnalisé permettant d'accéder au gestionnaire global de téléversements.
 * Utilisé notamment par `UploadDropzone` pour enqueue un média et par `UploadTray` pour afficher l'état.
 * 
 * @returns L'interface de gestion des téléversements (`jobs`, `enqueue`, `cancel`, `dismiss`, etc.)
 * @throws {Error} Si invoqué en dehors d'un `<UploadProvider>`
 */
export function useUploads(): UploadContextValue {
  const ctx = useContext(UploadContext);
  if (!ctx) {
    throw new Error("useUploads doit être utilisé dans un <UploadProvider>");
  }
  return ctx;
}
