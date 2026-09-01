import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";

const ROLE_LABEL_KEYS = {
  super_admin: ["usersPage.roleSuperAdmin", "Super administrateur"],
  admin: ["usersPage.roleAdmin", "Administrateur"],
  internal_analyst: ["usersPage.roleInternalAnalyst", "Analyste interne"],
  external_partner: ["usersPage.roleExternalPartner", "Partenaire externe"],
};
const ROLE_BADGE_CLASSES = {
  super_admin: "bg-primary/10 text-primary-dark",
  admin: "bg-status-validated-bg text-status-validated",
  internal_analyst: "bg-status-review-bg text-status-review",
  external_partner: "bg-dark-8 text-dark-4",
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

  async function deleteUser(id) {
    if (!window.confirm(t("usersPage.deleteConfirm", "Supprimer ce compte ? Cette action est irréversible."))) {
      return;
    }

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
          <div className="relative z-20 -mt-10 mb-6 rounded-2xl bg-white p-16 text-center text-sm text-body-color shadow-2">{t("hero.loading", "Chargement…")}</div>
        ) : (
          <>
            <section className="relative z-20 -mt-10 mb-6 rounded-2xl bg-white p-6 shadow-2">
              <h2 className="mb-5 text-base font-semibold text-dark">{t("usersPage.createTitle", "Créer un utilisateur")}</h2>
              <form onSubmit={handleCreateSubmit} className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
                <div className="lg:col-span-1">
                  <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-dark-5">{t("usersPage.name", "Nom")}</label>
                  <input
                    type="text"
                    required
                    placeholder={t("usersPage.namePlaceholder", "Nom complet")}
                    value={form.name}
                    onChange={(event) => setForm({ ...form, name: event.target.value })}
                    className="w-full rounded-md border border-stroke px-3.5 py-2.5 text-sm outline-none focus:border-primary"
                  />
                </div>
                <div className="lg:col-span-1">
                  <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-dark-5">{t("usersPage.email", "Email")}</label>
                  <input
                    type="email"
                    required
                    value={form.email}
                    onChange={(event) => setForm({ ...form, email: event.target.value })}
                    className="w-full rounded-md border border-stroke px-3.5 py-2.5 text-sm outline-none focus:border-primary"
                  />
                </div>
                <div className="lg:col-span-1">
                  <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-dark-5">{t("usersPage.password", "Mot de passe")}</label>
                  <input
                    type="password"
                    required
                    minLength={8}
                    value={form.password}
                    onChange={(event) => setForm({ ...form, password: event.target.value })}
                    className="w-full rounded-md border border-stroke px-3.5 py-2.5 text-sm outline-none focus:border-primary"
                  />
                </div>
                <div className="lg:col-span-1">
                  <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-dark-5">{t("usersPage.role", "Rôle")}</label>
                  <select
                    value={form.role}
                    onChange={(event) => setForm({ ...form, role: event.target.value })}
                    className="w-full rounded-md border border-stroke px-3.5 py-2.5 text-sm outline-none focus:border-primary"
                  >
                    {ROLE_ORDER.map((role) => (
                      <option key={role} value={role}>
                        {roleLabel(role)}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="lg:col-span-1">
                  <button
                    type="submit"
                    disabled={creating}
                    className="w-full rounded-md bg-primary px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-70"
                  >
                    {creating ? t("usersPage.creating", "Création…") : t("usersPage.submitCreate", "Créer le compte")}
                  </button>
                </div>
              </form>
              {createError && <p className="mt-4 text-sm text-status-demo">{createError}</p>}
            </section>

            <section className="pb-20">
              {"loading" === status && (
                <div className="rounded-2xl border border-stroke bg-white p-16 text-center text-sm text-body-color">{t("hero.loading", "Chargement…")}</div>
              )}

              {"empty" === status && (
                <div className="rounded-2xl border border-stroke bg-white p-16 text-center">
                  <p className="text-sm text-body-color">{t("usersPage.empty", "Aucun utilisateur pour le moment.")}</p>
                </div>
              )}

              {"error" === status && <div className="rounded-2xl border border-status-demo/30 bg-status-demo-bg/40 p-16 text-center text-sm text-status-demo">{errorMessage}</div>}

              {"success" === status && (
                <div className="overflow-hidden rounded-2xl border border-stroke bg-white shadow-1">
                  <div className="overflow-x-auto">
                    <table className="w-full min-w-[800px] text-left text-sm">
                      <thead className="bg-surface-alt text-xs font-semibold uppercase tracking-wide text-dark-4">
                        <tr>
                          <th className="px-5 py-4">{t("usersPage.name", "Nom")}</th>
                          <th className="px-5 py-4">{t("usersPage.email", "Email")}</th>
                          <th className="px-5 py-4">{t("usersPage.role", "Rôle")}</th>
                          <th className="px-5 py-4">{t("usersPage.createdOn", "Créé le")}</th>
                          <th className="px-5 py-4">{t("usersPage.actions", "Actions")}</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-stroke">
                        {users.map((user) => {
                          const isSelf = user.email === currentUserEmail;
                          return (
                            <tr key={user.id}>
                              <td className="px-5 py-4 font-medium text-dark">{user.name}</td>
                              <td className="px-5 py-4">{user.email}</td>
                              <td className="px-5 py-4">
                                <span className={"inline-flex rounded-full px-2.5 py-1 text-xs font-semibold " + (ROLE_BADGE_CLASSES[user.role] || "bg-dark-8 text-dark-4")}>
                                  {roleLabel(user.role)}
                                </span>
                              </td>
                              <td className="px-5 py-4">{formatDate(user.createdAt)}</td>
                              <td className="px-5 py-4 text-right">
                                {isSelf ? (
                                  <span className="text-xs italic text-dark-6">{t("usersPage.you", "vous")}</span>
                                ) : (
                                  <>
                                    <select
                                      value={user.role}
                                      onChange={(event) => changeRole(user.id, event.target.value)}
                                      className="role-select rounded-md border border-stroke px-2 py-1.5 text-xs"
                                    >
                                      {ROLE_ORDER.map((role) => (
                                        <option key={role} value={role}>
                                          {roleLabel(role)}
                                        </option>
                                      ))}
                                    </select>{" "}
                                    <button
                                      type="button"
                                      onClick={() => deleteUser(user.id)}
                                      className="ml-2 rounded-md border border-status-demo/40 px-3 py-1.5 text-xs font-semibold text-status-demo hover:bg-status-demo-bg"
                                    >
                                      {t("usersPage.delete", "Supprimer")}
                                    </button>
                                  </>
                                )}
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}
            </section>
          </>
        )}
      </div>
    </>
  );
}
