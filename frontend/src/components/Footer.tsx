/**
 * ============================================================
 * CINAF v2 — Pied de page global (Footer)
 * ============================================================
 * Composant de pied de page universel (Server Component) présent sur toutes les pages publiques.
 * 
 * Sections organisées :
 * - Marque CINAF, slogan et liens vers les réseaux sociaux officiels (Facebook, Instagram, X, YouTube).
 * - Navigation plateforme : Catalogue, Studio, Abonnements, et candidature créateur ("Vous êtes producteur ?").
 * - Légal : Liens vers les CGU, Politique de confidentialité et Gestion des cookies.
 * - Société : À propos, Contact et Espace presse.
 * - Bas de page : Copyright avec année dynamique et mention de fabrication soignée.
 */

import Link from "next/link";

/**
 * Pied de page général de la plateforme CINAF.
 * 
 * @returns Le footer complet responsive
 */
export default function Footer() {
  // Calcul automatique de l'année pour le copyright légal
  const currentYear = new Date().getFullYear();

  return (
    <footer className="cinaf-footer">
      <div className="container">
        <div className="row gy-4 mb-4">
          {/* Section Marque et Réseaux Sociaux */}
          <div className="col-12 col-md-4">
            <div className="footer-logo">CINAF</div>
            <p className="footer-tagline mt-1">
              Le cinéma africain à portée de clic
            </p>
            <div className="d-flex gap-3 mt-3">
              {/* Réseaux sociaux externes avec sécurité noopener noreferrer */}
              <a
                href="https://facebook.com"
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Facebook"
              >
                <i className="bi bi-facebook" style={{ fontSize: "1.1rem" }} />
              </a>
              <a
                href="https://instagram.com"
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Instagram"
              >
                <i className="bi bi-instagram" style={{ fontSize: "1.1rem" }} />
              </a>
              <a
                href="https://twitter.com"
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Twitter / X"
              >
                <i className="bi bi-twitter-x" style={{ fontSize: "1.1rem" }} />
              </a>
              <a
                href="https://youtube.com"
                target="_blank"
                rel="noopener noreferrer"
                aria-label="YouTube"
              >
                <i className="bi bi-youtube" style={{ fontSize: "1.1rem" }} />
              </a>
            </div>
          </div>

          {/* Navigation vers les sections de la plateforme */}
          <div className="col-6 col-md-2 offset-md-2">
            <h6
              className="mb-3"
              style={{
                color: "var(--cinaf-text)",
                fontSize: "0.8rem",
                fontWeight: 700,
                textTransform: "uppercase",
                letterSpacing: "1px",
              }}
            >
              Plateforme
            </h6>
            <ul className="list-unstyled d-flex flex-column gap-2">
              <li>
                <Link href="/catalogue">Catalogue</Link>
              </li>
              <li>
                <Link href="/studio">Studio</Link>
              </li>
              <li>
                <Link href="/abonnement">Abonnements</Link>
              </li>
              <li>
                <Link href="/devenir-producteur">Vous êtes producteur ?</Link>
              </li>
            </ul>
          </div>

          {/* Informations Légales */}
          <div className="col-6 col-md-2">
            <h6
              className="mb-3"
              style={{
                color: "var(--cinaf-text)",
                fontSize: "0.8rem",
                fontWeight: 700,
                textTransform: "uppercase",
                letterSpacing: "1px",
              }}
            >
              Légal
            </h6>
            <ul className="list-unstyled d-flex flex-column gap-2">
              <li>
                <Link href="/cgu">CGU</Link>
              </li>
              <li>
                <Link href="/confidentialite">Confidentialité</Link>
              </li>
              <li>
                <Link href="/cookies">Cookies</Link>
              </li>
            </ul>
          </div>

          {/* Informations sur l'entreprise */}
          <div className="col-6 col-md-2">
            <h6
              className="mb-3"
              style={{
                color: "var(--cinaf-text)",
                fontSize: "0.8rem",
                fontWeight: 700,
                textTransform: "uppercase",
                letterSpacing: "1px",
              }}
            >
              Société
            </h6>
            <ul className="list-unstyled d-flex flex-column gap-2">
              <li>
                <Link href="/a-propos">À propos</Link>
              </li>
              <li>
                <Link href="/contact">Contact</Link>
              </li>
              <li>
                <Link href="/presse">Presse</Link>
              </li>
            </ul>
          </div>
        </div>

        {/* Ligne inférieure : Copyright et signature de marque */}
        <div
          style={{
            borderTop: "1px solid var(--cinaf-border)",
            paddingTop: "1.25rem",
            display: "flex",
            flexDirection: "row",
            flexWrap: "wrap",
            justifyContent: "space-between",
            alignItems: "center",
            gap: "0.5rem",
          }}
        >
          <p style={{ color: "var(--cinaf-text-muted)", fontSize: "0.8rem", margin: 0 }}>
            &copy; {currentYear} CINAF. Tous droits réservés.
          </p>
          <p style={{ color: "var(--cinaf-text-muted)", fontSize: "0.8rem", margin: 0 }}>
            Fait avec{" "}
            <i className="bi bi-heart-fill" style={{ color: "var(--cinaf-gold)" }} />{" "}
            pour le cinéma africain
          </p>
        </div>
      </div>
    </footer>
  );
}
