"use client";

// ============================================================
// CINAF v2 — Admin / Liste des utilisateurs
// Filtres (recherche + rôle) + table avec actions inline
// (suspend/activate, voir détail, supprimer).
// ============================================================

import { Suspense, useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import {
  ADMIN_ROLES,
  adminUsers,
  type AdminUser,
  type AdminRole,
} from "@/lib/api";
import { useAuth } from "@/lib/auth";
import Pagination from "@/components/Pagination";

const PAGE_LIMIT = 20;

/**
 * Page de gestion des comptes utilisateurs, enveloppée dans Suspense pour lire les SearchParams.
 */
export default function AdminUsersPage() {
  return (
    <Suspense
      fallback={
        <div className="d-flex justify-content-center py-5">
          <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} />
        </div>
      }
    >
      <UsersContent />
    </Suspense>
  );
}

/**
 * Contenu interactif d'administration des utilisateurs CINAF.
 * 
 * Fonctionnalités :
 * - Recherche par nom, prénom ou adresse email.
 * - Filtrage par rôle (Utilisateur, Abonné, Créateur, Modérateur, Administrateur).
 * - Actions directes : suspension / réactivation de compte, suppression, accès à la fiche détaillée.
 * - Protection empêchant un administrateur de se suspendre ou de se supprimer lui-même.
 * 
 * @returns La table d'administration des utilisateurs avec filtres et pagination.
 */
function UsersContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { user: currentUser } = useAuth();

  const search = searchParams.get("search") || "";
  const role = (searchParams.get("role") as AdminRole | null) || null;
  const page = Math.max(1, Number(searchParams.get("page")) || 1);

  const [items, setItems] = useState<AdminUser[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [pendingId, setPendingId] = useState<string | null>(null);
  const [searchInput, setSearchInput] = useState(search);

  const reload = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await adminUsers.list({
        page,
        limit: PAGE_LIMIT,
        search: search || undefined,
        role: role || undefined,
      });
      setItems(res.data);
      setTotal(res.total);
    } catch (err: unknown) {
      const msg = (err as { message?: string })?.message ?? "Erreur inconnue";
      setError(msg);
      setItems([]);
      setTotal(0);
    } finally {
      setLoading(false);
    }
  }, [page, search, role]);

  useEffect(() => {
    setSearchInput(search);
  }, [search]);

  useEffect(() => {
    reload();
  }, [reload]);

  const updateParams = useCallback(
    (next: Record<string, string | undefined>) => {
      const params = new URLSearchParams(searchParams.toString());
      Object.entries(next).forEach(([k, v]) => {
        if (v === undefined || v === "") params.delete(k);
        else params.set(k, v);
      });
      router.push(
        `/admin/utilisateurs${params.toString() ? `?${params.toString()}` : ""}`,
      );
    },
    [router, searchParams],
  );

  async function toggleSuspend(target: AdminUser) {
    if (target.id === currentUser?.id) {
      alert("Vous ne pouvez pas modifier l'état de votre propre compte.");
      return;
    }
    setPendingId(target.id);
    try {
      if (target.isSuspended) {
        await adminUsers.activate(target.id);
      } else {
        await adminUsers.suspend(target.id);
      }
      await reload();
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Erreur lors de la mise à jour.");
    } finally {
      setPendingId(null);
    }
  }

  async function deleteUser(target: AdminUser) {
    if (target.id === currentUser?.id) {
      alert("Vous ne pouvez pas supprimer votre propre compte.");
      return;
    }
    if (
      !confirm(
        `Supprimer définitivement « ${target.email} » ? Cette action est irréversible.`,
      )
    ) {
      return;
    }
    setPendingId(target.id);
    try {
      await adminUsers.remove(target.id);
      await reload();
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Erreur lors de la suppression.");
    } finally {
      setPendingId(null);
    }
  }

  async function handleExport() {
    try {
      const blob = await adminUsers.exportCsv();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = "users-export.csv";
      a.click();
      URL.revokeObjectURL(url);
    } catch (err: unknown) {
      alert((err as { message?: string })?.message ?? "Erreur d'export.");
    }
  }

  function submitSearch(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    updateParams({ search: searchInput || undefined, page: undefined });
  }

  return (
    <div className="container py-4">
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <div>
          <Link
            href="/admin"
            className="d-inline-block mb-2"
            style={{ color: "var(--cinaf-text-muted)", textDecoration: "none" }}
          >
            <i className="bi bi-arrow-left me-1" />
            Tableau de bord
          </Link>
          <h1 style={{ color: "var(--cinaf-text)", fontWeight: 700 }}>
            <i
              className="bi bi-people-fill me-2"
              style={{ color: "var(--cinaf-gold)" }}
            />
            Utilisateurs
          </h1>
          <p className="mb-0" style={{ color: "var(--cinaf-text-muted)" }}>
            {total} compte{total > 1 ? "s" : ""} inscrit{total > 1 ? "s" : ""}.
          </p>
        </div>
        <button
          type="button"
          onClick={handleExport}
          className="btn btn-cinaf-outline btn-sm"
        >
          <i className="bi bi-download me-1" />
          Export CSV
        </button>
      </div>

      <div className="d-flex flex-wrap gap-2 align-items-end mb-3">
        <form onSubmit={submitSearch} className="d-flex" style={{ flex: "1 1 280px", maxWidth: 420 }}>
          <input
            type="text"
            className="form-control"
            placeholder="Rechercher par email, prénom ou nom…"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            style={{
              background: "var(--cinaf-surface)",
              color: "var(--cinaf-text)",
              border: "1px solid var(--cinaf-border)",
            }}
          />
          <button
            type="submit"
            className="btn ms-2"
            style={{
              background: "var(--cinaf-gold)",
              color: "#000",
              fontWeight: 600,
            }}
          >
            <i className="bi bi-search" />
          </button>
        </form>

        <select
          className="form-select"
          style={{
            background: "var(--cinaf-surface)",
            color: "var(--cinaf-text)",
            border: "1px solid var(--cinaf-border)",
            maxWidth: 220,
          }}
          value={role ?? ""}
          onChange={(e) =>
            updateParams({ role: e.target.value || undefined, page: undefined })
          }
          aria-label="Filtre par rôle"
        >
          <option value="">Tous les rôles</option>
          {ADMIN_ROLES.map((r) => (
            <option key={r} value={r}>
              {r}
            </option>
          ))}
        </select>

        {(search || role) && (
          <button
            type="button"
            onClick={() =>
              updateParams({ search: undefined, role: undefined, page: undefined })
            }
            className="btn btn-link btn-sm"
            style={{ color: "var(--cinaf-text-muted)" }}
          >
            Réinitialiser
          </button>
        )}
      </div>

      {error && !loading && (
        <div
          className="alert"
          style={{ background: "#2a1414", color: "#ff8a8a", border: "1px solid #5a2020" }}
        >
          <i className="bi bi-exclamation-triangle me-2" />
          {error}
        </div>
      )}

      {loading ? (
        <div className="text-center py-5">
          <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} role="status" />
        </div>
      ) : items.length === 0 ? (
        <div className="text-center py-5" style={{ color: "var(--cinaf-text-muted)" }}>
          <i className="bi bi-search d-block mb-2" style={{ fontSize: "3rem" }} />
          Aucun utilisateur ne correspond aux critères.
        </div>
      ) : (
        <div className="table-responsive">
          <table
            className="table table-dark table-hover align-middle"
            style={{ background: "var(--cinaf-surface)" }}
          >
            <thead>
              <tr>
                <th>Utilisateur</th>
                <th>Email</th>
                <th>Rôles</th>
                <th>Plan</th>
                <th>Statut</th>
                <th>Inscrit le</th>
                <th className="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              {items.map((u) => {
                const isSelf = u.id === currentUser?.id;
                const initials =
                  ((u.firstName?.[0] ?? "") + (u.lastName?.[0] ?? "")).toUpperCase() ||
                  (u.email[0]?.toUpperCase() ?? "?");
                return (
                  <tr key={u.id}>
                    <td>
                      <div className="d-flex align-items-center gap-2">
                        <span
                          style={{
                            width: 32,
                            height: 32,
                            borderRadius: "50%",
                            background:
                              "linear-gradient(135deg, var(--cinaf-gold), #a07830)",
                            color: "#000",
                            fontWeight: 700,
                            fontSize: "0.75rem",
                            display: "flex",
                            alignItems: "center",
                            justifyContent: "center",
                          }}
                        >
                          {initials}
                        </span>
                        <span>
                          {u.firstName} {u.lastName}
                          {isSelf && (
                            <span className="badge bg-secondary ms-2" style={{ fontSize: "0.65rem" }}>
                              Vous
                            </span>
                          )}
                        </span>
                      </div>
                    </td>
                    <td>
                      <span style={{ color: "var(--cinaf-text-muted)" }}>{u.email}</span>
                    </td>
                    <td>
                      {u.roles.map((r) => (
                        <span
                          key={r}
                          className="badge me-1"
                          style={{
                            background:
                              r === "ROLE_ADMIN"
                                ? "var(--cinaf-gold)"
                                : "var(--cinaf-surface-2)",
                            color: r === "ROLE_ADMIN" ? "#000" : "var(--cinaf-text)",
                            fontSize: "0.65rem",
                          }}
                        >
                          {r.replace("ROLE_", "")}
                        </span>
                      ))}
                    </td>
                    <td>
                      {u.currentSubscription ? (
                        <span
                          className="badge"
                          style={{
                            background: "rgba(200,168,75,0.15)",
                            color: "var(--cinaf-gold)",
                            border: "1px solid rgba(200,168,75,0.4)",
                            fontSize: "0.7rem",
                            fontWeight: 600,
                          }}
                          title={
                            u.currentSubscription.endsAt
                              ? `Jusqu'au ${new Date(u.currentSubscription.endsAt).toLocaleDateString("fr-FR")}`
                              : undefined
                          }
                        >
                          <i className="bi bi-gem me-1" />
                          {u.currentSubscription.planName}
                        </span>
                      ) : (
                        <span style={{ color: "var(--cinaf-text-muted)" }}>—</span>
                      )}
                    </td>
                    <td>
                      {u.isSuspended ? (
                        <span className="badge" style={{ background: "#5a2020", color: "#ff8a8a" }}>
                          Suspendu
                        </span>
                      ) : u.isVerified ? (
                        <span className="badge" style={{ background: "#1f3a1f", color: "#8fd68f" }}>
                          Actif
                        </span>
                      ) : (
                        <span className="badge bg-secondary">Non vérifié</span>
                      )}
                    </td>
                    <td>
                      <span style={{ color: "var(--cinaf-text-muted)", fontSize: "0.85rem" }}>
                        {u.createdAt
                          ? new Date(u.createdAt).toLocaleDateString("fr-FR")
                          : "—"}
                      </span>
                    </td>
                    <td className="text-end">
                      <Link
                        href={`/admin/utilisateurs/${u.id}`}
                        className="btn btn-sm btn-cinaf-outline me-1"
                        title="Voir le détail"
                      >
                        <i className="bi bi-pencil" />
                      </Link>
                      <button
                        type="button"
                        className="btn btn-sm me-1"
                        onClick={() => toggleSuspend(u)}
                        disabled={isSelf || pendingId === u.id}
                        style={{
                          background: u.isSuspended ? "#1f3a1f" : "#5a3520",
                          color: u.isSuspended ? "#8fd68f" : "#f0c080",
                          border: "none",
                        }}
                        title={u.isSuspended ? "Réactiver" : "Suspendre"}
                      >
                        <i className={`bi ${u.isSuspended ? "bi-check-circle" : "bi-pause-circle"}`} />
                      </button>
                      <button
                        type="button"
                        className="btn btn-sm"
                        onClick={() => deleteUser(u)}
                        disabled={isSelf || pendingId === u.id}
                        style={{
                          background: "#3a1414",
                          color: "#ff8a8a",
                          border: "none",
                        }}
                        title="Supprimer"
                      >
                        <i className="bi bi-trash" />
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {!loading && total > PAGE_LIMIT && (
        <div className="mt-3 d-flex justify-content-center">
          <Pagination
            currentPage={page}
            totalItems={total}
            itemsPerPage={PAGE_LIMIT}
            onPageChange={(p) => updateParams({ page: p > 1 ? String(p) : undefined })}
          />
        </div>
      )}
    </div>
  );
}
