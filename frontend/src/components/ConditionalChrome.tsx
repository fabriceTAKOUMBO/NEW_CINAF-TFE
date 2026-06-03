"use client";

// ============================================================
// CINAF v2 — ConditionalChrome
// Masque la navbar haute et le footer global sur l'espace studio
// (/studio/**), qui dispose de son propre back-office.
// ============================================================

import { usePathname } from "next/navigation";
import Navbar from "@/components/Navbar";
import Footer from "@/components/Footer";

export default function ConditionalChrome({
  children,
}: {
  children: React.ReactNode;
}) {
  const pathname = usePathname();
  // Backoffice studio (`/studio` exact ou `/studio/...`) : on masque la chrome
  // globale car ce périmètre a son propre layout avec sidebar fixe.
  // ATTENTION au piège du préfixe : `/studios` (vue publique « chaîne YouTube »)
  // commence aussi par `/studio` lexicalement — on doit donc matcher
  // explicitement `/studio` exact ou `/studio/...` (slash suivant), pas un
  // simple `startsWith("/studio")` qui engloberait `/studios` à tort.
  const isStudioArea = pathname === "/studio" || (pathname?.startsWith("/studio/") ?? false);

  if (isStudioArea) {
    // Espace studio : pas de navbar/footer globaux, le studio layout
    // gère sa propre chrome (sidebar fixe).
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
