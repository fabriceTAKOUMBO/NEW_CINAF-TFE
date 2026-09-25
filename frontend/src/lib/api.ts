// ============================================================
// CINAF v2 — Client HTTP (fetch natif TypeScript)
// Ce module centralise tous les appels vers l'API Backend Symfony.
// Il gère l'authentification par jeton JWT et la normalisation des données.
// ============================================================

const BASE_URL =
  process.env.NEXT_PUBLIC_API_URL || "http://localhost:8000/api";

// ─── Types Fondamentaux ──────────────────────────────────────

/**
 * Représente un utilisateur dans le système.
 */
export interface User {
  id: string;
  email: string;
  firstName: string;
  lastName: string;
  roles: string[];
  createdAt: string;
  isVerified?: boolean;
  /** Calculé côté backend dans /auth/me : true si abo ACTIVE non expiré. */
  hasActiveSubscription?: boolean;
  /** Résumé de l'abo actif (ou null). */
  subscription?: SubscriptionSummary | null;
}

export interface SubscriptionSummary {
  id: string;
  status: string;
  planName: string;
  startsAt: string;
  endsAt?: string | null;
  canceledAt?: string | null;
  isCurrentlyActive: boolean;
}

/**
 * Données requises pour l'authentification.
 */
export interface LoginData {
  email: string;
  password: string;
}

/**
 * Réponse du serveur après une connexion réussie.
 */
export interface LoginResponse {
  access_token: string;
  refresh_token: string;
  user?: User;
}

/**
 * Données requises pour la création de compte.
 */
export interface RegisterData {
  email: string;
  password: string;
  firstName: string;
  lastName: string;
  consentRgpd: boolean;
}

/**
 * Réponse standard après une tentative d'inscription.
 */
export interface RegisterResponse {
  message: string;
  user?: User;
}

/**
 * Structure d'erreur standard renvoyée par ce client HTTP.
 */
export interface ApiError {
  message: string;
  statusCode: number;
  detail?: string;
  /**
   * Corps JSON complet de la réponse d'erreur, pour les erreurs qui portent
   * des données utiles à l'écran (ex. 410 d'une œuvre retirée : `{status,
   * kind, title}`). Absent si la réponse n'est pas un objet JSON.
   */
  data?: Record<string, unknown>;
}

// ─── Types Abonnement & Paiement ─────────────────────────────

/**
 * Plan d'abonnement proposé sur la plateforme.
 */
export interface SubscriptionPlan {
  id: string;
  name: string;
  description?: string | null;
  priceCents?: number;
  price: number;
  formattedPrice: string;
  currency: string;
  intervalUnit?: "month" | "year";
  intervalCount?: number;
  billingInterval: "month" | "year";
  features: string[];
  isActive: boolean;
  trialDays: number;
}

/**
 * Abonnement actif d'un utilisateur.
 * Forme alignée sur le backend Symfony (Subscription::toArray()).
 */
export interface UserSubscription {
  id: string;
  status: string;
  startsAt: string;
  endsAt?: string | null;
  canceledAt?: string | null;
  isCurrentlyActive?: boolean;
  plan: {
    id: string;
    name: string;
    priceCents?: number;
    price: number;
    formattedPrice: string;
    currency: string;
    intervalUnit?: string;
    intervalCount?: number;
    billingInterval: string;
    features: string[];
  };
}

/**
 * Réponse du backend quand Stripe est activé. On utilise Stripe Embedded Checkout :
 * le frontend passe `clientSecret` à `<EmbeddedCheckoutProvider>` pour afficher
 * le formulaire de paiement directement dans la page (pas de redirection).
 */
export interface StripeCheckoutResponse {
  mode: "stripe";
  clientSecret: string;
  sessionId: string;
}

export function isStripeCheckoutResponse(
  v: UserSubscription | StripeCheckoutResponse,
): v is StripeCheckoutResponse {
  return (v as StripeCheckoutResponse).mode === "stripe";
}

/** Statut d'une Checkout Session côté retour Embedded — consommé par /abonnement/success. */
export interface StripeSessionStatus {
  status: "open" | "complete" | "expired";
  paymentStatus: "paid" | "unpaid" | "no_payment_required";
  customerEmail?: string | null;
}

/**
 * Facture associée à un paiement.
 */
export interface InvoiceInfo {
  id: string;
  invoiceUrl?: string;
  invoicePdf?: string;
  invoiceNumber?: string;
}

/**
 * Paiement effectué par un utilisateur.
 */
export interface PaymentRecord {
  id: string;
  amount: number;
  formattedAmount: string;
  currency: string;
  status: string;
  paidAt?: string;
  createdAt: string;
  invoice?: InvoiceInfo;
}

/**
 * Entrée d'historique de paiement renvoyée par les endpoints
 * `/api/subscriptions/payments` (user) et `/api/admin/users/{id}/payments` (admin).
 *
 * Les données proviennent directement de Stripe (invoice.list) :
 *  - `amount` est en centimes (ex. 999 → 9,99 €).
 *  - `paidAt` est une chaîne ISO 8601 (format ATOM côté backend) ou null si la
 *    facture n'a pas encore été payée (status `open`).
 *  - `status` reprend les valeurs Stripe (`paid`, `open`, `void`, `uncollectible`).
 *  - `invoicePdfUrl` est l'URL du PDF Stripe ; null si Stripe ne l'a pas généré.
 */
export interface Payment {
  id: string;
  paidAt: string | null;
  amount: number;
  currency: string;
  status: string | null;
  planName: string | null;
  invoicePdfUrl: string | null;
}

// ─── Types Catalogue (Films, Séries, etc.) ───────────────────

export interface Genre {
  id: string;
  name: string;
  slug: string;
}

export interface Country {
  id: string;
  name: string;
  isoCode: string;
}

export interface Language {
  id: string;
  name: string;
  isoCode: string;
}

export interface Person {
  id: string;
  firstName: string;
  lastName: string;
  bio?: string;
  /** Champ backend : `photo` (URL de la photo de profil) */
  photo?: string;
}

/**
 * Entité Film complète avec métadonnées et relations.
 * Noms de champs alignés sur le contrat backend Sprint 2.
 */
export interface Film {
  id: string;
  title: string;
  slug: string;
  synopsis: string;
  year: number;
  duration: number;
  /** URL de l'affiche (champ backend : `poster`) */
  poster?: string;
  /** ID vidéo Bunny pour la bande-annonce (champ backend : `trailerVideoId`) */
  trailerVideoId?: string;
  bunnyVideoId?: string;
  /** Compteur de vues (champ backend : `views`) */
  views: number;
  avgRating?: number;
  genres: Genre[];
  countries: Country[];
  directors: Person[];
  cast: Person[];
}

/**
 * Une saison regroupe un ensemble d'épisodes.
 */
export interface Season {
  id: string;
  number: number;
  title?: string;
  synopsis?: string;
  episodes?: Episode[];
}

/**
 * Entité Episode pour les séries.
 */
export interface Episode {
  id: string;
  number: number;
  title: string;
  synopsis?: string;
  duration: number;
  bunnyVideoId?: string;
}

/**
 * Entité Série complète incluant les saisons.
 * Noms de champs alignés sur le contrat backend Sprint 2.
 */
export interface Serie {
  id: string;
  title: string;
  slug: string;
  synopsis: string;
  year: number;
  /** URL de l'affiche (champ backend : `poster`) */
  poster?: string;
  /** ID vidéo Bunny pour la bande-annonce (champ backend : `trailerVideoId`) */
  trailerVideoId?: string;
  /** Nombre de saisons (champ backend : `nbSeasons`) */
  nbSeasons?: number;
  avgRating?: number;
  genres: Genre[];
  countries: Country[];
  /** Saisons : chargées via GET /series/{id}/seasons (peut être absent si endpoint séparé) */
  seasons?: Season[];
}

/**
 * Contenu mis en avant pour le carrousel principal.
 */
export interface FeaturedContent {
  id: string;
  contentType: "film" | "serie";
  contentId: string;
  position: number;
  film?: Film;
  serie?: Serie;
}

/**
 * Informations retournées par les endpoints /stream (Sprint 2).
 * Le backend retourne { bunnyVideoId, libraryId } pour construire l'URL Bunny côté client.
 */
export interface StreamInfo {
  bunnyVideoId: string;
  libraryId: string;
}

/**
 * Résultats d'une recherche multi-critères.
 */
export interface SearchResult {
  films: Film[];
  series: Serie[];
  total: number;
}

/**
 * Structure générique pour les listes paginées.
 * Format backend Sprint 2 : { data: T[], total: number, page: number, limit: number }
 */
export interface PaginatedResult<T> {
  data: T[];
  total: number;
  page: number;
  limit: number;
}

// ─── Helpers internes ─────────────────────────────────────────

/**
 * Récupère le jeton d'accès stocké dans le localStorage du navigateur.
 * Utilisé pour authentifier les requêtes sortantes auprès du backend.
 */
function getAccessToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem("access_token");
}

/**
 * Prépare les en-têtes de la requête HTTP.
 * Ajoute automatiquement le Content-Type, Accept et le jeton Bearer si nécessaire.
 * Pour les requêtes PATCH, API Platform attend "application/merge-patch+json".
 */
function buildHeaders(withAuth = true, method = "GET"): HeadersInit {
  const isPatch = method.toUpperCase() === "PATCH";
  const headers: Record<string, string> = {
    "Content-Type": isPatch ? "application/merge-patch+json" : "application/json",
    Accept: "application/json",
  };

  if (withAuth) {
    const token = getAccessToken();
    if (token) {
      headers["Authorization"] = `Bearer ${token}`;
    }
  }

  return headers;
}

/**
 * Traite la réponse brute de fetch.
 * Gère l'expiration de session (401), l'extraction des messages d'erreur et le parsing JSON.
 */
async function handleResponse<T>(response: Response): Promise<T> {
  // Cas 401 : Session expirée ou jeton invalide. On nettoie et redirige.
  // Exception : on ne redirige pas si l'on se trouve déjà sur une page d'auth
  // (ex. /login avec credentials incorrects) pour que le catch de la page
  // puisse afficher le message d'erreur approprié.
  if (response.status === 401) {
    if (typeof window !== "undefined") {
      const authPaths = ["/login", "/register", "/forgot-password", "/reset-password"];
      const isAuthPage = authPaths.some((p) => window.location.pathname.startsWith(p));
      if (!isAuthPage) {
        localStorage.removeItem("access_token");
        window.location.href = "/login";
      }
    }
    throw { message: "Non authentifié", statusCode: 401 } as ApiError;
  }

  const contentType = response.headers.get("content-type") || "";
  const isJson = contentType.includes("application/json") || contentType.includes("application/ld+json");

  // Gestion des erreurs HTTP (4xx et 5xx)
  if (!response.ok) {
    let errorMessage = `Erreur ${response.status}`;
    let detail: string | undefined;
    let data: Record<string, unknown> | undefined;

    if (isJson) {
      try {
        const errorData = await response.json();
        // Extraction du message selon les différents formats possibles (Symfony, Hydra, Custom)
        errorMessage = errorData.message || errorData.detail || errorData["hydra:description"] || errorMessage;
        detail = errorData.detail;
        if (errorData && typeof errorData === "object" && !Array.isArray(errorData)) {
          data = errorData;
        }
      } catch {
        // En cas d'échec du parsing JSON, on garde le message par défaut
      }
    }

    throw { message: errorMessage, statusCode: response.status, detail, data } as ApiError;
  }

  // Succès sans contenu (204 No Content)
  if (response.status === 204 || !isJson) {
    return undefined as T;
  }

  // Retourne les données parsées
  return response.json() as Promise<T>;
}

/**
 * Fonction générique pour effectuer une requête HTTP encapsulant fetch.
 */
async function request<T>(
  method: string,
  path: string,
  body?: unknown,
  withAuth = true
): Promise<T> {
  const response = await fetch(`${BASE_URL}${path}`, {
    method,
    headers: buildHeaders(withAuth, method),
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  return handleResponse<T>(response);
}

// ─── Module auth ──────────────────────────────────────────────

/**
 * Services liés à la gestion de la session et de la sécurité.
 */
export const auth = {
  /** Inscription d'un nouvel utilisateur */
  register(data: RegisterData): Promise<RegisterResponse> {
    return request<RegisterResponse>("POST", "/auth/register", data, false);
  },

  /** Connexion utilisateur : renvoie les jetons d'accès et de rafraîchissement */
  login(data: LoginData): Promise<LoginResponse> {
    return request<LoginResponse>("POST", "/auth/login", data, false);
  },

  /** Déconnexion : révoque la session côté serveur en envoyant le refresh_token */
  logout(): Promise<void> {
    // Le refresh_token est stocké en cookie ; le backend l'attend dans le body
    const refreshToken =
      typeof document !== "undefined"
        ? document.cookie
            .split("; ")
            .find((row) => row.startsWith("refresh_token="))
            ?.split("=")[1]
        : undefined;
    return request<void>(
      "POST",
      "/auth/logout",
      refreshToken ? { refresh_token: refreshToken } : {},
    );
  },

  /** Initie la procédure de récupération de mot de passe */
  forgotPassword(email: string): Promise<{ message: string }> {
    return request<{ message: string }>("POST", "/auth/forgot-password", { email }, false);
  },

  /** Finalise le changement de mot de passe à l'aide d'un jeton reçu par email */
  resetPassword(token: string, password: string): Promise<{ message: string }> {
    return request<{ message: string }>("POST", "/auth/reset-password", { token, password }, false);
  },

  /** Récupère le profil de l'utilisateur connecté depuis le JWT (évite le décodage manuel) */
  me(): Promise<User> {
    return request<User>("GET", "/auth/me");
  },
};

// ─── Module users ─────────────────────────────────────────────

/**
 * Services liés à la gestion des comptes utilisateurs.
 */
export const users = {
  /** Récupère les informations détaillées d'un profil */
  getProfile(id: string): Promise<User> {
    return request<User>("GET", `/users/${id}`);
  },

  /** Met à jour partiellement les informations d'un utilisateur (PATCH) */
  updateProfile(id: string, data: Partial<User>): Promise<User> {
    return request<User>("PATCH", `/users/${id}`, data);
  },

  /** Supprime définitivement un compte utilisateur (RGPD) */
  deleteAccount(id: string): Promise<void> {
    return request<void>("DELETE", `/users/${id}`);
  },
};

// ─── Helpers API Platform (Hydra) ────────────────────────────

/**
 * Normalise les réponses de type collection Hydra (API Platform)
 * ou REST standard vers PaginatedResult<T> (format backend Sprint 2).
 * Accepte : hydra:member / member / data / items selon l'implémentation backend.
 */
function hydraToPage<T>(
  raw: Record<string, unknown>,
  page: number,
  limit = 30
): PaginatedResult<T> {
  const members = (
    raw["hydra:member"] ??
    raw["member"] ??
    raw["data"] ??
    raw["items"] ??
    []
  ) as T[];
  const total = (
    raw["hydra:totalItems"] ??
    raw["totalItems"] ??
    raw["total"] ??
    members.length
  ) as number;
  const resolvedLimit = (raw["limit"] as number | undefined) ?? limit;
  return { data: members, total, page, limit: resolvedLimit };
}

// ─── Module catalogue ────────────────────────────────────────

/**
 * Module principal pour l'exploration et le streaming des contenus.
 * URLs alignées sur les endpoints Sprint 2 (routmap.md).
 */
export const catalogue = {
  // ── Films ─────────────────────────────────────────────────

  /** GET /films?page=&limit= — Liste paginée de films (30/page) */
  async getFilms(page = 1, limit = 30): Promise<PaginatedResult<Film>> {
    const params = new URLSearchParams({ page: String(page), limit: String(limit) });
    const raw = await request<Record<string, unknown>>("GET", `/films?${params}`, undefined, false);
    return hydraToPage<Film>(raw, page, limit);
  },

  /** GET /films/{id} — Détail film */
  getFilm(id: string): Promise<Film> {
    return request<Film>("GET", `/films/${id}`, undefined, false);
  },

  /** GET /films/search?q=&genre=&year=&country=&lang= — Recherche films */
  async searchFilms(params: {
    q?: string;
    genre?: string;
    year?: number;
    country?: string;
    lang?: string;
  }): Promise<PaginatedResult<Film>> {
    const qs = new URLSearchParams();
    if (params.q) qs.set("q", params.q);
    if (params.genre) qs.set("genre", params.genre);
    if (params.year) qs.set("year", String(params.year));
    if (params.country) qs.set("country", params.country);
    if (params.lang) qs.set("lang", params.lang);
    const raw = await request<Record<string, unknown>>("GET", `/films/search?${qs}`, undefined, false);
    return hydraToPage<Film>(raw, 1);
  },

  /** GET /films/featured — Films mis en avant (home) */
  getFeatured(): Promise<FeaturedContent[]> {
    return request<FeaturedContent[]>("GET", "/films/featured", undefined, false);
  },

  /** GET /films/trending — Top vues du moment */
  getTrending(): Promise<Film[]> {
    return request<Film[]>("GET", "/films/trending", undefined, false);
  },

  /** GET /films/new — Nouvelles sorties */
  getNew(): Promise<Film[]> {
    return request<Film[]>("GET", "/films/new", undefined, false);
  },

  // ── Séries ────────────────────────────────────────────────

  /** GET /series?page=&limit= — Liste paginée de séries */
  async getSeries(page = 1, limit = 30): Promise<PaginatedResult<Serie>> {
    const params = new URLSearchParams({ page: String(page), limit: String(limit) });
    const raw = await request<Record<string, unknown>>("GET", `/series?${params}`, undefined, false);
    return hydraToPage<Serie>(raw, page, limit);
  },

  /** GET /series/{id} — Détail série */
  getSerie(id: string): Promise<Serie> {
    return request<Serie>("GET", `/series/${id}`, undefined, false);
  },

  /** GET /series/search?q=&genre=&year= — Recherche séries */
  async searchSeries(params: {
    q?: string;
    genre?: string;
    year?: number;
  }): Promise<PaginatedResult<Serie>> {
    const qs = new URLSearchParams();
    if (params.q) qs.set("q", params.q);
    if (params.genre) qs.set("genre", params.genre);
    if (params.year) qs.set("year", String(params.year));
    const raw = await request<Record<string, unknown>>("GET", `/series/search?${qs}`, undefined, false);
    return hydraToPage<Serie>(raw, 1);
  },

  /** GET /series/{id}/seasons — Saisons d'une série */
  getSeasons(serieId: string): Promise<Season[]> {
    return request<Season[]>("GET", `/series/${serieId}/seasons`, undefined, false);
  },

  /** GET /series/{id}/seasons/{saison}/episodes — Épisodes d'une saison */
  getEpisodes(serieId: string, seasonNumber: number): Promise<Episode[]> {
    return request<Episode[]>(
      "GET",
      `/series/${serieId}/seasons/${seasonNumber}/episodes`,
      undefined,
      false
    );
  },

  /** GET /episodes/{id} — Détail épisode (ROLE_USER requis) */
  getEpisode(episodeId: string): Promise<Episode> {
    return request<Episode>("GET", `/episodes/${episodeId}`, undefined, true);
  },

  // ── Référentiel ───────────────────────────────────────────

  /** GET /genres — Liste des genres */
  async getGenres(): Promise<Genre[]> {
    const raw = await request<Record<string, unknown>>("GET", "/genres", undefined, false);
    return (raw["hydra:member"] ?? raw["member"] ?? raw["data"] ?? raw) as Genre[];
  },

  /** GET /countries — Liste des pays */
  async getCountries(): Promise<Country[]> {
    const raw = await request<Record<string, unknown>>("GET", "/countries", undefined, false);
    return (raw["hydra:member"] ?? raw["member"] ?? raw["data"] ?? raw) as Country[];
  },

  /** GET /languages — Liste des langues */
  async getLanguages(): Promise<Language[]> {
    const raw = await request<Record<string, unknown>>("GET", "/languages", undefined, false);
    return (raw["hydra:member"] ?? raw["member"] ?? raw["data"] ?? raw) as Language[];
  },

  /** GET /persons — Liste réalisateurs/acteurs */
  async getPersons(): Promise<Person[]> {
    const raw = await request<Record<string, unknown>>("GET", "/persons", undefined, false);
    return (raw["hydra:member"] ?? raw["member"] ?? raw["data"] ?? raw) as Person[];
  },

  // ── Streaming ─────────────────────────────────────────────

  /** GET /films/{id}/stream — URL streaming film (ROLE_USER requis) */
  getFilmStream(id: string): Promise<StreamInfo> {
    return request<StreamInfo>("GET", `/films/${id}/stream`, undefined, true);
  },

  /** GET /episodes/{id}/stream — URL streaming épisode (ROLE_USER requis) */
  getEpisodeStream(id: string): Promise<StreamInfo> {
    return request<StreamInfo>("GET", `/episodes/${id}/stream`, undefined, true);
  },

  // ── Actions ───────────────────────────────────────────────

  /** POST /films/{id}/view — Incrémenter le compteur de vues (ROLE_USER requis) */
  incrementView(filmId: string): Promise<void> {
    return request<void>("POST", `/films/${filmId}/view`, undefined, true);
  },

  /**
   * DETTE — Recherche unifiée films+séries en une requête.
   * La spec Sprint 2 définit deux endpoints séparés (/films/search et /series/search).
   * Cette méthode combine les deux pour préserver la compatibilité avec les pages existantes.
   */
  async search(params: {
    q?: string;
    genre?: string;
    year?: number;
    country?: string;
    lang?: string;
  }): Promise<SearchResult> {
    const [filmsRes, seriesRes] = await Promise.allSettled([
      catalogue.searchFilms(params),
      catalogue.searchSeries({ q: params.q, genre: params.genre, year: params.year }),
    ]);
    const films = filmsRes.status === "fulfilled" ? filmsRes.value.data : [];
    const series = seriesRes.status === "fulfilled" ? seriesRes.value.data : [];
    return { films, series, total: films.length + series.length };
  },
};

// ─── Module abonnement ──────────────────────────────────────

/**
 * Services liés aux abonnements et paiements Stripe.
 */
export const subscriptions = {
  /** Récupère la liste des plans d'abonnement actifs */
  async getPlans(): Promise<SubscriptionPlan[]> {
    const data = await request<Record<string, unknown>>("GET", "/subscription-plans", undefined, false);
    return (data["hydra:member"] ?? data["member"] ?? data["data"] ?? data) as SubscriptionPlan[];
  },

  /** Récupère les détails d'un plan */
  getPlan(id: string): Promise<SubscriptionPlan> {
    return request<SubscriptionPlan>("GET", `/subscription-plans/${id}`, undefined, false);
  },

  /**
   * Souscrit à un plan.
   * - Si Stripe est désactivé côté backend (mode démo/test), retourne une `UserSubscription`
   *   active immédiatement (compat. avec l'ancien mock).
   * - Si Stripe est activé, retourne `{ mode: 'stripe', checkoutUrl, sessionId }` — l'appelant
   *   doit alors rediriger le navigateur vers `checkoutUrl`. L'abonnement sera créé en DB
   *   par le webhook `checkout.session.completed`.
   */
  subscribe(planId: string): Promise<UserSubscription | StripeCheckoutResponse> {
    return request<UserSubscription | StripeCheckoutResponse>(
      "POST",
      "/subscriptions/subscribe",
      { planId },
      true,
    );
  },

  /**
   * Annule l'abonnement actif de l'utilisateur connecté en mode différé.
   * Le backend rempli `canceledAt` mais conserve `status=ACTIVE` jusqu'à `endsAt` :
   * aucun prélèvement futur, accès maintenu jusqu'à la fin de la période en cours.
   */
  cancel(): Promise<{ message: string; subscription: UserSubscription }> {
    return request<{ message: string; subscription: UserSubscription }>(
      "POST",
      "/subscriptions/cancel",
      undefined,
      true,
    );
  },

  /**
   * Annule la résiliation différée demandée précédemment.
   * Remet `canceledAt` à null côté backend ; le cycle de facturation reprend normalement.
   */
  resume(): Promise<{ message: string; subscription: UserSubscription }> {
    return request<{ message: string; subscription: UserSubscription }>(
      "POST",
      "/subscriptions/resume",
      undefined,
      true,
    );
  },

  /**
   * Récupère l'historique des paiements Stripe de l'utilisateur connecté.
   * Retourne un tableau vide si l'utilisateur n'a jamais eu de Stripe customer
   * (ex. compte créé en mode mock sans Stripe).
   */
  getPayments(): Promise<Payment[]> {
    return request<Payment[]>("GET", "/subscriptions/payments", undefined, true);
  },

  /** Récupère l'abonnement actif de l'utilisateur connecté */
  async getCurrent(): Promise<UserSubscription | null> {
    const data = await request<{ subscription: UserSubscription | null }>(
      "GET",
      "/subscriptions/current",
      undefined,
      true
    );
    return data.subscription;
  },

  /**
   * Récupère le statut d'une Stripe Checkout Session — utilisé par la page
   * `/abonnement/success` pour confirmer immédiatement le paiement sans
   * attendre le webhook.
   */
  getSessionStatus(sessionId: string): Promise<StripeSessionStatus> {
    return request<StripeSessionStatus>(
      "GET",
      `/subscriptions/session/${encodeURIComponent(sessionId)}`,
      undefined,
      true,
    );
  },

  /**
   * Vérifie si l'utilisateur connecté peut lire le contenu d'une œuvre.
   * Retourne true si 204, false si 403 (pas d'abo) ; throw sur autres erreurs.
   */
  async canPlay(slug: string): Promise<boolean> {
    const token = typeof window !== "undefined" ? localStorage.getItem("access_token") : null;
    const res = await fetch(`${BASE_URL}/catalogue/discover/${encodeURIComponent(slug)}/can-play`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    });
    if (res.status === 204) return true;
    if (res.status === 403) return false;
    if (res.status === 401) {
      throw { message: "Non authentifié", statusCode: 401 } as ApiError;
    }
    throw { message: `Erreur ${res.status}`, statusCode: res.status } as ApiError;
  },
};

// ─── Module Admin Subscriptions ──────────────────────────────

/**
 * Gestion admin des abonnements d'un utilisateur cible.
 * Toutes les routes sont protégées par ROLE_ADMIN.
 */
export const adminSubscriptions = {
  /**
   * Récupère l'abonnement actif d'un utilisateur spécifié par son identifiant.
   * 
   * @param userId - Identifiant UUID de l'utilisateur
   * @returns L'abonnement courant de l'utilisateur, ou null s'il n'en a pas
   */
  getCurrent(userId: string): Promise<UserSubscription | null> {
    return request<{ subscription: UserSubscription | null }>(
      "GET",
      `/admin/users/${userId}/subscription`,
      undefined,
      true,
    ).then((d) => d.subscription);
  },

  /**
   * Attribue manuellement un plan d'abonnement à un utilisateur (contournement administratif sans Stripe).
   * 
   * @param userId - Identifiant UUID de l'utilisateur
   * @param planId - Identifiant UUID du plan d'abonnement choisi
   * @returns L'abonnement créé et activé
   */
  assign(userId: string, planId: string): Promise<UserSubscription> {
    return request<UserSubscription>(
      "POST",
      `/admin/users/${userId}/subscription`,
      { planId },
      true,
    );
  },

  /**
   * Modifie l'abonnement actif (plan et/ou date de début).
   * `endsAt` est recalculé côté backend à partir du plan et de startsAt.
   * 
   * @param userId - Identifiant UUID de l'utilisateur
   * @param data - Données du plan ou de la date de début modifiée
   * @returns L'abonnement actualisé
   */
  update(userId: string, data: { planId?: string; startsAt?: string }): Promise<UserSubscription> {
    return request<UserSubscription>(
      "PATCH",
      `/admin/users/${userId}/subscription`,
      data,
      true,
    );
  },

  /**
   * Résilie l'abonnement actif d'un utilisateur en mode différé.
   * Le backend remplit `canceledAt` mais conserve `status=ACTIVE` ; l'accès
   * reste effectif jusqu'à `endsAt`. Aucun prélèvement futur côté Stripe.
   * 
   * @param userId - Identifiant UUID de l'utilisateur
   * @returns Message de confirmation et abonnement mis à jour
   */
  cancel(userId: string): Promise<{ message: string; subscription: UserSubscription }> {
    return request<{ message: string; subscription: UserSubscription }>(
      "DELETE",
      `/admin/users/${userId}/subscription`,
      undefined,
      true,
    );
  },

  /**
   * Annule la résiliation différée d'un utilisateur (réactivation).
   * Remet `canceledAt` à null côté backend ; le cycle de facturation reprend.
   * 
   * @param userId - Identifiant UUID de l'utilisateur
   * @returns Message de succès et abonnement réactivé
   */
  resume(userId: string): Promise<{ message: string; subscription: UserSubscription }> {
    return request<{ message: string; subscription: UserSubscription }>(
      "POST",
      `/admin/users/${userId}/subscription/resume`,
      undefined,
      true,
    );
  },

  /**
   * Récupère l'historique des paiements Stripe de l'utilisateur ciblé.
   * Retourne un tableau vide si l'utilisateur n'a pas de Stripe customer.
   * 
   * @param userId - Identifiant UUID de l'utilisateur
   * @returns Liste des factures/paiements Stripe
   */
  getPayments(userId: string): Promise<Payment[]> {
    return request<Payment[]>(
      "GET",
      `/admin/users/${userId}/payments`,
      undefined,
      true,
    );
  },
};

// ─── Module Discover (catalogue Bunny public) ────────────────

export interface DiscoverEpisode {
  slug: string;
  name: string;
  hlsUrl: string | null;
  mp4Url: string | null;
  /** Champs éditoriaux servis en mode DB uniquement (absents en source `bunny`). */
  number?: number;
  /** Durée en minutes, null si inconnue. */
  duration?: number | null;
  synopsis?: string | null;
}

export interface DiscoverSeason {
  slug: string;
  name: string;
  episodes: DiscoverEpisode[];
}

export type DiscoverKind = "film" | "serie";

export interface DiscoverWorkSummary {
  slug: string;
  title: string;
  kind: DiscoverKind;
  /**
   * URL CDN absolue de l'affiche (zone des visuels), ou null si l'œuvre n'en a
   * pas. Absent en source catalogue `bunny`, qui ne connaît que l'arborescence
   * vidéo — d'où l'optionnalité. Le repli est `PlaceholderPoster`.
   */
  poster?: string | null;
}

/**
 * Référence légère vers un studio, injectée dans le payload détail des
 * œuvres servies en mode DB (CATALOGUE_SOURCE=db). En mode Bunny live,
 * ce champ vaut `null` (le catalogue Bunny ne passe pas par les entités).
 * Permet à la page détail d'afficher « Publié par {studio} » avec un lien
 * cliquable vers la chaîne studio publique.
 */
export interface DiscoverStudioRef {
  id: string;
  name: string;
  slug: string;
  logoUrl: string | null;
}

export interface DiscoverWork extends DiscoverWorkSummary {
  seasons: DiscoverSeason[];
  studio?: DiscoverStudioRef | null;
  /**
   * Métadonnées éditoriales de la fiche (mode DB uniquement). Toutes
   * optionnelles : les œuvres importées n'ont ni année, ni durée, ni genres —
   * la page détail masque chaque information absente.
   */
  synopsis?: string | null;
  year?: number | null;
  /** Films : durée en minutes. */
  duration?: number | null;
  /** Séries : nombre de saisons. */
  nbSeasons?: number;
  genres?: string[];
  countries?: string[];
  /** Films uniquement. */
  directors?: string[];
  /** Films uniquement. */
  cast?: string[];
  /** Manifest HLS de la bande-annonce, ou null. */
  trailerUrl?: string | null;
}

/**
 * Catalogue public alimenté par la Storage Zone Bunny `cinaftv-movies`.
 * Aucune auth requise pour list/get (la lecture vidéo passe directement
 * par l'URL HLS publique du CDN Bunny).
 *
 * Le `kind` (film | serie) est détecté côté backend via la présence de
 * sous-dossiers nommés CAS_X / SAISON_X / S_X.
 */
export const discover = {
  async list(params: { q?: string; kind?: DiscoverKind; page?: number; limit?: number } = {}): Promise<PaginatedResult<DiscoverWorkSummary>> {
    const qs = new URLSearchParams();
    if (params.q) qs.set("q", params.q);
    if (params.kind) qs.set("kind", params.kind);
    if (params.page) qs.set("page", String(params.page));
    if (params.limit) qs.set("limit", String(params.limit));
    const suffix = qs.toString() ? `?${qs.toString()}` : "";
    return request<PaginatedResult<DiscoverWorkSummary>>("GET", `/catalogue/discover${suffix}`, undefined, false);
  },

  get(slug: string): Promise<DiscoverWork> {
    return request<DiscoverWork>("GET", `/catalogue/discover/${encodeURIComponent(slug)}`, undefined, false);
  },
};

// ─── Module Admin Users ──────────────────────────────────────

/** Profil étendu d'un utilisateur côté admin (inclut isSuspended et abo actif). */
export interface AdminUser extends User {
  isSuspended?: boolean;
  /** Résumé de l'abonnement actif (ou null) — renvoyé uniquement par /api/admin/users (liste). */
  currentSubscription?: AdminUserSubscriptionSummary | null;
}

export interface AdminUserSubscriptionSummary {
  id: string;
  planName: string;
  status: string;
  startsAt: string;
  endsAt?: string | null;
  isCurrentlyActive: boolean;
}

export interface AdminUsersListParams {
  page?: number;
  limit?: number;
  search?: string;
  role?: string;
}

export const ADMIN_ROLES = [
  "ROLE_USER",
  "ROLE_ABONNE",
  "ROLE_CREATEUR",
  "ROLE_MODERATEUR",
  "ROLE_ADMIN",
] as const;
export type AdminRole = (typeof ADMIN_ROLES)[number];

/**
 * Endpoints d'administration des utilisateurs.
 * Tous protégés par ROLE_ADMIN côté backend (`access_control: ^/api/admin → ROLE_ADMIN`).
 */
export const adminUsers = {
  /**
   * Récupère la liste paginée et filtrée des utilisateurs du système.
   * 
   * @param params - Paramètres optionnels de pagination, recherche textuelle et filtrage par rôle
   * @returns Une promesse contenant la liste paginée d'utilisateurs enrichis
   */
  list(params: AdminUsersListParams = {}): Promise<PaginatedResult<AdminUser>> {
    const qs = new URLSearchParams();
    if (params.page) qs.set("page", String(params.page));
    if (params.limit) qs.set("limit", String(params.limit));
    if (params.search) qs.set("search", params.search);
    if (params.role) qs.set("role", params.role);
    const suffix = qs.toString() ? `?${qs.toString()}` : "";
    return request<PaginatedResult<AdminUser>>("GET", `/admin/users${suffix}`, undefined, true);
  },

  /**
   * Récupère les détails complets d'un utilisateur par son identifiant unique.
   * 
   * @param id - Identifiant UUID de l'utilisateur
   * @returns Les informations détaillées de l'utilisateur
   */
  get(id: string): Promise<AdminUser> {
    return request<AdminUser>("GET", `/admin/users/${id}`, undefined, true);
  },

  /**
   * Met à jour les coordonnées de base d'un utilisateur (email, nom, prénom, statut de vérification).
   * 
   * @param id - Identifiant UUID de l'utilisateur
   * @param data - Champs modifiables
   * @returns L'utilisateur mis à jour
   */
  update(id: string, data: Partial<Pick<AdminUser, "email" | "firstName" | "lastName" | "isVerified">>): Promise<AdminUser> {
    return request<AdminUser>("PATCH", `/admin/users/${id}`, data, true);
  },

  /**
   * Modifie les rôles de sécurité attribués à un utilisateur (ex: promotion admin ou studio).
   * 
   * @param id - Identifiant UUID de l'utilisateur
   * @param roles - Nouveau tableau de rôles de sécurité (ex: ['ROLE_USER', 'ROLE_CREATEUR'])
   * @returns L'utilisateur mis à jour avec ses nouveaux rôles
   */
  updateRole(id: string, roles: string[]): Promise<AdminUser> {
    return request<AdminUser>("PATCH", `/admin/users/${id}/role`, { roles }, true);
  },

  /**
   * Suspend temporairement l'accès au compte d'un utilisateur (blocage de connexion).
   * 
   * @param id - Identifiant UUID de l'utilisateur
   * @returns L'utilisateur avec son statut suspendu
   */
  suspend(id: string): Promise<AdminUser> {
    return request<AdminUser>("PATCH", `/admin/users/${id}/suspend`, {}, true);
  },

  /**
   * Réactive un compte utilisateur précédemment suspendu.
   * 
   * @param id - Identifiant UUID de l'utilisateur
   * @returns L'utilisateur réactivé
   */
  activate(id: string): Promise<AdminUser> {
    return request<AdminUser>("PATCH", `/admin/users/${id}/activate`, {}, true);
  },

  /**
   * Supprime définitivement un utilisateur de la base de données (action irréversible RGPD).
   * 
   * @param id - Identifiant UUID de l'utilisateur
   */
  remove(id: string): Promise<void> {
    return request<void>("DELETE", `/admin/users/${id}`, undefined, true);
  },

  /**
   * Télécharge un export CSV complet de la base utilisateurs (autorisé admin uniquement).
   * Réalisé par fetch + Blob pour injecter l'en-tête `Authorization: Bearer <token>`.
   * 
   * @returns Un objet Blob contenant les données CSV brutes
   * @throws {ApiError} En cas d'échec HTTP ou d'absence de droits
   */
  async exportCsv(): Promise<Blob> {
    const token = typeof window !== "undefined" ? localStorage.getItem("access_token") : null;
    const res = await fetch(`${BASE_URL}/admin/users/export`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    });
    if (!res.ok) {
      throw { message: `Export échoué (${res.status})`, statusCode: res.status } as ApiError;
    }
    return res.blob();
  },
};

// ─── Module Bunny Storage (admin) ───────────────────────────

export interface BunnyFile {
  path: string;
  name: string;
  size: number;
  lastModified: number | null;
  url: string;
  extension: string;
  type: "image" | "video" | "audio" | "document" | "other";
  contentType?: string | null;
}

export interface BunnyDirectory {
  path: string;
  name: string;
}

export interface BunnyListing {
  path: string;
  files: BunnyFile[];
  directories: BunnyDirectory[];
  note?: string;
  zone?: string;
}

export interface BunnyZonesInfo {
  zones: string[];
  default: string;
}

/**
 * Explorateur multi-zones du bucket Bunny Storage (réservé ROLE_ADMIN).
 * Chaque appel accepte un `zone` optionnel ; si omis, le backend utilise
 * la zone par défaut configurée (BUNNY_DEFAULT_ZONE).
 */
export const bunny = {
  /** Liste les Storage Zones configurées + nom de la zone par défaut. */
  listZones(): Promise<BunnyZonesInfo> {
    return request<BunnyZonesInfo>("GET", "/admin/bunny/zones", undefined, true);
  },

  /** Liste brute : dossiers + fichiers, filtrage optionnel par type. */
  listFiles(params: { path?: string; recursive?: boolean; type?: BunnyFile["type"]; zone?: string } = {}): Promise<BunnyListing> {
    const qs = new URLSearchParams();
    if (params.zone) qs.set("zone", params.zone);
    if (params.path) qs.set("path", params.path);
    if (params.recursive) qs.set("recursive", "1");
    if (params.type) qs.set("type", params.type);
    const suffix = qs.toString() ? `?${qs.toString()}` : "";
    return request<BunnyListing>("GET", `/admin/bunny/files${suffix}`, undefined, true);
  },

  /** Liste uniquement les images (récursif par défaut). */
  listImages(path = "", recursive = true, zone?: string): Promise<BunnyListing> {
    const qs = new URLSearchParams();
    if (zone) qs.set("zone", zone);
    if (path) qs.set("path", path);
    qs.set("recursive", recursive ? "1" : "0");
    return request<BunnyListing>("GET", `/admin/bunny/images?${qs.toString()}`, undefined, true);
  },

  /** Liste uniquement les vidéos stockées sur la Storage Zone (hors Bunny Stream). */
  listVideos(path = "", recursive = true, zone?: string): Promise<BunnyListing> {
    const qs = new URLSearchParams();
    if (zone) qs.set("zone", zone);
    if (path) qs.set("path", path);
    qs.set("recursive", recursive ? "1" : "0");
    return request<BunnyListing>("GET", `/admin/bunny/videos?${qs.toString()}`, undefined, true);
  },
};

// ─── Module Producteur / Studio ──────────────────────────────

/** Statut d'un contenu (film ou série). */
export type ContentStatus = "DRAFT" | "PUBLISHED" | "WITHDRAWN" | "PENDING_APPROVAL";

/** Statut d'une demande de retrait. */
export type WithdrawalStatus = "PENDING" | "APPROVED" | "REJECTED";

export type UpdateStudioPayload = {
  name?: string;
  description?: string;
};

/**
 * Studio détenu par un utilisateur ROLE_CREATEUR.
 */
export interface Studio {
  id: string;
  name: string;
  slug: string;
  description?: string | null;
  logoUrl?: string | null;
  bunnyFolder: string;
  isActive: boolean;
  /**
   * `true` une fois qu'au moins un contenu du studio a été approuvé par
   * un administrateur. Les studios créés en self-service démarrent à
   * `false` : leur premier publish part en `PENDING_APPROVAL` au lieu
   * de `PUBLISHED`.
   */
  isValidated?: boolean;
  ownerId: string;
  createdAt: string;
  updatedAt: string;
}



/** Statistiques agrégées renvoyées par GET /studio/me. */
export interface StudioStats {
  totalFilms: number;
  publishedFilms: number;
  draftFilms: number;
  withdrawnFilms: number;
  totalSeries: number;
  publishedSeries: number;
  draftSeries: number;
  withdrawnSeries: number;
  pendingWithdrawals: number;
  /**
   * Nombre d'utilisateurs abonnés à la chaîne du studio (follow gratuit
   * style YouTube). Renvoyé par `GET /api/studio/me`.
   */
  subscribersCount: number;
}

/** Réponse de GET /api/studio/me */
export interface StudioMeResponse {
  studio: Studio;
  stats: StudioStats;
}

/** Film tel que renvoyé par /api/studio/films (forme studio). */
export interface StudioFilm {
  id: string;
  title: string;
  slug: string;
  synopsis: string;
  year: number;
  duration: number;
  poster?: string | null;
  trailerVideoId?: string | null;
  bunnyVideoId?: string | null;
  views?: number;
  avgRating?: number | null;
  status: ContentStatus;
  studioId: string;
  publishedAt?: string | null;
  withdrawnAt?: string | null;
  createdAt: string;
  // toArray(true) renvoie aussi genres/countries/directors/cast
  genres?: Array<{ id?: string; name: string; slug?: string } | string>;
}

/** Série côté studio (renvoyée par /api/studio/series). */
export interface StudioSerie {
  id: string;
  title: string;
  slug: string;
  synopsis: string;
  year: number;
  poster?: string | null;
  trailerVideoId?: string | null;
  nbSeasons?: number | null;
  status: ContentStatus;
  studioId: string;
  publishedAt?: string | null;
  withdrawnAt?: string | null;
  createdAt: string;
  seasons?: StudioSeason[];
}

export interface StudioSeason {
  id: string;
  number: number;
  title?: string | null;
  synopsis?: string | null;
  episodes?: StudioEpisode[];
}

export interface StudioEpisode {
  id: string;
  number: number;
  title: string;
  synopsis?: string | null;
  duration?: number | null;
  bunnyVideoId?: string | null;
}

/** Demande de retrait (vue côté admin et studio). */
export interface WithdrawalRequest {
  id: string;
  studio?: { id: string; name: string; slug: string };
  studioId?: string;
  requestedBy?: { id: string; email: string; firstName: string; lastName: string };
  requestedById?: string;
  targetType: "film" | "serie";
  targetId: string;
  reason: string;
  status: WithdrawalStatus;
  reviewedBy?: { id: string; email: string; firstName?: string; lastName?: string } | null;
  reviewedById?: string | null;
  reviewedAt?: string | null;
  reviewNote?: string | null;
  createdAt: string;
}

/** Réponse renvoyée par POST /api/studio/upload */
export interface UploadResult {
  url: string;
  path: string;
  size: number;
  mimeType: string;
}

/** Payload pour la création d'un film. */
export interface CreateFilmPayload {
  title: string;
  synopsis: string;
  year: number;
  duration: number;
  slug?: string;
}

/** Payload pour la mise à jour partielle d'un film. */
export type UpdateFilmPayload = Partial<{
  title: string;
  synopsis: string;
  year: number;
  duration: number;
  poster: string | null;
  trailerVideoId: string | null;
  bunnyVideoId: string | null;
}>;

/** Payload pour la création d'une série. */
export interface CreateSeriePayload {
  title: string;
  synopsis: string;
  year: number;
  slug?: string;
}

/** Payload pour la mise à jour partielle d'une série. */
export type UpdateSeriePayload = Partial<{
  title: string;
  synopsis: string;
  year: number;
  poster: string | null;
  trailerVideoId: string | null;
}>;

export interface CreateSeasonPayload {
  number: number;
  title?: string;
  synopsis?: string;
}

export type UpdateSeasonPayload = Partial<CreateSeasonPayload>;

export interface CreateEpisodePayload {
  number: number;
  title: string;
  synopsis?: string;
  duration?: number;
  bunnyVideoId?: string;
}

export type UpdateEpisodePayload = Partial<CreateEpisodePayload>;

/**
 * Helper pour upload multipart avec progression et annulation.
 * Utilise XMLHttpRequest (et non `fetch`) car seul XHR expose les events
 * `upload.onprogress` nécessaires à une vraie barre de progression et un
 * `xhr.abort()` propre pour les annulations utilisateur.
 *
 * Errors mappées sur le même format `ApiError` que `handleResponse` pour
 * cohérence avec le reste du client.
 */
function uploadRequestXHR<T>(
  path: string,
  formData: FormData,
  options?: { onProgress?: (percent: number) => void; signal?: AbortSignal },
): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("POST", `${BASE_URL}${path}`);
    xhr.responseType = "text";

    const token = getAccessToken();
    xhr.setRequestHeader("Accept", "application/json");
    if (token) xhr.setRequestHeader("Authorization", `Bearer ${token}`);

    if (options?.onProgress) {
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
          options.onProgress!(Math.round((e.loaded * 100) / e.total));
        }
      };
    }

    if (options?.signal) {
      if (options.signal.aborted) {
        xhr.abort();
        return reject({ message: "Upload annulé", statusCode: 0 } as ApiError);
      }
      options.signal.addEventListener("abort", () => xhr.abort(), { once: true });
    }

    xhr.onload = () => {
      const status = xhr.status;
      const text = xhr.responseText || "";
      const isJson = (xhr.getResponseHeader("content-type") || "").includes("application/json");

      // 401 : nettoyage + redirect (calque de handleResponse).
      if (status === 401) {
        if (typeof window !== "undefined") {
          const authPaths = ["/login", "/register", "/forgot-password", "/reset-password"];
          const isAuthPage = authPaths.some((p) => window.location.pathname.startsWith(p));
          if (!isAuthPage) {
            localStorage.removeItem("access_token");
            window.location.href = "/login";
          }
        }
        return reject({ message: "Non authentifié", statusCode: 401 } as ApiError);
      }

      if (status < 200 || status >= 300) {
        let message = `Erreur ${status}`;
        let detail: string | undefined;
        if (isJson && text) {
          try {
            const data = JSON.parse(text);
            message = data.message || data.detail || data["hydra:description"] || message;
            detail = data.detail;
          } catch {
            /* keep default message */
          }
        }
        return reject({ message, statusCode: status, detail } as ApiError);
      }

      if (!text) return resolve(undefined as unknown as T);
      try {
        resolve(JSON.parse(text) as T);
      } catch {
        resolve(text as unknown as T);
      }
    };

    xhr.onerror = () => reject({ message: "Erreur réseau", statusCode: 0 } as ApiError);
    xhr.onabort = () => reject({ message: "Upload annulé", statusCode: 0 } as ApiError);

    xhr.send(formData);
  });
}

/**
 * Endpoints d'information et de configuration du studio du créateur connecté.
 * Requièrent le rôle ROLE_CREATEUR.
 */
export const studio = {
  /**
   * Récupère le profil du studio ainsi que les statistiques agrégées (films, séries, abonnés chaîne).
   * 
   * @returns Le studio courant et ses compteurs statistiques
   */
  getMe(): Promise<StudioMeResponse> {
    return request<StudioMeResponse>("GET", "/studio/me");
  },

  /**
   * Met à jour les informations textuelles du studio (nom, description).
   * 
   * @param payload - Données à mettre à jour
   * @returns Le studio mis à jour
   */
  updateMe(payload: UpdateStudioPayload): Promise<Studio> {
    return request<Studio>("PATCH", "/studio/me", payload);
  },
};

/**
 * Opérations CRUD et de publication de films côté studio/producteur.
 * Toutes les méthodes nécessitent ROLE_CREATEUR.
 */
export const studioFilms = {
  /**
   * Liste les films appartenant au studio connecté avec filtrage par statut et pagination.
   * 
   * @param params - Filtres optionnels (statut: DRAFT/PUBLISHED/WITHDRAWN/PENDING_APPROVAL, pagination)
   * @returns Liste paginée de films studio
   */
  list(params: {
    status?: ContentStatus;
    page?: number;
    limit?: number;
  } = {}): Promise<PaginatedResult<StudioFilm>> {
    const qs = new URLSearchParams();
    if (params.status) qs.set("status", params.status);
    if (params.page) qs.set("page", String(params.page));
    if (params.limit) qs.set("limit", String(params.limit));
    const suffix = qs.toString() ? `?${qs.toString()}` : "";
    return request<PaginatedResult<StudioFilm>>("GET", `/studio/films${suffix}`);
  },

  /**
   * Récupère le détail d'un film pour édition dans l'espace studio.
   * 
   * @param id - Identifiant UUID du film
   * @returns Le film sous sa forme complète studio
   */
  get(id: string): Promise<StudioFilm> {
    return request<StudioFilm>("GET", `/studio/films/${id}`);
  },

  /**
   * Crée un nouveau brouillon de film.
   * 
   * @param payload - Métadonnées de base du film (titre, synopsis, année, durée)
   * @returns Le film nouvellement créé (statut initial DRAFT)
   */
  create(payload: CreateFilmPayload): Promise<StudioFilm> {
    return request<StudioFilm>("POST", "/studio/films", payload);
  },

  /**
   * Met à jour partiellement les champs d'un film (titre, synopsis, affiche, vidéos).
   * 
   * @param id - Identifiant UUID du film
   * @param payload - Champs modifiés
   * @returns Le film actualisé
   */
  update(id: string, payload: UpdateFilmPayload): Promise<StudioFilm> {
    return request<StudioFilm>("PATCH", `/studio/films/${id}`, payload);
  },

  /**
   * Supprime définitivement un film appartenant au studio (autorisé uniquement si non publié).
   * 
   * @param id - Identifiant UUID du film
   */
  remove(id: string): Promise<void> {
    return request<void>("DELETE", `/studio/films/${id}`);
  },

  /**
   * Demande la publication d'un film.
   * Si le studio est déjà validé, le film passe directement en PUBLISHED.
   * Si le studio n'est pas encore validé, le film passe en PENDING_APPROVAL pour modération admin.
   * 
   * @param id - Identifiant UUID du film
   * @returns Le film avec son nouveau statut
   */
  publish(id: string): Promise<StudioFilm> {
    return request<StudioFilm>("POST", `/studio/films/${id}/publish`);
  },

  /**
   * Soumet une demande formelle de retrait pour un film actuellement en ligne.
   * Crée un enregistrement `WithdrawalRequest` examiné par l'équipe d'administration.
   * 
   * @param id - Identifiant UUID du film
   * @param reason - Motif justifiant la demande de dépublication
   * @returns La demande de retrait créée avec statut PENDING
   */
  withdraw(id: string, reason: string): Promise<WithdrawalRequest> {
    return request<WithdrawalRequest>("POST", `/studio/films/${id}/withdraw`, { reason });
  },
};

/**
 * Opérations CRUD séries, saisons et épisodes côté studio.
 * Permet la gestion hiérarchique de l'arborescence des séries d'un créateur.
 */
export const studioSeries = {
  /**
   * Liste les séries du studio connecté avec filtres de statut et pagination.
   * 
   * @param params - Filtres (status, page, limit)
   * @returns Liste paginée des séries
   */
  list(params: {
    status?: ContentStatus;
    page?: number;
    limit?: number;
  } = {}): Promise<PaginatedResult<StudioSerie>> {
    const qs = new URLSearchParams();
    if (params.status) qs.set("status", params.status);
    if (params.page) qs.set("page", String(params.page));
    if (params.limit) qs.set("limit", String(params.limit));
    const suffix = qs.toString() ? `?${qs.toString()}` : "";
    return request<PaginatedResult<StudioSerie>>("GET", `/studio/series${suffix}`);
  },

  /**
   * Récupère la fiche détaillée d'une série avec ses saisons et épisodes.
   * 
   * @param id - Identifiant UUID de la série
   * @returns La série complète
   */
  get(id: string): Promise<StudioSerie> {
    return request<StudioSerie>("GET", `/studio/series/${id}`);
  },

  /**
   * Crée un nouveau projet de série en brouillon.
   * 
   * @param payload - Données initiales (titre, synopsis, année)
   * @returns La série créée au statut DRAFT
   */
  create(payload: CreateSeriePayload): Promise<StudioSerie> {
    return request<StudioSerie>("POST", "/studio/series", payload);
  },

  /**
   * Met à jour les métadonnées globales de la série (affiche, trailer, synopsis).
   * 
   * @param id - Identifiant UUID de la série
   * @param payload - Données partielles
   * @returns La série mise à jour
   */
  update(id: string, payload: UpdateSeriePayload): Promise<StudioSerie> {
    return request<StudioSerie>("PATCH", `/studio/series/${id}`, payload);
  },

  /**
   * Supprime une série du studio.
   * 
   * @param id - Identifiant UUID de la série
   */
  remove(id: string): Promise<void> {
    return request<void>("DELETE", `/studio/series/${id}`);
  },

  /**
   * Publie la série ou soumet une demande d'approbation selon le statut de validation du studio.
   * 
   * @param id - Identifiant UUID de la série
   * @returns La série avec son nouveau statut
   */
  publish(id: string): Promise<StudioSerie> {
    return request<StudioSerie>("POST", `/studio/series/${id}/publish`);
  },

  /**
   * Demande le retrait du catalogue d'une série en ligne.
   * 
   * @param id - Identifiant UUID de la série
   * @param reason - Motif de dépublication
   * @returns La demande de retrait PENDING
   */
  withdraw(id: string, reason: string): Promise<WithdrawalRequest> {
    return request<WithdrawalRequest>("POST", `/studio/series/${id}/withdraw`, { reason });
  },

  // ── Saisons ──

  /**
   * Ajoute une nouvelle saison à une série existante.
   * 
   * @param serieId - Identifiant UUID de la série parente
   * @param payload - Numéro, titre optionnel et synopsis de la saison
   * @returns La saison créée
   */
  createSeason(serieId: string, payload: CreateSeasonPayload): Promise<StudioSeason> {
    return request<StudioSeason>("POST", `/studio/series/${serieId}/seasons`, payload);
  },

  /**
   * Modifie les informations d'une saison.
   * 
   * @param serieId - Identifiant UUID de la série
   * @param seasonId - Identifiant UUID de la saison
   * @param payload - Modifications apportées
   * @returns La saison mise à jour
   */
  updateSeason(
    serieId: string,
    seasonId: string,
    payload: UpdateSeasonPayload,
  ): Promise<StudioSeason> {
    return request<StudioSeason>(
      "PATCH",
      `/studio/series/${serieId}/seasons/${seasonId}`,
      payload,
    );
  },

  /**
   * Supprime une saison et l'ensemble de ses épisodes associés.
   * 
   * @param serieId - Identifiant UUID de la série
   * @param seasonId - Identifiant UUID de la saison
   */
  removeSeason(serieId: string, seasonId: string): Promise<void> {
    return request<void>("DELETE", `/studio/series/${serieId}/seasons/${seasonId}`);
  },

  // ── Épisodes ──

  /**
   * Ajoute un nouvel épisode à une saison donnée.
   * 
   * @param serieId - Identifiant UUID de la série
   * @param seasonId - Identifiant UUID de la saison parente
   * @param payload - Numéro, titre, durée et synopsis de l'épisode
   * @returns L'épisode créé
   */
  createEpisode(
    serieId: string,
    seasonId: string,
    payload: CreateEpisodePayload,
  ): Promise<StudioEpisode> {
    return request<StudioEpisode>(
      "POST",
      `/studio/series/${serieId}/seasons/${seasonId}/episodes`,
      payload,
    );
  },

  /**
   * Met à jour les détails d'un épisode ou lui associe un fichier vidéo BunnyCDN (`bunnyVideoId`).
   * 
   * @param serieId - Identifiant UUID de la série
   * @param seasonId - Identifiant UUID de la saison
   * @param episodeId - Identifiant UUID de l'épisode
   * @param payload - Données partielles
   * @returns L'épisode mis à jour
   */
  updateEpisode(
    serieId: string,
    seasonId: string,
    episodeId: string,
    payload: UpdateEpisodePayload,
  ): Promise<StudioEpisode> {
    return request<StudioEpisode>(
      "PATCH",
      `/studio/series/${serieId}/seasons/${seasonId}/episodes/${episodeId}`,
      payload,
    );
  },

  /**
   * Supprime un épisode spécifique.
   * 
   * @param serieId - Identifiant UUID de la série
   * @param seasonId - Identifiant UUID de la saison
   * @param episodeId - Identifiant UUID de l'épisode
   */
  removeEpisode(serieId: string, seasonId: string, episodeId: string): Promise<void> {
    return request<void>(
      "DELETE",
      `/studio/series/${serieId}/seasons/${seasonId}/episodes/${episodeId}`,
    );
  },
};

/** Cible d'un upload studio (convention "1 dossier par projet"). */
export type StudioUploadTarget =
  | { type: "film"; id: string; purpose: "poster" | "trailer" | "video" }
  | { type: "serie"; id: string; purpose: "poster" | "trailer" }
  | { type: "episode"; serieId: string; seasonId: string; episodeId: string; purpose: "video" };

/** Upload Bunny côté studio (multipart, vraie progression XHR, annulable). */
export const studioUploads = {
  upload(
    file: File,
    target: StudioUploadTarget,
    onProgress?: (percent: number) => void,
    signal?: AbortSignal,
  ): Promise<UploadResult> {
    const fd = new FormData();
    fd.append("file", file);
    fd.append("targetType", target.type);
    fd.append("purpose", target.purpose);
    // L'ID transmis dépend du type de cible.
    const targetId = target.type === "episode" ? target.episodeId : target.id;
    fd.append("targetId", targetId);
    return uploadRequestXHR<UploadResult>("/studio/upload", fd, { onProgress, signal });
  },
};

// ─── Module Admin Films / Séries / Withdrawals ──────────────

/** Forme de film/série côté admin (avec studio enrichi). */
export interface AdminFilm extends StudioFilm {
  studio?: { id: string; name: string; slug: string } | null;
}

export interface AdminSerie extends StudioSerie {
  studio?: { id: string; name: string; slug: string } | null;
}

export interface AdminFilmsListParams {
  status?: ContentStatus;
  studioId?: string;
  search?: string;
  page?: number;
  limit?: number;
}

export type AdminFilmUpdatePayload = Partial<{
  title: string;
  synopsis: string;
  year: number;
  duration: number;
  poster: string | null;
  trailerVideoId: string | null;
  bunnyVideoId: string | null;
  status: ContentStatus;
  studioId: string | null;
}>;

export type AdminSerieUpdatePayload = Partial<{
  title: string;
  synopsis: string;
  year: number;
  poster: string | null;
  trailerVideoId: string | null;
  status: ContentStatus;
  studioId: string | null;
}>;

/**
 * Endpoints d'administration globale pour les films (requièrent ROLE_ADMIN).
 */
export const adminFilms = {
  /**
   * Liste l'ensemble des films de la plateforme avec filtres multi-critères et pagination.
   * 
   * @param params - Filtres (statut, studioId, recherche, pagination)
   * @returns Liste paginée de films au format admin enrichi
   */
  list(params: AdminFilmsListParams = {}): Promise<PaginatedResult<AdminFilm>> {
    const qs = new URLSearchParams();
    if (params.status) qs.set("status", params.status);
    if (params.studioId) qs.set("studioId", params.studioId);
    if (params.search) qs.set("search", params.search);
    if (params.page) qs.set("page", String(params.page));
    if (params.limit) qs.set("limit", String(params.limit));
    const suffix = qs.toString() ? `?${qs.toString()}` : "";
    return request<PaginatedResult<AdminFilm>>("GET", `/admin/films${suffix}`);
  },

  /**
   * Récupère le détail d'un film pour modération ou supervision administrative.
   * 
   * @param id - Identifiant UUID du film
   * @returns Le film enrichi des informations du studio propriétaire
   */
  get(id: string): Promise<AdminFilm> {
    return request<AdminFilm>("GET", `/admin/films/${id}`);
  },

  /**
   * Modifie unilatéralement les attributs ou le statut d'un film (ex: correction, suspension).
   * 
   * @param id - Identifiant UUID du film
   * @param payload - Champs à mettre à jour
   * @returns Le film modifié
   */
  update(id: string, payload: AdminFilmUpdatePayload): Promise<AdminFilm> {
    return request<AdminFilm>("PATCH", `/admin/films/${id}`, payload);
  },

  /**
   * Supprime définitivement un film et ses liaisons en base de données.
   * 
   * @param id - Identifiant UUID du film
   */
  remove(id: string): Promise<void> {
    return request<void>("DELETE", `/admin/films/${id}`);
  },
};

/**
 * Endpoints d'administration globale pour les séries (requièrent ROLE_ADMIN).
 */
export const adminSeries = {
  /**
   * Liste toutes les séries de la plateforme avec pagination et filtres.
   * 
   * @param params - Filtres (statut, studioId, recherche, pagination)
   * @returns Liste paginée des séries enrichies
   */
  list(params: AdminFilmsListParams = {}): Promise<PaginatedResult<AdminSerie>> {
    const qs = new URLSearchParams();
    if (params.status) qs.set("status", params.status);
    if (params.studioId) qs.set("studioId", params.studioId);
    if (params.search) qs.set("search", params.search);
    if (params.page) qs.set("page", String(params.page));
    if (params.limit) qs.set("limit", String(params.limit));
    const suffix = qs.toString() ? `?${qs.toString()}` : "";
    return request<PaginatedResult<AdminSerie>>("GET", `/admin/series${suffix}`);
  },

  /**
   * Récupère une série par son identifiant côté administration.
   * 
   * @param id - Identifiant UUID de la série
   * @returns La série complète avec son studio
   */
  get(id: string): Promise<AdminSerie> {
    return request<AdminSerie>("GET", `/admin/series/${id}`);
  },

  /**
   * Modifie les données ou le statut d'une série par l'administrateur.
   * 
   * @param id - Identifiant UUID de la série
   * @param payload - Données partielles
   * @returns La série modifiée
   */
  update(id: string, payload: AdminSerieUpdatePayload): Promise<AdminSerie> {
    return request<AdminSerie>("PATCH", `/admin/series/${id}`, payload);
  },

  /**
   * Supprime définitivement une série de la plateforme.
   * 
   * @param id - Identifiant UUID de la série
   */
  remove(id: string): Promise<void> {
    return request<void>("DELETE", `/admin/series/${id}`);
  },
};

/**
 * Liste des studios actifs (utile pour les filtres admin).
 * Endpoint backend : GET /api/admin/studios (créé par Agent 4).
 */
export const adminStudios = {
  /**
   * Récupère la liste de tous les studios existants pour alimenter les listes déroulantes de filtrage.
   * 
   * @returns Un objet contenant le tableau de studios
   */
  list(): Promise<{ data: Studio[] }> {
    return request<{ data: Studio[] }>("GET", "/admin/studios");
  },
};

export interface AdminWithdrawalsListParams {
  status?: WithdrawalStatus;
  page?: number;
  limit?: number;
}

export interface AdminWithdrawalApproveResponse {
  withdrawal: WithdrawalRequest;
  target: StudioFilm | StudioSerie;
}

/**
 * Gestion du workflow de retrait d'œuvres côté administration.
 * Permet d'approuver (dépublier) ou de rejeter une demande de retrait soumise par un studio.
 */
export const adminWithdrawals = {
  /**
   * Liste les demandes de retrait d'œuvres (PENDING, APPROVED, REJECTED).
   * 
   * @param params - Filtre de statut et pagination
   * @returns Liste paginée des demandes de retrait
   */
  list(params: AdminWithdrawalsListParams = {}): Promise<PaginatedResult<WithdrawalRequest>> {
    const qs = new URLSearchParams();
    if (params.status) qs.set("status", params.status);
    if (params.page) qs.set("page", String(params.page));
    if (params.limit) qs.set("limit", String(params.limit));
    const suffix = qs.toString() ? `?${qs.toString()}` : "";
    return request<PaginatedResult<WithdrawalRequest>>("GET", `/admin/withdrawals${suffix}`);
  },

  /**
   * Récupère les détails d'une demande de retrait spécifique.
   * 
   * @param id - Identifiant UUID de la demande
   * @returns La demande complète
   */
  get(id: string): Promise<WithdrawalRequest> {
    return request<WithdrawalRequest>("GET", `/admin/withdrawals/${id}`);
  },

  /**
   * Approuve la demande de retrait : passe le contenu en WITHDRAWN et horodate la décision.
   * 
   * @param id - Identifiant UUID de la demande
   * @param reviewNote - Commentaire administratif optionnel expliquant la décision
   * @returns La demande validée ainsi que l'entité cible mise à jour
   */
  approve(id: string, reviewNote?: string): Promise<AdminWithdrawalApproveResponse> {
    return request<AdminWithdrawalApproveResponse>(
      "POST",
      `/admin/withdrawals/${id}/approve`,
      reviewNote ? { reviewNote } : {},
    );
  },

  /**
   * Rejette la demande de retrait : le contenu reste en ligne (PUBLISHED).
   * 
   * @param id - Identifiant UUID de la demande
   * @param reviewNote - Motif du refus communiqué au producteur
   * @returns La demande avec le statut REJECTED
   */
  reject(id: string, reviewNote?: string): Promise<WithdrawalRequest> {
    return request<WithdrawalRequest>(
      "POST",
      `/admin/withdrawals/${id}/reject`,
      reviewNote ? { reviewNote } : {},
    );
  },
};

// ─── Module Onboarding Studio (self-service) ────────────────

/** Payload pour la création self-service d'un studio. */
export interface CreateStudioPayload {
  name: string;
  description: string;
}

/**
 * Parcours self-service "Je suis producteur" : un utilisateur logué
 * (non-admin) crée son propre studio via le formulaire de candidature/onboarding.
 *
 * Endpoint backend : POST /api/studio/onboarding.
 * Accessible à tout utilisateur authentifié (ROLE_USER suffit) ; l'admin est
 * explicitement refusé afin de préserver l'étanchéité des rôles.
 */
export const studioOnboarding = {
  /**
   * Crée un nouveau studio pour l'utilisateur connecté et lui attribue ROLE_CREATEUR.
   * 
   * @param payload - Nom et description du studio
   * @returns Le studio créé
   */
  create(payload: CreateStudioPayload): Promise<Studio> {
    return request<Studio>("POST", "/studio/onboarding", payload, true);
  },
};

// ─── Module Admin Approvals ─────────────────────────────────

/**
 * Élément de la file d'approbation : film ou série en `PENDING_APPROVAL`.
 * Le backend ajoute deux champs au payload standard de `toArray()` :
 *   - `kind` : 'film' | 'serie'
 *   - `studio` : { id, name, slug, isValidated }
 */
export interface AdminApprovalItem {
  kind: "film" | "serie";
  id: string;
  title: string;
  slug: string;
  synopsis: string;
  year: number;
  duration?: number;
  poster?: string | null;
  status: ContentStatus;
  createdAt: string;
  studio: {
    id: string;
    name: string;
    slug: string;
    isValidated: boolean;
  } | null;
}

/**
 * Gestion de la file d'attente d'approbation des œuvres soumises par de nouveaux studios.
 * Requièrent ROLE_ADMIN.
 */
export const adminApprovals = {
  /**
   * Liste paginée des films et séries en attente d'approbation préalable (`PENDING_APPROVAL`).
   * 
   * @param params - Options de pagination (page, limit)
   * @returns Liste paginée d'éléments soumis à examen
   */
  list(params: { page?: number; limit?: number } = {}): Promise<
    PaginatedResult<AdminApprovalItem>
  > {
    const qs = new URLSearchParams();
    if (params.page) qs.set("page", String(params.page));
    if (params.limit) qs.set("limit", String(params.limit));
    const suffix = qs.toString() ? `?${qs.toString()}` : "";
    return request<PaginatedResult<AdminApprovalItem>>(
      "GET",
      `/admin/approvals${suffix}`,
    );
  },

  /**
   * Valide un film en attente : passe son statut à PUBLISHED et valide le studio s'il ne l'était pas.
   * 
   * @param id - Identifiant UUID du film
   * @returns Le film validé
   */
  approveFilm(id: string): Promise<StudioFilm> {
    return request<StudioFilm>("PATCH", `/admin/films/${id}/approve`, {});
  },

  /**
   * Rejette un film en attente : le repasse en DRAFT ou le refuse avec motif.
   * 
   * @param id - Identifiant UUID du film
   * @param reason - Raison du refus
   * @returns Le film mis à jour
   */
  rejectFilm(id: string, reason?: string): Promise<StudioFilm> {
    return request<StudioFilm>(
      "PATCH",
      `/admin/films/${id}/reject`,
      reason ? { reason } : {},
    );
  },

  /**
   * Valide une série en attente d'approbation et la rend publique.
   * 
   * @param id - Identifiant UUID de la série
   * @returns La série validée
   */
  approveSerie(id: string): Promise<StudioSerie> {
    return request<StudioSerie>("PATCH", `/admin/series/${id}/approve`, {});
  },

  /**
   * Rejette une série en attente d'approbation.
   * 
   * @param id - Identifiant UUID de la série
   * @param reason - Motif explicatif du rejet
   * @returns La série mise à jour
   */
  rejectSerie(id: string, reason?: string): Promise<StudioSerie> {
    return request<StudioSerie>(
      "PATCH",
      `/admin/series/${id}/reject`,
      reason ? { reason } : {},
    );
  },
};

// ─── Module Studios publics (vue "chaîne") ──────────────────

/**
 * Représentation publique d'un studio, telle que renvoyée par les endpoints
 * `/api/studios/*`. Différente du type `Studio` plus haut (qui est utilisé
 * par l'espace authentifié `/api/studio/*` et expose `ownerId`).
 *
 * - Ne contient AUCUNE donnée privée du studio (pas de `ownerId`, pas de
 *   credentials, etc.).
 * - Ajoute deux compteurs agrégés côté backend : `publishedFilmsCount` et
 *   `publishedSeriesCount`, pratiques pour la tuile "façon YouTube".
 */
export interface StudioPublic {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  logoUrl: string | null;
  bunnyFolder: string;
  isActive: boolean;
  isValidated: boolean;
  createdAt: string;
  updatedAt: string;
  publishedFilmsCount: number;
  publishedSeriesCount: number;
  /**
   * Compteur d'abonnés « façon YouTube » (follow gratuit, distinct de
   * l'abonnement payant Stripe à la plateforme). Toujours renvoyé par
   * `GET /api/studios`, `GET /api/studios/search` et `GET /api/studios/{slug}`.
   */
  subscribersCount: number;
}

/**
 * Œuvre (film ou série) publiée par un studio, telle que renvoyée par
 * `GET /api/studios/{slug}/works`. Forme minimale optimisée pour
 * l'affichage en grille sur la page chaîne publique.
 */
export interface Work {
  id: string;
  slug: string;
  title: string;
  kind: "film" | "serie";
  poster: string | null;
  year: number | null;
  createdAt: string;
}

/**
 * Endpoints publics « chaîne studio » (aucune authentification requise).
 * Les listes utilisent la pagination Hydra standard (`hydra:member` /
 * `hydra:totalItems`) — normalisées via `hydraToPage()`.
 */
export const studios = {
  /** GET /api/studios?page=&itemsPerPage= — Studios publics triés par nom asc */
  async list(opts: { page?: number; itemsPerPage?: number } = {}): Promise<PaginatedResult<StudioPublic>> {
    const page = opts.page ?? 1;
    const itemsPerPage = opts.itemsPerPage ?? 30;
    const qs = new URLSearchParams({
      page: String(page),
      itemsPerPage: String(itemsPerPage),
    });
    const raw = await request<Record<string, unknown>>(
      "GET",
      `/studios?${qs.toString()}`,
      undefined,
      false,
    );
    return hydraToPage<StudioPublic>(raw, page, itemsPerPage);
  },

  /** GET /api/studios/{slug} — Détail d'un studio public (404 si non public) */
  get(slug: string): Promise<StudioPublic> {
    return request<StudioPublic>("GET", `/studios/${encodeURIComponent(slug)}`, undefined, false);
  },

  /**
   * GET /api/studios/{slug}/works — Œuvres publiées du studio (triées par
   * createdAt desc). Le filtre `kind` accepte `film`, `serie` ou `all`.
   */
  async getWorks(
    slug: string,
    opts: { page?: number; itemsPerPage?: number; kind?: "film" | "serie" | "all" } = {},
  ): Promise<PaginatedResult<Work>> {
    const page = opts.page ?? 1;
    const itemsPerPage = opts.itemsPerPage ?? 30;
    const qs = new URLSearchParams({
      page: String(page),
      itemsPerPage: String(itemsPerPage),
    });
    if (opts.kind) qs.set("kind", opts.kind);
    const raw = await request<Record<string, unknown>>(
      "GET",
      `/studios/${encodeURIComponent(slug)}/works?${qs.toString()}`,
      undefined,
      false,
    );
    return hydraToPage<Work>(raw, page, itemsPerPage);
  },

  /**
   * GET /api/studios/search?q= — Recherche rapide (max 20 résultats, tableau
   * plat). Le backend impose min. 2 caractères et renvoie `[]` sinon ; on
   * applique la même règle côté client pour éviter des appels inutiles.
   */
  async search(q: string): Promise<StudioPublic[]> {
    if (q.trim().length < 2) return [];
    const qs = new URLSearchParams({ q: q.trim() });
    return request<StudioPublic[]>(
      "GET",
      `/studios/search?${qs.toString()}`,
      undefined,
      false,
    );
  },

  /**
   * POST /api/studios/{slug}/subscribe — Abonne l'utilisateur authentifié
   * au studio (« follow YouTube », gratuit, distinct de l'abonnement Stripe).
   *
   * Idempotent côté backend : un appel répété renvoie 200 (au lieu de 201)
   * avec exactement le même payload. Le client traite les deux statuts de
   * la même façon, d'où la signature de retour unique.
   *
   * Erreurs typiques : 401 (non authentifié) → géré globalement par
   * `handleResponse` ; 404 si le studio n'est pas public.
   */
  subscribe(slug: string): Promise<{ isSubscribed: true; subscribersCount: number }> {
    return request<{ isSubscribed: true; subscribersCount: number }>(
      "POST",
      `/studios/${encodeURIComponent(slug)}/subscribe`,
      undefined,
      true,
    );
  },

  /**
   * DELETE /api/studios/{slug}/subscribe — Désabonne l'utilisateur du studio.
   *
   * Idempotent : retourne 204 quel que soit l'état antérieur (abonné ou non).
   * `handleResponse` traite déjà le 204 No Content en renvoyant `undefined`,
   * d'où l'utilisation possible de `request<void>` sans helper raw dédié.
   */
  unsubscribe(slug: string): Promise<void> {
    return request<void>(
      "DELETE",
      `/studios/${encodeURIComponent(slug)}/subscribe`,
      undefined,
      true,
    );
  },

  /**
   * GET /api/studios/{slug}/subscription — Récupère l'état d'abonnement
   * de l'utilisateur authentifié vis-à-vis du studio. Appelé au montage de
   * la page chaîne pour afficher le bouton dans le bon état initial.
   */
  getSubscription(slug: string): Promise<{ isSubscribed: boolean }> {
    return request<{ isSubscribed: boolean }>(
      "GET",
      `/studios/${encodeURIComponent(slug)}/subscription`,
      undefined,
      true,
    );
  },
};

// ─── Module paiements ───────────────────────────────────────

/**
 * Services liés à la consultation de l'historique des paiements et téléchargement des factures.
 */
export const payments = {
  /**
   * Récupère la liste de tous les paiements et facturations de l'utilisateur connecté.
   * 
   * @returns Un tableau d'enregistrements de paiement (`PaymentRecord[]`)
   */
  async getHistory(): Promise<PaymentRecord[]> {
    const data = await request<{ payments: PaymentRecord[] }>("GET", "/payments", undefined, true);
    return data.payments;
  },

  /**
   * Récupère les métadonnées et l'URL du PDF d'une facture spécifique.
   * 
   * @param paymentId - Identifiant du paiement
   * @returns Les informations de la facture associée (`InvoiceInfo`)
   */
  async getInvoice(paymentId: string): Promise<InvoiceInfo> {
    const data = await request<{ invoice: InvoiceInfo }>(
      "GET",
      `/payments/${paymentId}/invoice`,
      undefined,
      true
    );
    return data.invoice;
  },
};

