// ============================================================
// CINAF v2 — Layout global (Root Layout)
// ============================================================

import type { Metadata } from "next";
import localFont from "next/font/local";
import "bootstrap/dist/css/bootstrap.min.css";
import "bootstrap-icons/font/bootstrap-icons.css";
import "./globals.css";

import { AuthProvider } from "@/lib/auth";
import { UploadProvider } from "@/lib/upload-context";
import ConditionalChrome from "@/components/ConditionalChrome";
import BootstrapClient from "@/components/BootstrapClient";
import UploadTray from "@/components/studio/UploadTray";

const geistSans = localFont({
  src: "./fonts/GeistVF.woff",
  variable: "--font-geist-sans",
  weight: "100 900",
});

const geistMono = localFont({
  src: "./fonts/GeistMonoVF.woff",
  variable: "--font-geist-mono",
  weight: "100 900",
});

export const metadata: Metadata = {
  title: {
    default: "CINAF v2 — Le cinéma africain à portée de clic",
    template: "%s | CINAF",
  },
  description:
    "Découvrez les meilleurs films et séries africains en streaming HD. CINAF, la plateforme dédiée aux productions du continent africain.",
  keywords: ["streaming", "films africains", "cinéma africain", "séries africaines"],
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="fr" data-bs-theme="dark">
      <body className={`${geistSans.variable} ${geistMono.variable}`}>
        {/* 
          AuthProvider : Composant de contexte qui enveloppe l'application.
          Il permet à n'importe quel composant enfant d'accéder à l'état de l'utilisateur
          via le hook useAuth().
        */}
        <AuthProvider>
          <UploadProvider>
            {/*
              ConditionalChrome : rend la navbar haute et le footer global sur
              toutes les pages publiques/abonnées, mais les masque sur /studio/**
              qui dispose de son propre back-office (sidebar fixe).
            */}
            <ConditionalChrome>{children}</ConditionalChrome>
            {/* Tray d'uploads en arrière-plan, visible sur toutes les pages */}
            <UploadTray />
          </UploadProvider>
        </AuthProvider>

        {/* 
          BootstrapClient : Petit composant "client-side" qui importe les scripts JS de Bootstrap.
          C'est nécessaire car RootLayout est un Server Component par défaut.
        */}
        <BootstrapClient />
      </body>
    </html>
  );
}
