"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { studio, type Studio, type UpdateStudioPayload } from "@/lib/api";

export default function ProfilStudioPage() {
  const [current, setCurrent] = useState<Studio | null>(null);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  // Chargement initial : on récupère le studio courant pour pré-remplir le formulaire.
  useEffect(() => {
    let cancelled = false;
    studio.getMe()
      .then((res) => {
        if (cancelled) return;
        setCurrent(res.studio);
        setName(res.studio.name);
        setDescription(res.studio.description ?? "");
      })
      .catch(() => {
        if (!cancelled) setError("Impossible de charger votre studio.");
      });
    return () => { cancelled = true; };
  }, []);

  // Soumission du formulaire — n'envoie que les champs réellement modifiés.
  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!current) return;

    const trimmedName = name.trim();
    const trimmedDescription = description.trim();
    if (!trimmedName) {
      setError("Le nom du studio est obligatoire.");
      return;
    }
    if (!trimmedDescription) {
      setError("La description du studio est obligatoire.");
      return;
    }

    const payload: UpdateStudioPayload = {};
    if (trimmedName !== current.name) payload.name = trimmedName;
    if (trimmedDescription !== (current.description ?? "")) {
      payload.description = trimmedDescription;
    }

    if (Object.keys(payload).length === 0) {
      setError("Aucune modification à enregistrer.");
      return;
    }

    setSubmitting(true);
    setError(null);
    setSuccess(false);

    try {
      const updated = await studio.updateMe(payload);
      setCurrent(updated);
      setSuccess(true);
    } catch (err: unknown) {
      const e = err as { status?: number; body?: { error?: string } } | undefined;
      if (e?.status === 409) {
        setError("Ce nom de studio est déjà utilisé. Choisissez-en un autre.");
      } else if (e?.status === 400) {
        setError(e?.body?.error ?? "Données invalides.");
      } else {
        setError("Une erreur est survenue. Réessayez dans quelques instants.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  if (!current) {
    return <div className="container py-5">Chargement de votre studio…</div>;
  }

  return (
    <div className="container py-5">
      <nav aria-label="Fil d'Ariane" className="mb-3">
        <Link href="/studio">← Retour au tableau de bord</Link>
      </nav>

      <h1 className="mb-4">Profil du studio</h1>
      <p className="text-muted mb-4">
        Modifiez le nom commercial et la description de votre studio.
        L&apos;identifiant technique (slug) reste inchangé pour préserver
        vos liens existants.
      </p>

      {success && (
        <div className="alert alert-success" role="status">
          Profil du studio mis à jour avec succès.
        </div>
      )}
      {error && (
        <div className="alert alert-danger" role="alert">
          {error}
        </div>
      )}

      <form onSubmit={onSubmit} noValidate>
        <div className="mb-3">
          <label htmlFor="studio-name" className="form-label fw-bold">
            Nom du studio
          </label>
          <input
            id="studio-name"
            type="text"
            className="form-control"
            value={name}
            onChange={(e) => setName(e.target.value)}
            maxLength={255}
            required
          />
          <div className="form-text">
            255 caractères maximum. Doit être unique sur la plateforme.
          </div>
        </div>

        <div className="mb-4">
          <label htmlFor="studio-description" className="form-label fw-bold">
            Description
          </label>
          <textarea
            id="studio-description"
            className="form-control"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={6}
            maxLength={2000}
            required
          />
          <div className="form-text">
            {description.length} / 2 000 caractères.
          </div>
        </div>

        <div className="d-flex gap-2">
          <button
            type="submit"
            className="btn btn-cinaf"
            disabled={submitting}
          >
            {submitting ? "Enregistrement…" : "Enregistrer les modifications"}
          </button>
          <Link href="/studio" className="btn btn-outline-secondary">
            Annuler
          </Link>
        </div>
      </form>
    </div>
  );
}