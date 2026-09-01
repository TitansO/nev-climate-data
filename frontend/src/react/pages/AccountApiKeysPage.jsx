import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";

function formatDate(isoDateTime) {
  return new Date(isoDateTime).toLocaleString("fr-FR", { year: "numeric", month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit" });
}

function StatusBadge({ status }) {
  const classes = "active" === status ? "bg-status-validated-bg text-status-validated" : "bg-dark-8 text-dark-4";
  const label = "active" === status ? "Active" : "Révoquée";
  return <span className={"inline-flex rounded-full px-2.5 py-1 text-xs font-semibold " + classes}>{label}</span>;
}

export default function AccountApiKeysPage() {
  const { requireAuth, authorizedFetch, API_BASE_URL } = useAuth();
  const { t } = useI18n();
  const [status, setStatus] = useState("loading"); // loading | empty | error | success
  const [errorMessage, setErrorMessage] = useState("");
  const [keys, setKeys] = useState([]);
  const [creating, setCreating] = useState(false);
  const [newKey, setNewKey] = useState(null);
  const [copied, setCopied] = useState(false);

  async function loadKeys() {
    setStatus("loading");

    let response;
    try {
      response = await authorizedFetch(API_BASE_URL + "/api/api-keys", { headers: { Accept: "application/json" } });
    } catch (networkError) {
      setErrorMessage("Impossible de contacter le serveur.");
      setStatus("error");
      return;
    }

    if (!response.ok) {
      const body = await response.json().catch(() => null);
      setErrorMessage(body && body.message ? body.message : "Impossible de charger vos clés API.");
      setStatus("error");
      return;
    }

    const body = await response.json();
    setKeys(body);
    setStatus(0 === body.length ? "empty" : "success");
  }

  useEffect(() => {
    requireAuth();
    loadKeys();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function createKey() {
    setCreating(true);
    try {
      const response = await authorizedFetch(API_BASE_URL + "/api/api-keys", { method: "POST" });
      const body = await response.json();

      if (!response.ok) {
        throw new Error(body && body.message ? body.message : "La création de la clé a échoué.");
      }

      setNewKey(body.key);
      await loadKeys();
    } catch (error) {
      setErrorMessage(error.message);
      setStatus("error");
    } finally {
      setCreating(false);
    }
  }

  async function revokeKey(id) {
    if (!window.confirm("Révoquer cette clé ? Toute application qui l'utilise cessera immédiatement de fonctionner.")) {
      return;
    }

    try {
      const response = await authorizedFetch(API_BASE_URL + "/api/api-keys/" + id, { method: "DELETE" });
      if (!response.ok && 204 !== response.status) {
        throw new Error("La révocation a échoué.");
      }
      await loadKeys();
    } catch (error) {
      setErrorMessage(error.message);
      setStatus("error");
    }
  }

  function copyNewKey() {
    navigator.clipboard.writeText(newKey).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    });
  }

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-16 pt-[140px] text-center text-white lg:pt-[160px]">
        <div className="container mx-auto px-4">
          <h1 className="mb-3 text-3xl font-bold sm:text-4xl">{t("profilePage.myApiKeys", "Mes clés API")}</h1>
          <p className="mx-auto max-w-[640px] text-white/80">{t("apiKeysPage.subtitle", "Générez et gérez vos clés d'accès à l'API NEV Climate Data.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <section className="relative z-20 -mt-10 mb-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-white p-6 shadow-2">
          <p className="text-sm text-body-color">{t("apiKeysPage.onceNote", "Chaque clé n'est affichée en clair qu'une seule fois, à sa création.")}</p>
          <button
            type="button"
            disabled={creating}
            onClick={createKey}
            className="rounded-md bg-primary px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-70"
          >
            {creating ? "Génération…" : t("apiKeysPage.generate", "Générer une nouvelle clé")}
          </button>
        </section>

        {null !== newKey && (
          <section className="mb-6 rounded-2xl border border-primary/30 bg-surface-alt p-6">
            <p className="mb-2 text-sm font-semibold text-deep-3">
              {t("apiKeysPage.newKeyGenerated", "Nouvelle clé générée - copiez-la maintenant, elle ne sera plus jamais affichée :")}
            </p>
            <div className="flex flex-wrap items-center gap-3">
              <code className="flex-1 break-all rounded-md bg-dark px-4 py-3 text-sm text-gray-7">{newKey}</code>
              <button type="button" onClick={copyNewKey} className="rounded-md border border-stroke bg-white px-4 py-2.5 text-sm font-semibold text-dark-4 hover:bg-gray-2">
                {copied ? "Copié !" : t("apiKeysPage.copy", "Copier")}
              </button>
            </div>
            <button type="button" className="mt-4 text-sm font-medium text-primary hover:text-primary-dark" onClick={() => setNewKey(null)}>
              {t("apiKeysPage.saved", "J'ai sauvegardé ma clé")}
            </button>
          </section>
        )}

        <section className="pb-20">
          {"loading" === status && <div className="rounded-2xl border border-stroke bg-white p-16 text-center text-sm text-body-color">{t("hero.loading", "Chargement…")}</div>}

          {"empty" === status && (
            <div className="rounded-2xl border border-stroke bg-white p-16 text-center">
              <p className="text-sm text-body-color">{t("apiKeysPage.empty", "Vous n'avez encore aucune clé API.")}</p>
            </div>
          )}

          {"error" === status && <div className="rounded-2xl border border-status-demo/30 bg-status-demo-bg/40 p-16 text-center text-sm text-status-demo">{errorMessage}</div>}

          {"success" === status && (
            <div className="overflow-hidden rounded-2xl border border-stroke bg-white shadow-1">
              <div className="overflow-x-auto">
                <table className="w-full min-w-[700px] text-left text-sm">
                  <thead className="bg-surface-alt text-xs font-semibold uppercase tracking-wide text-dark-4">
                    <tr>
                      <th className="px-5 py-4">{t("apiKeysPage.identifier", "Identifiant")}</th>
                      <th className="px-5 py-4">{t("dataPage.status", "Statut")}</th>
                      <th className="px-5 py-4">{t("apiKeysPage.quota", "Quota (req./jour)")}</th>
                      <th className="px-5 py-4">{t("apiKeysPage.createdOn", "Créée le")}</th>
                      <th className="px-5 py-4">{t("apiKeysPage.revokedOn", "Révoquée le")}</th>
                      <th className="px-5 py-4"></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-stroke">
                    {keys.map((key) => (
                      <tr key={key.id}>
                        <td className="px-5 py-4 font-medium text-dark">#{key.id}</td>
                        <td className="px-5 py-4">
                          <StatusBadge status={key.status} />
                        </td>
                        <td className="px-5 py-4">{key.quota.toLocaleString("fr-FR")}</td>
                        <td className="px-5 py-4">{formatDate(key.created_at)}</td>
                        <td className="px-5 py-4">{key.revoked_at ? formatDate(key.revoked_at) : "—"}</td>
                        <td className="px-5 py-4 text-right">
                          {"active" === key.status && (
                            <button
                              type="button"
                              onClick={() => revokeKey(key.id)}
                              className="rounded-md border border-status-demo/40 px-3 py-1.5 text-xs font-semibold text-status-demo hover:bg-status-demo-bg"
                            >
                              Révoquer
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </section>
      </div>
    </>
  );
}
