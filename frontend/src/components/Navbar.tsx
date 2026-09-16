"use client";

/**
 * ============================================================
 * CINAF v2 — Barre de navigation principale (Navbar)
 * ============================================================
 * Composant de navigation supérieure fixé (`sticky-top`) présent sur l'ensemble
 * des pages publiques de la plateforme.
 * 
 * Fonctionnalités & Logique métier :
 * - Marque CINAF et navigation vers Catalogue, Films, Séries et Studios.
 * - Distinction d'état actif : Le lien `Studios` s'allume en doré lorsque `pathname`
 *   vaut `/studios` ou `/studios/[slug]`, sans interférer avec l'espace `/studio`.
 * - Recherche rapide : Champ `SearchBar` compact intégré pour ordinateur.
 * - Menu utilisateur connecté / anonyme :
 *   - Si non connecté : Boutons "Connexion" et "S'inscrire".
 *   - Si connecté : Avatar personnalisé avec initiales et menu déroulant complet :
 *     - "Mon profil" (`/profile`)
 *     - "Mon abonnement" (`/mon-abonnement`)
 *     - "Studio" (`/studio`) si l'utilisateur est producteur (`isStudio(user)`).
 *     - "Administration" (`/admin`) si l'utilisateur est administrateur (`isAdmin(user)`).
 *     - "Déconnexion" avec invalidation de session.
 * - Fermeture automatique du volet mobile Bootstrap (`closeCollapse`) lors du clic sur un lien.
 */

import { useEffect, useRef } from "react";
import Link from "next/link";
import { useRouter, usePathname } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { isAdmin, isStudio } from "@/lib/auth-helpers";
import SearchBar from "./SearchBar";

/**
 * Barre de navigation supérieure globale.
 * 
 * @returns Le composant de navigation responsive
 */
export default function Navbar() {
  // Consommation du contexte d'authentification pour adapter les menus
  const { user, isAuthenticated, logout } = useAuth();
  const router = useRouter();

  // Détection de l'onglet actif Studios public (/studios) en excluant le back-office (/studio)
  const pathname = usePathname() || "";
  const isStudiosActive =
    pathname === "/studios" || pathname.startsWith("/studios/");

  // Référence vers le conteneur du menu mobile Bootstrap
  const collapseRef = useRef<HTMLDivElement>(null);

  /**
   * Ferme programmatiquement le menu repliable mobile de Bootstrap après navigation.
   */
  const closeCollapse = () => {
    if (collapseRef.current && collapseRef.current.classList.contains("show")) {
      import("bootstrap/dist/js/bootstrap.bundle.min.js").then((bs) => {
        const collapseEl = collapseRef.current;
        if (collapseEl) {
          const bsCollapse = bs.Collapse.getInstance(collapseEl);
          bsCollapse?.hide();
        }
      });
    }
  };

  /**
   * Déconnecte l'utilisateur, ferme le volet mobile et redirige vers la page de connexion.
   */
  const handleLogout = async () => {
    closeCollapse();
    await logout();
    router.push("/login");
  };

  /**
   * Calcule les initiales de l'utilisateur connecté pour l'avatar (ex: "FT").
   * 
   * @returns Deux lettres d'initiales en majuscule, ou le premier caractère de l'email
   */
  const getInitials = (): string => {
    if (!user) return "?";
    const f = user.firstName?.[0] ?? "";
    const l = user.lastName?.[0] ?? "";
    return (f + l).toUpperCase() || user.email[0].toUpperCase();
  };

  /**
   * Génère le libellé complet affiché pour l'utilisateur (Prénom Nom ou email).
   * 
   * @returns La chaîne de caractères du nom
   */
  const getDisplayName = (): string => {
    if (!user) return "";
    if (user.firstName || user.lastName) {
      return `${user.firstName ?? ""} ${user.lastName ?? ""}`.trim();
    }
    return user.email;
  };

  // Chargement côté client de Bootstrap pour garantir le fonctionnement des dropdowns
  useEffect(() => {
    import("bootstrap/dist/js/bootstrap.bundle.min.js");
  }, []);

  return (
    <nav className="navbar navbar-expand-lg cinaf-navbar sticky-top" style={{ zIndex: 1030 }}>
      <div className="container">
        {/* Logo CINAF cliquable */}
        <Link href="/" className="navbar-brand cinaf-logo-text" onClick={closeCollapse}>
          CINAF
        </Link>

        {/* Bouton burger pour smartphones et tablettes */}
        <button
          className="navbar-toggler border-0"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#navbarMain"
          aria-controls="navbarMain"
          aria-expanded="false"
          aria-label="Ouvrir le menu"
        >
          <i className="bi bi-list" style={{ fontSize: "1.5rem", color: "var(--cinaf-text)" }} />
        </button>

        {/* Menu principal repliable */}
        <div className="collapse navbar-collapse" id="navbarMain" ref={collapseRef}>
          {/* Liens de navigation gauche */}
          <ul className="navbar-nav me-auto mb-2 mb-lg-0">
            <li className="nav-item">
              <Link href="/catalogue" className="nav-link" onClick={closeCollapse}>
                Catalogue
              </Link>
            </li>
            <li className="nav-item">
              <Link href="/films" className="nav-link" onClick={closeCollapse}>
                Films
              </Link>
            </li>
            <li className="nav-item">
              <Link href="/series" className="nav-link" onClick={closeCollapse}>
                Séries
              </Link>
            </li>
            {/* Lien Studios — vue publique des chaînes de studios */}
            <li className="nav-item">
              <Link
                href="/studios"
                className={`nav-link${isStudiosActive ? " active" : ""}`}
                onClick={closeCollapse}
                style={isStudiosActive ? { color: "var(--cinaf-gold)" } : undefined}
              >
                Studios
              </Link>
            </li>
          </ul>

          {/* Section droite : Recherche et statut utilisateur */}
          <div className="d-flex align-items-center gap-3">
            {/* Barre de recherche rapide (écrans larges) */}
            <div className="d-none d-lg-block">
              <SearchBar mode="redirect" compact />
            </div>

            {isAuthenticated ? (
              /* Menu déroulant profil pour utilisateur authentifié */
              <div className="dropdown">
                <button
                  className="btn d-flex align-items-center gap-2 p-0 border-0 bg-transparent"
                  data-bs-toggle="dropdown"
                  aria-expanded="false"
                  style={{ cursor: "pointer" }}
                >
                  {/* Pastille circulaire avec initiales */}
                  <span
                    style={{
                      width: 34,
                      height: 34,
                      borderRadius: "50%",
                      background: "linear-gradient(135deg, var(--cinaf-gold), #a07830)",
                      color: "#000",
                      fontWeight: 700,
                      fontSize: "0.75rem",
                      display: "flex",
                      alignItems: "center",
                      justifyContent: "center",
                      flexShrink: 0,
                    }}
                  >
                    {getInitials()}
                  </span>
                  <span
                    className="d-none d-lg-inline"
                    style={{
                      color: "var(--cinaf-text)",
                      fontSize: "0.875rem",
                      fontWeight: 500,
                      maxWidth: 130,
                      overflow: "hidden",
                      textOverflow: "ellipsis",
                      whiteSpace: "nowrap",
                    }}
                  >
                    {getDisplayName()}
                  </span>
                  <i
                    className="bi bi-chevron-down d-none d-lg-inline"
                    style={{ fontSize: "0.7rem", color: "var(--cinaf-text-muted)" }}
                  />
                </button>

                <ul className="dropdown-menu dropdown-menu-end mt-2">
                  <li>
                    <Link
                      href="/profile"
                      className="dropdown-item d-flex align-items-center gap-2"
                      onClick={closeCollapse}
                    >
                      <i className="bi bi-person" style={{ color: "var(--cinaf-gold)" }} />
                      Mon profil
                    </Link>
                  </li>

                  <li>
                    <Link
                      href="/mon-abonnement"
                      className="dropdown-item d-flex align-items-center gap-2"
                      onClick={closeCollapse}
                    >
                      <i className="bi bi-gem" style={{ color: "var(--cinaf-gold)" }} />
                      Mon abonnement
                    </Link>
                  </li>

                  {/* Accès réservé aux producteurs */}
                  {isStudio(user) && (
                    <li>
                      <Link
                        href="/studio"
                        className="dropdown-item d-flex align-items-center gap-2"
                        onClick={closeCollapse}
                      >
                        <i className="bi bi-camera-reels-fill" style={{ color: "var(--cinaf-gold)" }} />
                        Studio
                      </Link>
                    </li>
                  )}

                  {/* Accès réservé aux administrateurs */}
                  {isAdmin(user) && (
                    <li>
                      <Link
                        href="/admin"
                        className="dropdown-item d-flex align-items-center gap-2"
                        onClick={closeCollapse}
                      >
                        <i className="bi bi-shield-lock-fill" style={{ color: "var(--cinaf-gold)" }} />
                        Administration
                      </Link>
                    </li>
                  )}

                  <li>
                    <hr className="dropdown-divider" />
                  </li>

                  <li>
                    <button
                      className="dropdown-item d-flex align-items-center gap-2"
                      onClick={handleLogout}
                    >
                      <i className="bi bi-box-arrow-right" style={{ color: "var(--cinaf-danger)" }} />
                      <span style={{ color: "var(--cinaf-danger)" }}>Déconnexion</span>
                    </button>
                  </li>
                </ul>
              </div>
            ) : (
              /* Boutons d'accès pour visiteur anonyme */
              <>
                <Link
                  href="/login"
                  className="btn btn-cinaf-outline btn-sm px-3"
                  onClick={closeCollapse}
                >
                  Connexion
                </Link>
                <Link
                  href="/register"
                  className="btn btn-cinaf btn-sm px-3"
                  onClick={closeCollapse}
                >
                  S&apos;inscrire
                </Link>
              </>
            )}
          </div>
        </div>
      </div>
    </nav>
  );
}
