"use client";

/**
 * ============================================================
 * CINAF v2 — Barre de recherche avec temporisation (SearchBar)
 * ============================================================
 * Champ de recherche textuelle intégrant un mécanisme de debounce (temporisation)
 * pour éviter de submerger le serveur d'appels API à chaque frappe de touche.
 * 
 * Deux modes d'opération :
 * - `mode = "redirect"` (mode par défaut) : À l'issue du délai (300ms) ou à l'appui
 *   sur la touche Entrée, redirige l'utilisateur vers la page `/recherche?q=...`.
 * - `mode = "inline"` : Invoque le callback `onChange(query)` pour filtrer en direct
 *   la liste sur la page courante (ex: dans les catalogues de films, séries, studios).
 * - Seuil minimal : La recherche ne se déclenche qu'à partir de 2 caractères saisis.
 */

import { useState, useEffect, useRef, useCallback } from "react";
import { useRouter } from "next/navigation";

/**
 * Propriétés attendues par le composant `SearchBar`.
 */
interface SearchBarProps {
  /** Mode opératoire : "redirect" vers /recherche ou "inline" via onChange */
  mode?: "redirect" | "inline";
  /** Callback invoqué en mode "inline" avec la requête saisie */
  onChange?: (query: string) => void;
  /** Texte indicatif (placeholder) dans le champ de saisie */
  placeholder?: string;
  /** Valeur initiale pré-remplie */
  defaultValue?: string;
  /** Format compact pour insertion dans les barres de navigation */
  compact?: boolean;
  /** Focus automatique sur le champ au montage */
  autoFocus?: boolean;
}

/**
 * Champ de recherche réactif avec debounce de 300 millisecondes.
 * 
 * @param props - Propriétés du composant
 * @returns Le champ de recherche stylisé avec icône loupe
 */
export default function SearchBar({
  mode = "redirect",
  onChange,
  placeholder = "Rechercher un film, une série, un studio...",
  defaultValue = "",
  compact = false,
  autoFocus = false,
}: SearchBarProps) {
  // Contrôle de la valeur saisie
  const [value, setValue] = useState(defaultValue);
  const router = useRouter();
  
  // Référence conservant le timer de debounce sans déclencher de re-rendu
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  /**
   * Fonction de debounce : temporise l'exécution pendant 300 millisecondes.
   */
  const debounce = useCallback(
    (val: string) => {
      if (timerRef.current) clearTimeout(timerRef.current);
      
      timerRef.current = setTimeout(() => {
        if (mode === "redirect") {
          router.push(`/recherche?q=${encodeURIComponent(val)}`);
        } else if (onChange) {
          onChange(val);
        }
      }, 300);
    },
    [mode, onChange, router]
  );

  /**
   * Gestionnaire de modification du texte.
   */
  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value;
    setValue(val);
    // Filtrage conditionnel : au moins 2 caractères requis
    if (val.trim().length >= 2) {
      debounce(val.trim());
    }
  };

  /**
   * Validation manuelle immédiate lors de l'appui sur la touche Entrée.
   */
  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Enter" && value.trim()) {
      if (timerRef.current) clearTimeout(timerRef.current);
      
      if (mode === "redirect") {
        router.push(`/recherche?q=${encodeURIComponent(value.trim())}`);
      } else if (onChange) {
        onChange(value.trim());
      }
    }
  };

  // Nettoyage du timer au démontage pour prévenir les fuites mémoire
  useEffect(() => {
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, []);

  return (
    <div
      className={`input-group ${compact ? "input-group-sm" : ""}`}
      style={{ maxWidth: compact ? 260 : undefined }}
    >
      <span
        className="input-group-text"
        style={{
          backgroundColor: "var(--cinaf-surface-2)",
          borderColor: "var(--cinaf-border)",
          color: "var(--cinaf-text-muted)",
        }}
      >
        <i className="bi bi-search" />
      </span>
      <input
        type="text"
        className="form-control"
        placeholder={placeholder}
        value={value}
        onChange={handleChange}
        onKeyDown={handleKeyDown}
        autoFocus={autoFocus}
      />
    </div>
  );
}
