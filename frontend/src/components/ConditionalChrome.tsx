"use client";

/**
 * ============================================================
 * CINAF v2 — Enveloppe conditionnelle de navigation (ConditionalChrome)
 * ============================================================
 * Ce composant décide d'afficher ou de masquer la barre de navigation haute (`Navbar`)
 * et le pied de page général (`Footer`) en fonction de la route active.
 * 
 * Règle de distinction de périmètre :
 * - Espace Studio privé (`/studio` et `/studio/...`) : L'interface générale est masquée
 *   car le module Studio implémente son propre gabarit dédié (mise en page dashboard avec barre latérale fixe).
 * - Vue Chaînes publiques (`/studios` et `/studios/...`) : Conserve impérativement la Navbar et le Footer
 *   car il s'agit d'un espace public de consultation.
 *   Note : On effectue une vérification stricte (`pathname === "/studio" || pathname.startsWith("/studio/")`)
 *   pour éviter le faux-positif lexical d'un simple `startsWith("/studio")` qui masquerait à tort `/studios`.
 */

import { usePathname } from "next/navigation";
import Navbar from "@/components/Navbar";
import Footer from "@/components/Footer";

/**
 * Composant d'habillage conditionnel de page.
 * 
 * @param props - Contient les éléments enfants à rendre
 * @returns La page enveloppée de la Navbar/Footer ou rendue telle quelle dans le Studio
 */
export default function ConditionalChrome({
  children,
}: {
  children: React.ReactNode;
}) {
  const pathname = usePathname();
  
  // Vérification rigoureuse du préfixe Studio dashboard vs Studios public
  const isStudioArea = pathname === "/studio" || (pathname?.startsWith("/studio/") ?? false);

  if (isStudioArea) {
    // Espace Studio : le gabarit studio/layout.tsx prend en charge l'ergonomie
    return <>{children}</>;
  }

  return (
    <>
      <Navbar />
      <main style={{ minHeight: "calc(100vh - 64px - 200px)" }}>{children}</main>
      <Footer />
    </>
  );
}
