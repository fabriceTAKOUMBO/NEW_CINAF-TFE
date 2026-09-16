"use client";

// ============================================================
// CINAF v2 — Admin / Explorateur Bunny Storage (multi-zones)
// Affiche le contenu réel stocké sur les Storage Zones Bunny.
// L'admin choisit la zone via un dropdown ; chaque zone a ses
// propres dossiers, fichiers et CDN.
// ============================================================

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { bunny, type BunnyListing, type BunnyFile } from "@/lib/api";

type Tab = "all" | "image" | "video";

const ZONE_STORAGE_KEY = "cinaf.bunny.zone";

/**
 * Explorateur de fichiers et stockage Bunny.net pour les administrateurs.
 * 
 * Fonctionnalités :
 * - Liste des Storage Zones Bunny disponibles (`bunny.listZones`).
 * - Navigation dans l'arborescence des dossiers et sous-dossiers.
 * - Filtrage par type de fichier (Tous, Images, Vidéos).
 * - Aperçu instantané des affiches et vérification des URLs publiques CDN.
 * 
 * @returns L'explorateur de stockage Bunny Storage.
 */
export default function AdminBunnyPage() {
  // La garde ROLE_ADMIN (redirection + écran de vérification) est entièrement
  // assurée par app/admin/layout.tsx : cette page n'est montée que pour un admin
  // authentifié. On ne re-vérifie donc plus le rôle ici (cf. audit finding bunny #7/#8).

  const [zones, setZones] = useState<string[]>([]);
  const [defaultZone, setDefaultZone] = useState<string>("");
  const [currentZone, setCurrentZone] = useState<string>("");
  const [zonesLoaded, setZonesLoaded] = useState(false);

  const [listing, setListing] = useState<BunnyListing | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<Tab>("all");
  const [path, setPath] = useState<string>("");
  const [previewImage, setPreviewImage] = useState<BunnyFile | null>(null);

  // ─── Chargement initial des zones disponibles ─────────────
  useEffect(() => {
    let cancelled = false;
    bunny
      .listZones()
      .then((info) => {
        if (cancelled) return;
        setZones(info.zones);
        setDefaultZone(info.default);
        const persisted =
          typeof window !== "undefined" ? localStorage.getItem(ZONE_STORAGE_KEY) : null;
        const initial =
          persisted && info.zones.includes(persisted) ? persisted : info.default;
        setCurrentZone(initial);
        setZonesLoaded(true);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const msg = (err as { message?: string })?.message ?? "Erreur inconnue";
        setError(`Impossible de charger les zones Bunny : ${msg}`);
        setZonesLoaded(true);
        setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  // ─── Chargement du listing quand zone/onglet/path change ──
  useEffect(() => {
    if (!zonesLoaded || !currentZone) return;

    let cancelled = false;
    setLoading(true);
    setError(null);

    const call =
      tab === "image"
        ? bunny.listImages(path, true, currentZone)
        : tab === "video"
          ? bunny.listVideos(path, true, currentZone)
          : bunny.listFiles({ path, recursive: false, zone: currentZone });

    call
      .then((data) => {
        if (!cancelled) setListing(data);
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          const msg = (err as { message?: string })?.message ?? "Erreur inconnue";
          setError(msg);
          setListing(null);
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [tab, path, currentZone, zonesLoaded]);

  // ─── Persistance de la zone active ────────────────────────
  useEffect(() => {
    if (currentZone && typeof window !== "undefined") {
      localStorage.setItem(ZONE_STORAGE_KEY, currentZone);
    }
  }, [currentZone]);

  const breadcrumbs = useMemo(() => {
    if (!path) return [{ label: "Racine", path: "" }];
    const parts = path.split("/").filter(Boolean);
    const crumbs: { label: string; path: string }[] = [{ label: "Racine", path: "" }];
    let cur = "";
    for (const p of parts) {
      cur = cur ? `${cur}/${p}` : p;
      crumbs.push({ label: p, path: cur });
    }
    return crumbs;
  }, [path]);

  function handleZoneChange(z: string) {
    setCurrentZone(z);
    setPath("");
  }

  return (
    <div className="container py-4">
      <div className="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
        <div>
          <h1 className="mb-1" style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
            <i className="bi bi-hdd-network me-2" style={{ color: "var(--cinaf-gold)" }} />
            Bunny Storage
          </h1>
          <p className="mb-0" style={{ color: "var(--cinaf-text-muted)" }}>
            Explorateur du bucket — {listing?.path ? `/${listing.path}` : "racine"}
            {currentZone && (
              <>
                {" · "}
                <span style={{ color: "var(--cinaf-gold)" }}>zone {currentZone}</span>
                {currentZone === defaultZone && (
                  <span className="badge bg-secondary ms-2">défaut</span>
                )}
              </>
            )}
          </p>
        </div>
        <div className="d-flex align-items-center gap-2">
          {zones.length > 0 && (
            <select
              value={currentZone}
              onChange={(e) => handleZoneChange(e.target.value)}
              className="form-select form-select-sm"
              style={{
                background: "var(--cinaf-surface)",
                color: "var(--cinaf-text)",
                border: "1px solid var(--cinaf-border)",
                minWidth: 200,
              }}
              aria-label="Storage Zone Bunny"
            >
              {zones.map((z) => (
                <option key={z} value={z}>
                  {z}
                  {z === defaultZone ? " (défaut)" : ""}
                </option>
              ))}
            </select>
          )}
          <Link href="/profile" className="btn btn-cinaf-outline btn-sm">
            <i className="bi bi-arrow-left me-1" />
            Retour
          </Link>
        </div>
      </div>

      {/* ─── Onglets type ─────────────────────────────────── */}
      <ul className="nav nav-pills mb-3" role="tablist">
        {([
          { id: "all", label: "Tous", icon: "bi-folder2-open" },
          { id: "image", label: "Images", icon: "bi-image" },
          { id: "video", label: "Vidéos", icon: "bi-film" },
        ] as { id: Tab; label: string; icon: string }[]).map((t) => (
          <li key={t.id} className="nav-item">
            <button
              className={`nav-link ${tab === t.id ? "active" : ""}`}
              style={{
                color: tab === t.id ? "#000" : "var(--cinaf-text)",
                background: tab === t.id ? "var(--cinaf-gold)" : "transparent",
                border: "1px solid var(--cinaf-border)",
              }}
              onClick={() => setTab(t.id)}
            >
              <i className={`bi ${t.icon} me-1`} />
              {t.label}
            </button>
          </li>
        ))}
      </ul>

      {/* ─── Breadcrumbs path ─────────────────────────────── */}
      <nav aria-label="breadcrumb" className="mb-3">
        <ol className="breadcrumb" style={{ background: "var(--cinaf-surface)", padding: "0.5rem 1rem", borderRadius: 6 }}>
          {breadcrumbs.map((b, i) => (
            <li
              key={b.path}
              className={`breadcrumb-item ${i === breadcrumbs.length - 1 ? "active" : ""}`}
              aria-current={i === breadcrumbs.length - 1 ? "page" : undefined}
            >
              {i === breadcrumbs.length - 1 ? (
                <span style={{ color: "var(--cinaf-gold)" }}>{b.label}</span>
              ) : (
                <button
                  type="button"
                  onClick={() => setPath(b.path)}
                  className="btn btn-link p-0"
                  style={{ color: "var(--cinaf-text)", textDecoration: "none" }}
                >
                  {b.label}
                </button>
              )}
            </li>
          ))}
        </ol>
      </nav>

      {/* ─── État ─────────────────────────────────────────── */}
      {loading && (
        <div className="text-center py-5">
          <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} role="status" />
        </div>
      )}

      {error && !loading && (
        <div className="alert" style={{ background: "#2a1414", color: "#ff8a8a", border: "1px solid #5a2020" }}>
          <i className="bi bi-exclamation-triangle me-2" />
          Connexion Bunny impossible : {error}
        </div>
      )}

      {listing && !loading && !error && (
        <>
          {/* Dossiers (onglet "Tous" uniquement) */}
          {tab === "all" && listing.directories.length > 0 && (
            <div className="mb-4">
              <h5 style={{ color: "var(--cinaf-text-muted)" }}>
                Dossiers <span className="badge bg-secondary">{listing.directories.length}</span>
              </h5>
              <div className="row g-3">
                {listing.directories.map((d) => (
                  <div key={d.path} className="col-6 col-md-4 col-lg-3">
                    <button
                      type="button"
                      onClick={() => setPath(d.path)}
                      className="w-100 text-start p-3"
                      style={{
                        background: "var(--cinaf-surface)",
                        border: "1px solid var(--cinaf-border)",
                        borderRadius: 8,
                        color: "var(--cinaf-text)",
                      }}
                    >
                      <i className="bi bi-folder-fill me-2" style={{ color: "var(--cinaf-gold)", fontSize: "1.4rem" }} />
                      {d.name}
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Images en grille */}
          {tab === "image" && (
            <div>
              <h5 style={{ color: "var(--cinaf-text-muted)" }}>
                Images <span className="badge bg-secondary">{listing.files.length}</span>
              </h5>
              {listing.files.length === 0 ? (
                <EmptyState message="Aucune image sur ce bucket." />
              ) : (
                <div className="row g-3">
                  {listing.files.map((f) => (
                    <div key={f.path} className="col-6 col-md-4 col-lg-3">
                      <button
                        type="button"
                        onClick={() => setPreviewImage(f)}
                        className="w-100 p-0 border-0"
                        style={{ background: "transparent" }}
                      >
                        <div
                          style={{
                            aspectRatio: "1 / 1",
                            background: `url(${f.url}) center/cover no-repeat, var(--cinaf-surface)`,
                            borderRadius: 8,
                            border: "1px solid var(--cinaf-border)",
                          }}
                        />
                        <p className="mt-2 mb-0 small text-truncate" style={{ color: "var(--cinaf-text)" }}>
                          {f.name}
                        </p>
                        <p className="mb-0 small" style={{ color: "var(--cinaf-text-muted)" }}>
                          {formatSize(f.size)}
                        </p>
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Vidéos en grille */}
          {tab === "video" && (
            <div>
              <h5 style={{ color: "var(--cinaf-text-muted)" }}>
                Vidéos <span className="badge bg-secondary">{listing.files.length}</span>
              </h5>
              {listing.note && (
                <p className="small" style={{ color: "var(--cinaf-text-muted)", fontStyle: "italic" }}>
                  <i className="bi bi-info-circle me-1" />
                  {listing.note}
                </p>
              )}
              {listing.files.length === 0 ? (
                <EmptyState message="Aucune vidéo stockée directement sur Bunny Storage (Bunny Stream non interrogé ici)." />
              ) : (
                <div className="row g-3">
                  {listing.files.map((f) => (
                    <div key={f.path} className="col-12 col-md-6 col-lg-4">
                      <div
                        className="p-0"
                        style={{
                          background: "var(--cinaf-surface)",
                          border: "1px solid var(--cinaf-border)",
                          borderRadius: 8,
                          overflow: "hidden",
                        }}
                      >
                        <video src={f.url} controls preload="metadata" style={{ width: "100%", display: "block", background: "#000" }} />
                        <div className="p-3">
                          <p className="mb-1 text-truncate" style={{ color: "var(--cinaf-text)" }}>{f.name}</p>
                          <p className="mb-0 small" style={{ color: "var(--cinaf-text-muted)" }}>
                            {formatSize(f.size)} · {f.contentType ?? f.extension.toUpperCase()}
                          </p>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Fichiers génériques (onglet "Tous") */}
          {tab === "all" && listing.files.length > 0 && (
            <div className="mt-4">
              <h5 style={{ color: "var(--cinaf-text-muted)" }}>
                Fichiers <span className="badge bg-secondary">{listing.files.length}</span>
              </h5>
              <div className="table-responsive">
                <table className="table table-dark table-hover align-middle" style={{ background: "var(--cinaf-surface)" }}>
                  <thead>
                    <tr>
                      <th>Type</th>
                      <th>Nom</th>
                      <th>Taille</th>
                      <th>Modifié</th>
                      <th className="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {listing.files.map((f) => (
                      <tr key={f.path}>
                        <td>
                          <i className={`bi ${iconForType(f.type)} me-2`} style={{ color: "var(--cinaf-gold)" }} />
                          <span className="small text-uppercase">{f.type}</span>
                        </td>
                        <td>{f.name}</td>
                        <td>{formatSize(f.size)}</td>
                        <td>{f.lastModified ? new Date(f.lastModified * 1000).toLocaleString("fr-FR") : "—"}</td>
                        <td className="text-end">
                          <a href={f.url} target="_blank" rel="noopener noreferrer" className="btn btn-sm btn-cinaf-outline">
                            <i className="bi bi-box-arrow-up-right" />
                          </a>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {tab === "all" && listing.directories.length === 0 && listing.files.length === 0 && (
            <EmptyState message="Ce dossier est vide." />
          )}
        </>
      )}

      {/* ─── Modale aperçu image ──────────────────────────── */}
      {previewImage && (
        <div
          role="dialog"
          className="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
          style={{ background: "rgba(0,0,0,0.85)", zIndex: 1050, padding: "2rem" }}
          onClick={() => setPreviewImage(null)}
        >
          <div style={{ maxWidth: "90vw", maxHeight: "90vh" }} onClick={(e) => e.stopPropagation()}>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={previewImage.url} alt={previewImage.name} style={{ maxWidth: "100%", maxHeight: "80vh", display: "block" }} />
            <p className="mt-2 mb-0 text-center" style={{ color: "#fff" }}>{previewImage.name}</p>
          </div>
        </div>
      )}
    </div>
  );
}

function EmptyState({ message }: { message: string }) {
  return (
    <div className="text-center py-5" style={{ color: "var(--cinaf-text-muted)" }}>
      <i className="bi bi-folder2 d-block mb-2" style={{ fontSize: "3rem" }} />
      {message}
    </div>
  );
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
  return `${(bytes / 1024 / 1024 / 1024).toFixed(2)} GB`;
}

function iconForType(type: string): string {
  switch (type) {
    case "image": return "bi-image";
    case "video": return "bi-film";
    case "audio": return "bi-music-note-beamed";
    case "document": return "bi-file-text";
    default: return "bi-file-earmark";
  }
}
