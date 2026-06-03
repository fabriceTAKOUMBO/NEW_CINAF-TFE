"use client";

// ============================================================
// CINAF v2 — Barre de recherche avec debounce
// ============================================================

import { useState, useEffect, useRef, useCallback } from "react";
import { useRouter } from "next/navigation";

interface SearchBarProps {
  /** Mode "redirect" : navigue vers /recherche?q=  |  "inline" : appelle onChange */
  mode?: "redirect" | "inline";
  onChange?: (query: string) => void;
  placeholder?: string;
  defaultValue?: string;
  compact?: boolean;
  autoFocus?: boolean;
}

export default function SearchBar({
  mode = "redirect",
  onChange,
  placeholder = "Rechercher un film, une série, un studio...",
  defaultValue = "",
  compact = false,
  autoFocus = false,
}: SearchBarProps) {
  // État local pour contrôler la valeur du champ de saisie
  const [value, setValue] = useState(defaultValue);
  const router = useRouter();
  
  // Référence pour stocker l'ID du timer du debounce sans provoquer de re-render
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  /**
   * Implémentation du debounce pour limiter le nombre d'appels à l'API ou de redirections.
   * On attend que l'utilisateur arrête de taper pendant 300ms avant d'exécuter l'action.
   */
  const debounce = useCallback(
    (val: string) => {
      // On annule le timer précédent s'il existe
      if (timerRef.current) clearTimeout(timerRef.current);
      
      // On lance un nouveau timer
      timerRef.current = setTimeout(() => {
        if (mode === "redirect") {
          // Mode navigation vers la page de recherche dédiée
          router.push(`/recherche?q=${encodeURIComponent(val)}`);
        } else if (onChange) {
          // Mode "live" : on informe le composant parent du changement
          onChange(val);
        }
      }, 300);
    },
    [mode, onChange, router]
  );

  /**
   * Gère la modification du texte dans l'input.
   */
  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value;
    setValue(val);
    // On ne lance la recherche que si au moins 2 caractères sont saisis
    if (val.trim().length >= 2) {
      debounce(val.trim());
    }
  };

  /**
   * Gère l'appui sur la touche Entrée pour une recherche immédiate.
   */
  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Enter" && value.trim()) {
      // On annule le debounce en cours car l'utilisateur a validé manuellement
      if (timerRef.current) clearTimeout(timerRef.current);
      
      if (mode === "redirect") {
        router.push(`/recherche?q=${encodeURIComponent(value.trim())}`);
      } else if (onChange) {
        onChange(value.trim());
      }
    }
  };

  // Nettoyage du timer lors du démontage du composant pour éviter les fuites de mémoire
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
