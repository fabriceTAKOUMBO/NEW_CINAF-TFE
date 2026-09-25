"use client";

// ============================================================
// CINAF v2 — Page chaîne publique d'un studio /studios/{slug}
// Style retenu : liste unique films+séries avec toggle Tous/Films/Séries.
// ============================================================

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, usePathname, useRouter } from "next/navigation";
import { studios, type StudioPublic, type Work } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import Pagination from "@/components/Pagination";
import NotFoundView from "@/components/NotFoundView";

// 24 œuvres par page (aligné sur /films et /series).
const WORKS_PER_PAGE = 24;

/** Filtres affichés en onglet au-dessus de la grille d'œuvres. */
type KindFilter = "all" | "film" | "serie";

/**
 * Initiales (max 2 caractères) à utiliser pour le placeholder de logo studio
 * dans le header. Logique identique à celle de `StudioCard` mais reproduite
 * ici localement pour éviter d'exposer un helper réutilisé une seule fois.
 */
function studioInitials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return "?";
  if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
  return (words[0][0] + words[1][0]).toUpperCase();
}

/**
 * Page chaîne publique d'un studio. Charge en parallèle :
 *   - Les métadonnées du studio (`studios.get`)
 *   - La page d'œuvres courante filtrée par `kind` (`studios.getWorks`)
 *
 * 404 dédié si le studio n'existe pas ou n'est pas public.
 */
export default function StudioChannelPage() {
  const params = useParams<{ slug: string }>();
  const slug = params?.slug ?? "";
  const router = useRouter();
  const pathname = usePathname();
  const { user, isLoading: authLoading } = useAuth();

  const [studio, setStudio] = useState<StudioPublic | null>(null);
  const [studioError, setStudioError] = useState<string | null>(null);
  const [studioStatus, setStudioStatus] = useState<number | null>(null);

  const [works, setWorks] = useState<Work[]>([]);
  const [worksTotal, setWorksTotal] = useState(0);
  const [worksLoading, setWorksLoading] = useState(true);
  const [worksError, setWorksError] = useState<string | null>(null);

  const [kind, setKind] = useState<KindFilter>("all");
  const [page, setPage] = useState(1);

  // État du bouton « S'abonner / Abonné » façon YouTube.
  //  - `isSubscribed === null` : tant qu'on ne sait pas (utilisateur connecté
  //    mais l'appel `getSubscription` n'a pas encore répondu).
  //  - `subscribersCount` : copie locale du compteur affiché, mise à jour
  //    de façon optimiste lors d'un clic, réversible en cas d'erreur réseau.
  //  - `subActionLoading` : empêche le double-clic pendant l'appel API.
  //  - `subError` : message d'erreur transitoire (auto-dismiss à l'action suivante).
  const [isSubscribed, setIsSubscribed] = useState<boolean | null>(null);
  const [subscribersCount, setSubscribersCount] = useState<number>(0);
  const [subActionLoading, setSubActionLoading] = useState(false);
  const [subError, setSubError] = useState<string | null>(null);
  // Active le hover du bouton « Abonné » pour basculer le label en
  // « Se désabonner ». Géré en state (et pas en CSS pur) car on doit aussi
  // remplacer l'icône, ce qui demande un re-rendu React.
  const [hoverUnsub, setHoverUnsub] = useState(false);

  // 1) Charge le studio à chaque changement de slug.
  useEffect(() => {
    if (!slug) return;
    let cancelled = false;
    setStudio(null);
    setStudioError(null);
    setStudioStatus(null);
    studios
      .get(slug)
      .then((s) => {
        if (cancelled) return;
        setStudio(s);
        // Initialise le compteur affiché à partir du payload public.
        setSubscribersCount(s.subscribersCount);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const e = err as { message?: string; statusCode?: number };
        setStudioStatus(e.statusCode ?? null);
        setStudioError(e.message ?? "Erreur inconnue");
      });
    return () => {
      cancelled = true;
    };
  }, [slug]);

  // 1bis) Charge l'état d'abonnement (uniquement pour un utilisateur connecté).
  // L'appel attend que le contexte d'auth soit résolu pour éviter un GET
  // anonyme qui retournerait 401 → redirection inutile vers /login.
  useEffect(() => {
    if (!slug) return;
    if (authLoading) return;
    if (!user) {
      // Non connecté : on connaît l'état (« non abonné ») sans appeler le backend.
      setIsSubscribed(false);
      return;
    }
    let cancelled = false;
    setIsSubscribed(null);
    studios
      .getSubscription(slug)
      .then((res) => {
        if (cancelled) return;
        setIsSubscribed(res.isSubscribed);
      })
      .catch(() => {
        // En cas d'erreur, on retombe sur l'état « non abonné » par défaut
        // pour ne pas bloquer l'UI. L'erreur n'est pas remontée à l'utilisateur
        // (pas d'action utilisateur en cours).
        if (!cancelled) setIsSubscribed(false);
      });
    return () => {
      cancelled = true;
    };
  }, [slug, user, authLoading]);

  // 2) Charge les œuvres à chaque changement de slug / kind / page.
  useEffect(() => {
    if (!slug) return;
    let cancelled = false;
    setWorksLoading(true);
    setWorksError(null);
    studios
      .getWorks(slug, { page, itemsPerPage: WORKS_PER_PAGE, kind })
      .then((res) => {
        if (cancelled) return;
        setWorks(res.data);
        setWorksTotal(res.total);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const msg = (err as { message?: string })?.message ?? "Erreur inconnue";
        setWorksError(msg);
        setWorks([]);
        setWorksTotal(0);
      })
      .finally(() => {
        if (!cancelled) setWorksLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [slug, kind, page]);

  /**
   * Met à jour le filtre de type. Remet la pagination à 1 pour ne pas
   * afficher une page vide si la nouvelle catégorie a moins d'œuvres.
   */
  const handleKindChange = (next: KindFilter) => {
    if (next === kind) return;
    setKind(next);
    setPage(1);
  };

  /**
   * Clic sur « S'abonner » :
   *  - Utilisateur non connecté → redirection vers /login avec `next`
   *    pointant sur la page courante pour le ramener ici après login.
   *  - Sinon : update optimiste de l'UI (passage en « Abonné », +1 compteur)
   *    puis appel POST. En cas d'erreur réseau on rollback et on affiche
   *    un message non bloquant sous le bouton.
   */
  const handleSubscribe = async () => {
    if (!user) {
      const next = pathname ?? `/studios/${slug}`;
      router.push(`/login?next=${encodeURIComponent(next)}`);
      return;
    }
    if (subActionLoading) return;
    setSubActionLoading(true);
    setSubError(null);
    // Snapshot pour rollback en cas d'erreur.
    const previousSubscribed = isSubscribed;
    const previousCount = subscribersCount;
    setIsSubscribed(true);
    setSubscribersCount((c) => c + 1);
    try {
      const res = await studios.subscribe(slug);
      // Le backend renvoie le compteur exact (utile si race condition) ; on
      // s'aligne dessus plutôt que de garder notre +1 optimiste.
      setSubscribersCount(res.subscribersCount);
    } catch (err: unknown) {
      setIsSubscribed(previousSubscribed);
      setSubscribersCount(previousCount);
      setSubError(
        (err as { message?: string })?.message ?? "Abonnement impossible.",
      );
    } finally {
      setSubActionLoading(false);
    }
  };

  /**
   * Clic sur « Abonné / Se désabonner » :
   *  - Update optimiste (passage en « non abonné », -1 compteur) puis DELETE.
   *  - Rollback en cas d'erreur réseau, comme `handleSubscribe`.
   *
   * Le DELETE backend est idempotent (204 même si déjà désabonné), donc
   * aucun cas particulier à gérer côté client autre que le clamp min à 0.
   */
  const handleUnsubscribe = async () => {
    if (!user) return;
    if (subActionLoading) return;
    setSubActionLoading(true);
    setSubError(null);
    setHoverUnsub(false);
    const previousSubscribed = isSubscribed;
    const previousCount = subscribersCount;
    setIsSubscribed(false);
    setSubscribersCount((c) => Math.max(0, c - 1));
    try {
      await studios.unsubscribe(slug);
    } catch (err: unknown) {
      setIsSubscribed(previousSubscribed);
      setSubscribersCount(previousCount);
      setSubError(
        (err as { message?: string })?.message ?? "Désabonnement impossible.",
      );
    } finally {
      setSubActionLoading(false);
    }
  };

  // État : studio introuvable / non public (404) — écran commun aux pages introuvables.
  if (studioStatus === 404 || (studioError && !studio)) {
    return (
      <NotFoundView
        title="Ce studio est introuvable"
        message="Ce studio n'existe pas ou n'est plus accessible publiquement."
        backHref="/studios"
        backLabel="Voir tous les studios"
      />
    );
  }

  // État : chargement initial du studio.
  if (!studio) {
    return (
      <div
        className="d-flex align-items-center justify-content-center"
        style={{ minHeight: "60vh" }}
      >
        <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} />
      </div>
    );
  }

  const filmsLabel = `${studio.publishedFilmsCount} film${studio.publishedFilmsCount > 1 ? "s" : ""}`;
  const seriesLabel = `${studio.publishedSeriesCount} série${studio.publishedSeriesCount > 1 ? "s" : ""}`;
  // Pluriel français : 0 et 1 prennent « abonné », 2+ prennent « abonnés ».
  const subscribersLabel = `${subscribersCount.toLocaleString("fr-FR")} ${
    subscribersCount <= 1 ? "abonné" : "abonnés"
  }`;

  return (
    <div className="container py-4">
      {/* Header studio : logo + (nom + compteurs) + bouton subscribe à droite */}
      <header className="d-flex flex-column flex-md-row align-items-md-center gap-3 mb-4">
        {/* Logo 96×96 (ou placeholder doré aux initiales) */}
        <div
          style={{
            width: 96,
            height: 96,
            flexShrink: 0,
            borderRadius: 8,
            overflow: "hidden",
            background:
              "linear-gradient(135deg, #1a1a1a 0%, #2a2010 60%, #3d2f15 100%)",
            border: "1px solid var(--cinaf-border)",
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
          }}
          aria-label={`Logo ${studio.name}`}
        >
          {studio.logoUrl ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={studio.logoUrl}
              alt={studio.name}
              style={{ width: "100%", height: "100%", objectFit: "cover" }}
            />
          ) : (
            <span
              style={{
                fontSize: "2rem",
                fontWeight: 800,
                color: "var(--cinaf-gold)",
                letterSpacing: "0.05em",
              }}
            >
              {studioInitials(studio.name)}
            </span>
          )}
        </div>

        <div className="flex-grow-1">
          <h1
            className="mb-1"
            style={{ color: "var(--cinaf-text)", fontWeight: 700 }}
          >
            {studio.name}
          </h1>
          <p
            className="mb-1"
            style={{ color: "var(--cinaf-gold)", fontSize: "0.9rem" }}
          >
            {filmsLabel} · {seriesLabel}
          </p>
          {/* Compteur d'abonnés sur sa propre ligne, juste sous les compteurs
              films/séries. Mis à jour en optimiste lors de subscribe/unsubscribe. */}
          <p
            className="mb-0 d-flex align-items-center gap-1"
            style={{ color: "var(--cinaf-gold)", fontSize: "0.9rem" }}
          >
            <i className="bi bi-people-fill" aria-hidden="true" />
            <span>{subscribersLabel}</span>
          </p>
        </div>

        {/* Bouton subscribe / abonné — placé à droite via ml-md-auto en flex.
            Wrapper séparé pour pouvoir poser le message d'erreur transitoire
            sous le bouton sans casser l'alignement du header. */}
        <div className="ms-md-auto d-flex flex-column align-items-md-end">
          <SubscribeButton
            // L'état null (= chargement initial pour user connecté) désactive
            // le bouton pour éviter un clic prématuré avant qu'on connaisse
            // l'état réel d'abonnement côté backend.
            isSubscribed={isSubscribed}
            loading={subActionLoading}
            hoverUnsub={hoverUnsub}
            setHoverUnsub={setHoverUnsub}
            onSubscribe={handleSubscribe}
            onUnsubscribe={handleUnsubscribe}
          />
          {subError && (
            <small
              className="mt-1"
              style={{ color: "#ff8a8a", maxWidth: 240, textAlign: "right" }}
            >
              {subError}
            </small>
          )}
        </div>
      </header>

      {studio.description && (
        <p
          className="mb-4"
          style={{ color: "var(--cinaf-text-muted)", maxWidth: 800 }}
        >
          {studio.description}
        </p>
      )}

      {/* Toggle Tous / Films / Séries */}
      <div
        className="btn-group mb-4"
        role="group"
        aria-label="Filtrer les œuvres par type"
      >
        {(
          [
            { id: "all" as KindFilter, label: "Tous" },
            { id: "film" as KindFilter, label: "Films" },
            { id: "serie" as KindFilter, label: "Séries" },
          ]
        ).map((opt) => (
          <button
            key={opt.id}
            type="button"
            className={`btn ${kind === opt.id ? "btn-warning" : "btn-outline-warning"}`}
            onClick={() => handleKindChange(opt.id)}
          >
            {opt.label}
          </button>
        ))}
      </div>

      {/* Liste d'œuvres */}
      {worksLoading && (
        <div className="text-center py-5">
          <div
            className="spinner-border"
            style={{ color: "var(--cinaf-gold)" }}
            role="status"
          />
        </div>
      )}

      {worksError && !worksLoading && (
        <div
          className="alert"
          style={{
            background: "#2a1414",
            color: "#ff8a8a",
            border: "1px solid #5a2020",
          }}
        >
          <i className="bi bi-exclamation-triangle me-2" />
          Œuvres indisponibles : {worksError}
        </div>
      )}

      {!worksLoading && !worksError && works.length === 0 && (
        <div
          className="text-center py-5"
          style={{ color: "var(--cinaf-text-muted)" }}
        >
          <i
            className="bi bi-camera-reels d-block mb-2"
            style={{ fontSize: "3rem" }}
          />
          Ce studio n&apos;a encore publié aucune œuvre dans cette catégorie.
        </div>
      )}

      {!worksLoading && !worksError && works.length > 0 && (
        <>
          <div className="row g-3">
            {works.map((w) => (
              <div key={w.id} className="col-6 col-md-4 col-lg-3">
                <WorkTile work={w} />
              </div>
            ))}
          </div>

          {worksTotal > WORKS_PER_PAGE && (
            <Pagination
              currentPage={page}
              totalItems={worksTotal}
              itemsPerPage={WORKS_PER_PAGE}
              onPageChange={(p) => setPage(p)}
            />
          )}
        </>
      )}
    </div>
  );
}

// ─── Sous-composant local : bouton « S'abonner / Abonné » ──────

/**
 * Bouton style YouTube affiché à droite du nom du studio.
 *
 * États rendus :
 *  - `isSubscribed === null` : bouton « S'abonner » désactivé (l'état
 *    réel n'est pas encore connu côté backend).
 *  - `isSubscribed === false` : bouton plein doré (btn-warning) avec
 *    cloche vide. Au clic → `onSubscribe`.
 *  - `isSubscribed === true` : bouton outline doré avec cloche pleine.
 *    Label « Abonné » par défaut, bascule en « Se désabonner » au hover
 *    (state `hoverUnsub` géré par le parent pour ne pas avoir à dupliquer
 *    la logique en CSS).
 *
 * Le bouton est désactivé pendant `loading` pour éviter le double-clic.
 */
function SubscribeButton({
  isSubscribed,
  loading,
  hoverUnsub,
  setHoverUnsub,
  onSubscribe,
  onUnsubscribe,
}: {
  isSubscribed: boolean | null;
  loading: boolean;
  hoverUnsub: boolean;
  setHoverUnsub: (v: boolean) => void;
  onSubscribe: () => void;
  onUnsubscribe: () => void;
}) {
  if (isSubscribed === true) {
    return (
      <button
        type="button"
        className="btn btn-outline-warning d-flex align-items-center gap-2"
        onMouseEnter={() => setHoverUnsub(true)}
        onMouseLeave={() => setHoverUnsub(false)}
        onClick={onUnsubscribe}
        disabled={loading}
        aria-label="Se désabonner de ce studio"
      >
        <i className={`bi ${hoverUnsub ? "bi-bell-slash" : "bi-bell-fill"}`} />
        <span>{hoverUnsub ? "Se désabonner" : "Abonné"}</span>
      </button>
    );
  }

  // États `false` et `null` partagent le même rendu (bouton « S'abonner »),
  // le `null` (chargement initial pour user connecté) est juste désactivé.
  return (
    <button
      type="button"
      className="btn btn-warning d-flex align-items-center gap-2"
      onClick={onSubscribe}
      disabled={loading || isSubscribed === null}
      aria-label="S'abonner à ce studio"
    >
      <i className="bi bi-bell" />
      <span>S&apos;abonner</span>
    </button>
  );
}

// ─── Sous-composant local : tuile œuvre studio ────────────────

/**
 * Tuile compacte pour afficher une œuvre studio dans la grille de chaîne.
 * Local à ce fichier car la forme `Work` est différente des `Film` / `Serie`
 * que consomme `ContentCard` (pas de `genres` ni de `duration`).
 *
 * Le lien cible `/films/{slug}` ou `/series/{slug}` selon `kind`.
 */
function WorkTile({ work }: { work: Work }) {
  const href = work.kind === "serie" ? `/series/${work.slug}` : `/films/${work.slug}`;
  return (
    <Link href={href} className="text-decoration-none" style={{ color: "inherit" }}>
      <div className="content-card">
        <div className="content-card-poster">
          {work.poster ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={work.poster}
              alt={work.title}
              loading="lazy"
              className="content-card-img"
            />
          ) : (
            <div className="content-card-placeholder">
              <i
                className="bi bi-film"
                style={{ fontSize: "2rem", color: "var(--cinaf-text-muted)" }}
              />
            </div>
          )}

          {/* Badge type (Film / Série) */}
          <span
            style={{
              position: "absolute",
              top: 6,
              right: 6,
              background: "rgba(0,0,0,0.7)",
              color: work.kind === "serie" ? "#8ad" : "var(--cinaf-gold)",
              fontSize: "0.65rem",
              padding: "2px 8px",
              borderRadius: 12,
              fontWeight: 700,
              textTransform: "uppercase",
              letterSpacing: "0.05em",
            }}
          >
            {work.kind === "serie" ? "Série" : "Film"}
          </span>

          <div className="content-card-overlay">
            <h6 className="content-card-title">{work.title}</h6>
            {work.year != null && (
              <div className="d-flex align-items-center gap-2 flex-wrap">
                <span className="content-card-year">{work.year}</span>
              </div>
            )}
          </div>
        </div>
      </div>
    </Link>
  );
}
