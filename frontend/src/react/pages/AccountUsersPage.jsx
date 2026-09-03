import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";
import NevInput from "../components/ui/NevInput";
import NevSelect from "../components/ui/NevSelect";
import NevButton from "../components/ui/NevButton";
import NevBadge from "../components/ui/NevBadge";
import NevDataState from "../components/ui/NevDataState";
import NevTable from "../components/ui/NevTable";
import NevConfirmDialog from "../components/ui/NevConfirmDialog";

const ROLE_LABEL_KEYS = {
  super_admin: ["usersPage.roleSuperAdmin", "Super administrateur"],
  admin: ["usersPage.roleAdmin", "Administrateur"],
  internal_analyst: ["usersPage.roleInternalAnalyst", "Analyste interne"],
  external_partner: ["usersPage.roleExternalPartner", "Partenaire externe"],
};
const ROLE_BADGE_TONES = {
  super_admin: "success",
  admin: "info",
  internal_analyst: "neutral",
  external_partner: "neutral",
};
const ROLE_ORDER = ["super_admin", "admin", "internal_analyst", "external_partner"];

function formatDate(isoDateTime) {
  return new Date(isoDateTime).toLocaleDateString("fr-FR", { year: "numeric", month: "short", day: "2-digit" });
}

const CREATE_FORM_DEFAULTS = { name: "", email: "", password: "", role: "internal_analyst" };

export default function AccountUsersPage() {
  const { getCurrentUser, authorizedFetch, API_BASE_URL } = useAuth();
  const { t } = useI18n();

  function roleLabel(role) {
    const entry = ROLE_LABEL_KEYS[role];
    return entry ? t(entry[0], entry[1]) : role;
  }

  const [currentUserEmail, setCurrentUserEmail] = useState(null);
  const [authorized, setAuthorized] = useState(false); // becomes true only once confirmed super_admin
  const [status, setStatus] = useState("loading"); // loading | empty | error | success
  const [errorMessage, setErrorMessage] = useState("");
  const [users, setUsers] = useState([]);
  const [form, setForm] = useState(CREATE_FORM_DEFAULTS);
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState("");
  const [deleteTarget, setDeleteTarget] = useState(null);

  async function loadUsers() {
    setStatus("loading");

    let response;
    try {
      response = await authorizedFetch(API_BASE_URL + "/api/users", { headers: { Accept: "application/json" } });
    } catch (networkError) {
      setErrorMessage("Impossible de contacter le serveur.");
      setStatus("error");
      return;
    }

    if (!response.ok) {
      const body = await response.json().catch(() => null);
      setErrorMessage(body && body.message ? body.message : "Impossible de charger les utilisateurs.");
      setStatus("error");
      return;
    }

    const body = await response.json();
    setUsers(body);
    setStatus(0 === body.length ? "empty" : "success");
  }

  // Reserved to SuperAdmin: the API itself already refuses anyone else
  // (403), but redirect immediately rather than let a non-SuperAdmin sit
  // on a page that can only ever show them errors.
  useEffect(() => {
    let cancelled = false;
    getCurrentUser().then((user) => {
      if (cancelled) {
        return;
      }
      if (null === user) {
        window.location.href = "login.html";
        return;
      }
      if ("super_admin" !== user.role) {
        window.location.href = "account-profile.html";
        return;
      }
      setCurrentUserEmail(user.email);
      setAuthorized(true);
      loadUsers();
    });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function changeRole(id, role) {
    try {
      const response = await authorizedFetch(API_BASE_URL + "/api/users/" + id + "/role", {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ role }),
      });

      if (!response.ok) {
        const body = await response.json().catch(() => null);
        throw new Error(body && body.message ? body.message : "La mise à jour du rôle a échoué.");
      }

      await loadUsers();
    } catch (error) {
      // loadUsers() wasn't called on failure, so the <select> (its value
      // bound to the still-unchanged `users` state) reverts on its own.
      setErrorMessage(error.message);
      setStatus("error");
    }
  }

  async function confirmDelete() {
    const id = deleteTarget;
    setDeleteTarget(null);
    try {
      const response = await authorizedFetch(API_BASE_URL + "/api/users/" + id, { method: "DELETE" });
      if (!response.ok && 204 !== response.status) {
        const body = await response.json().catch(() => null);
        throw new Error(body && body.message ? body.message : "La suppression a échoué.");
      }
      await loadUsers();
    } catch (error) {
      setErrorMessage(error.message);
      setStatus("error");
    }
  }

  async function handleCreateSubmit(event) {
    event.preventDefault();
    setCreating(true);
    setCreateError("");

    try {
      const response = await authorizedFetch(API_BASE_URL + "/api/users", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: form.name.trim(),
          email: form.email.trim(),
          password: form.password,
          role: form.role,
        }),
      });
      const body = await response.json().catch(() => null);

      if (!response.ok) {
        throw new Error(body && body.message ? body.message : "La création a échoué.");
      }

      setForm(CREATE_FORM_DEFAULTS);
      await loadUsers();
    } catch (error) {
      setCreateError(error.message);
    } finally {
      setCreating(false);
    }
  }

  const columns = [
    { key: "name", label: t("usersPage.name", "Nom") },
    { key: "email", label: t("usersPage.email", "Email") },
    { key: "role", label: t("usersPage.role", "Rôle"), render: (user) => <NevBadge tone={ROLE_BADGE_TONES[user.role] || "neutral"}>{roleLabel(user.role)}</NevBadge> },
    { key: "createdAt", label: t("usersPage.createdOn", "Créé le"), render: (user) => formatDate(user.createdAt) },
    {
      key: "actions",
      label: t("usersPage.actions", "Actions"),
      align: "right",
      render: (user) =>
        user.email === currentUserEmail ? (
          <span className="text-xs italic text-dark-6">{t("usersPage.you", "vous")}</span>
        ) : (
          <div className="flex items-center justify-end gap-2">
            <select
              value={user.role}
              onChange={(event) => changeRole(user.id, event.target.value)}
              aria-label={t("usersPage.role", "Rôle") + " - " + user.email}
              className="role-select rounded-md border border-stroke px-2 py-1.5 text-xs focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            >
              {ROLE_ORDER.map((role) => (
                <option key={role} value={role}>
                  {roleLabel(role)}
                </option>
              ))}
            </select>
            <NevButton variant="outline" size="sm" onClick={() => setDeleteTarget(user.id)}>
              {t("usersPage.delete", "Supprimer")}
            </NevButton>
          </div>
        ),
    },
  ];

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-16 pt-[140px] text-center text-white lg:pt-[160px]">
        <div className="container mx-auto px-4">
          <h1 className="mb-3 text-3xl font-bold sm:text-4xl">{t("usersPage.title", "Gestion des utilisateurs")}</h1>
          <p className="mx-auto max-w-[640px] text-white/80">{t("usersPage.subtitle", "Créez des comptes et gérez les rôles de la plateforme NEV Climate Data.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        {!authorized ? (
          <NevCard as="div" padding="lg" className="relative z-20 -mt-10 mb-6 rounded-2xl text-center text-sm text-body-color shadow-card">
            {t("hero.loading", "Chargement…")}
          </NevCard>
        ) : (
          <>
            <NevCard as="section" padding="md" className="relative z-20 -mt-10 mb-6 rounded-2xl shadow-card">
              <h2 className="mb-5 text-lg font-semibold text-dark">{t("usersPage.createTitle", "Créer un utilisateur")}</h2>
              <form onSubmit={handleCreateSubmit} className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
                <NevInput
                  id="new-user-name"
                  label={t("usersPage.name", "Nom")}
                  type="text"
                  required
                  placeholder={t("usersPage.namePlaceholder", "Nom complet")}
                  value={form.name}
                  onChange={(event) => setForm({ ...form, name: event.target.value })}
                />
                <NevInput
                  id="new-user-email"
                  label={t("usersPage.email", "Email")}
                  type="email"
                  required
                  value={form.email}
                  onChange={(event) => setForm({ ...form, email: event.target.value })}
                />
                <NevInput
                  id="new-user-password"
                  label={t("usersPage.password", "Mot de passe")}
                  type="password"
                  required
                  minLength={8}
                  value={form.password}
                  onChange={(event) => setForm({ ...form, password: event.target.value })}
                />
                <NevSelect id="new-user-role" label={t("usersPage.role", "Rôle")} value={form.role} onChange={(event) => setForm({ ...form, role: event.target.value })}>
                  {ROLE_ORDER.map((role) => (
                    <option key={role} value={role}>
                      {roleLabel(role)}
                    </option>
                  ))}
                </NevSelect>
                <NevButton type="submit" disabled={creating} className="w-full">
                  {creating ? t("usersPage.creating", "Création…") : t("usersPage.submitCreate", "Créer le compte")}
                </NevButton>
              </form>
              {createError && <p className="mt-4 text-sm text-danger">{createError}</p>}
            </NevCard>

            <section className="pb-20">
              <NevDataState state={status} loadingText={t("hero.loading", "Chargement…")} emptyText={t("usersPage.empty", "Aucun utilisateur pour le moment.")} errorText={errorMessage} onRetry={loadUsers}>
                <NevTable columns={columns} rows={users} rowKey={(user) => user.id} />
              </NevDataState>
            </section>
          </>
        )}
      </div>

      <NevConfirmDialog
        open={null !== deleteTarget}
        title="Supprimer ce compte ?"
        description={t("usersPage.deleteConfirm", "Cette action est irréversible.")}
        confirmLabel={t("usersPage.delete", "Supprimer")}
        cancelLabel="Annuler"
        tone="danger"
        onConfirm={confirmDelete}
        onCancel={() => setDeleteTarget(null)}
      />
    </>
  );
}
