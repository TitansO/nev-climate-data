import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";
import NevButton from "../components/ui/NevButton";
import NevBadge from "../components/ui/NevBadge";
import NevDataState from "../components/ui/NevDataState";
import NevTable from "../components/ui/NevTable";
import NevConfirmDialog from "../components/ui/NevConfirmDialog";

function formatDate(isoDateTime) {
  return new Date(isoDateTime).toLocaleString("fr-FR", { year: "numeric", month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit" });
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
  const [revokeTarget, setRevokeTarget] = useState(null);

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

  async function confirmRevoke() {
    const id = revokeTarget;
    setRevokeTarget(null);
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

  const columns = [
    { key: "id", label: t("apiKeysPage.identifier", "Identifiant"), render: (key) => "#" + key.id },
    { key: "status", label: t("dataPage.status", "Statut"), render: (key) => <NevBadge tone={"active" === key.status ? "success" : "neutral"}>{"active" === key.status ? "Active" : "Révoquée"}</NevBadge> },
    { key: "quota", label: t("apiKeysPage.quota", "Quota (req./jour)"), align: "right", render: (key) => key.quota.toLocaleString("fr-FR") },
    { key: "created_at", label: t("apiKeysPage.createdOn", "Créée le"), render: (key) => formatDate(key.created_at) },
    { key: "revoked_at", label: t("apiKeysPage.revokedOn", "Révoquée le"), render: (key) => (key.revoked_at ? formatDate(key.revoked_at) : "—") },
    {
      key: "actions",
      label: "",
      align: "right",
      render: (key) =>
        "active" === key.status ? (
          <NevButton variant="outline" size="sm" onClick={() => setRevokeTarget(key.id)}>
            Révoquer
          </NevButton>
        ) : null,
    },
  ];

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-16 pt-[140px] text-center text-white lg:pt-[160px]">
        <div className="container mx-auto px-4">
          <h1 className="mb-3 text-3xl font-bold sm:text-4xl">{t("profilePage.myApiKeys", "Mes clés API")}</h1>
          <p className="mx-auto max-w-[640px] text-white/80">{t("apiKeysPage.subtitle", "Générez et gérez vos clés d'accès à l'API NEV Climate Data.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <NevCard as="section" padding="md" className="relative z-20 -mt-10 mb-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl shadow-card">
          <p className="text-sm text-body-color">{t("apiKeysPage.onceNote", "Chaque clé n'est affichée en clair qu'une seule fois, à sa création.")}</p>
          <NevButton type="button" disabled={creating} onClick={createKey}>
            {creating ? "Génération…" : t("apiKeysPage.generate", "Générer une nouvelle clé")}
          </NevButton>
        </NevCard>

        {null !== newKey && (
          <NevCard as="section" padding="md" className="mb-6 border-primary/30 bg-surface-alt">
            <p className="mb-2 text-sm font-semibold text-deep-3">
              {t("apiKeysPage.newKeyGenerated", "Nouvelle clé générée - copiez-la maintenant, elle ne sera plus jamais affichée :")}
            </p>
            <div className="flex flex-wrap items-center gap-3">
              <code className="flex-1 break-all rounded-md bg-dark px-4 py-3 text-sm text-gray-7">{newKey}</code>
              <NevButton variant="outline" size="sm" onClick={copyNewKey}>
                {copied ? "Copié !" : t("apiKeysPage.copy", "Copier")}
              </NevButton>
            </div>
            <NevButton variant="ghost" size="sm" className="mt-4 px-0" onClick={() => setNewKey(null)}>
              {t("apiKeysPage.saved", "J'ai sauvegardé ma clé")}
            </NevButton>
          </NevCard>
        )}

        <section className="pb-20">
          <NevDataState state={status} loadingText={t("hero.loading", "Chargement…")} emptyText={t("apiKeysPage.empty", "Vous n'avez encore aucune clé API.")} errorText={errorMessage} onRetry={loadKeys}>
            <NevTable columns={columns} rows={keys} rowKey={(key) => key.id} />
          </NevDataState>
        </section>
      </div>

      <NevConfirmDialog
        open={null !== revokeTarget}
        title="Révoquer cette clé API ?"
        description="Toute application qui l'utilise cessera immédiatement de fonctionner."
        confirmLabel="Révoquer"
        cancelLabel="Annuler"
        tone="danger"
        onConfirm={confirmRevoke}
        onCancel={() => setRevokeTarget(null)}
      />
    </>
  );
}
