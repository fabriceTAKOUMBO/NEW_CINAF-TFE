/**
 * ============================================================
 * CINAF v2 — Lecteur Bunny.net Stream (iframe responsive)
 * ============================================================
 * Ce composant intègre le lecteur de streaming vidéo propriétaire Bunny.net
 * au travers d'une iframe adaptative (ratio 16:9).
 * 
 * Fonctionnalités intégrées :
 * - Maintien automatique du ratio d'aspect cinématographique 16:9 via padding CSS.
 * - Préchargement des métadonnées vidéo (`preload=true`).
 * - Mémorisation de la position de lecture (`rememberPosition=true`).
 * - Contrôle de la vitesse de lecture (`showSpeed=true`).
 * - Support du plein écran et de l'accéléromètre pour mobiles.
 */

interface BunnyPlayerProps {
  /** Identifiant unique de la vidéo sur la bibliothèque Bunny Stream */
  videoId: string;
  /** Identifiant optionnel de la bibliothèque vidéo (fallback sur process.env.NEXT_PUBLIC_BUNNY_LIBRARY_ID) */
  libraryId?: string;
  /** Démarrage automatique de la lecture dès le chargement */
  autoplay?: boolean;
}

/**
 * Composant de lecture vidéo Bunny Stream responsive.
 * 
 * @param props - Propriétés du lecteur (`videoId`, `libraryId`, `autoplay`)
 * @returns Le conteneur vidéo avec l'iframe Bunny Stream intégrée
 */
export default function BunnyPlayer({
  videoId,
  libraryId,
  autoplay = false,
}: BunnyPlayerProps) {
  // Utilisation de l'ID de bibliothèque spécifié ou repli sur la variable d'environnement
  const libId = libraryId || process.env.NEXT_PUBLIC_BUNNY_LIBRARY_ID || "";
  
  // Construction de l'URL d'intégration Bunny.net avec paramètres d'expérience utilisateur
  const src = `https://player.mediadelivery.net/embed/${libId}/${videoId}?autoplay=${autoplay}&preload=true&rememberPosition=true&showSpeed=true`;

  return (
    <div
      style={{
        position: "relative",
        width: "100%",
        paddingTop: "56.25%", // Ratio 16:9 universel (9 / 16 * 100)
        backgroundColor: "#000",
        borderRadius: "8px",
        overflow: "hidden",
      }}
    >
      <iframe
        src={src}
        loading="lazy"
        allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;"
        allowFullScreen
        style={{
          position: "absolute",
          top: 0,
          left: 0,
          width: "100%",
          height: "100%",
          border: "none",
        }}
        title="Lecteur video CINAF"
      />
    </div>
  );
}
