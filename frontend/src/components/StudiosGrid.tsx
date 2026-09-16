/**
 * ============================================================
 * CINAF v2 — Grille responsive de studios (StudiosGrid)
 * ============================================================
 * Composant de présentation en grille adaptative pour les studios publics :
 * - 2 colonnes sur smartphone (écrans compacts).
 * - 3 colonnes sur tablette (écrans moyens).
 * - 4 colonnes sur ordinateur (écrans larges).
 * 
 * Gestion de l'état :
 * - Affiche un indicateur de chargement doré si `loading = true`.
 * - Retourne `null` si le tableau est vide (laissant la responsabilité du message vide à la page parente).
 */

import type { StudioPublic } from "@/lib/api";
import StudioCard from "./StudioCard";

/**
 * Propriétés attendues par le composant `StudiosGrid`.
 */
interface StudiosGridProps {
  /** Liste des studios à afficher */
  studios: StudioPublic[];
  /** Affiche un spinner doré centré à la place de la grille si true */
  loading?: boolean;
}

/**
 * Grille responsive de cartes de studios.
 * 
 * @param props - Propriétés du composant
 * @returns La grille Bootstrap de studios
 */
export default function StudiosGrid({ studios, loading = false }: StudiosGridProps) {
  if (loading) {
    return (
      <div className="text-center py-5">
        <div
          className="spinner-border"
          style={{ color: "var(--cinaf-gold)" }}
          role="status"
        >
          <span className="visually-hidden">Chargement des studios…</span>
        </div>
      </div>
    );
  }

  if (studios.length === 0) return null;

  return (
    <div className="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3">
      {studios.map((s) => (
        <div key={s.id} className="col">
          <StudioCard studio={s} />
        </div>
      ))}
    </div>
  );
}
