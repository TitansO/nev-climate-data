import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";
import NevButton from "../components/ui/NevButton";
import NevBadge from "../components/ui/NevBadge";
import NevDataState from "../components/ui/NevDataState";
import NevPagination from "../components/ui/NevPagination";

const PAGE_SIZE = 10;
const EVENT_TYPE_LABELS = {
  new_report: "Nouveau rapport",
  new_data: "Nouvelles données",
};

function formatDate(isoDateTime) {
  return new Date(isoDateTime).toLocaleString("fr-FR", { year: "numeric", month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit" });
}

export default function NotificationsPage() {
  const { requireAuth, authorizedFetch, API_BASE_URL } = useAuth();
  const { t } = useI18n();

  const [status, setStatus] = useState("loading"); // loading | empty | error | success
  const [errorMessage, setErrorMessage] = useState("");
  const [items, setItems] = useState([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [markingAll, setMarkingAll] = useState(false);

  async function loadNotifications(targetPage) {
    setStatus("loading");

    let response;
    try {
      response = await authorizedFetch(API_BASE_URL + "/api/notifications?page=" + targetPage + "&limit=" + PAGE_SIZE, {
        headers: { Accept: "application/json" },
      });
    } catch (networkError) {
      setErrorMessage("Impossible de contacter le serveur.");
      setStatus("error");
      return;
    }

    if (!response.ok) {
      const body = await response.json().catch(() => null);
      setErrorMessage(body && body.message ? body.message : "Impossible de charger vos notifications.");
      setStatus("error");
      return;
    }

    const body = await response.json();
    setPage(body.meta.page);
    setTotalPages(Math.max(body.meta.totalPages, 1));
    setTotal(body.meta.total);
    setItems(body.data);
    setStatus(0 === body.data.length ? "empty" : "success");
  }

  useEffect(() => {
    requireAuth();
    loadNotifications(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function markAsRead(id) {
    try {
      const response = await authorizedFetch(API_BASE_URL + "/api/notifications/" + id + "/read", { method: "PATCH" });
      if (!response.ok && 204 !== response.status) {
        throw new Error("Le marquage comme lue a échoué.");
      }
      await loadNotifications(page);
    } catch (error) {
      setErrorMessage(error.message);
      setStatus("error");
    }
  }

  async function markAllAsRead() {
    setMarkingAll(true);
    try {
      const response = await authorizedFetch(API_BASE_URL + "/api/notifications/read-all", { method: "POST" });
      if (!response.ok) {
        throw new Error("Le marquage global a échoué.");
      }
      await loadNotifications(page);
    } catch (error) {
      setErrorMessage(error.message);
      setStatus("error");
    } finally {
      setMarkingAll(false);
    }
  }

  const unreadCount = items.filter((item) => !item.isRead).length;

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-16 pt-[140px] text-center text-white lg:pt-[160px]">
        <div className="container mx-auto px-4">
          <h1 className="mb-3 text-3xl font-bold sm:text-4xl">{t("notifPage.title", "Mes notifications")}</h1>
          <p className="mx-auto max-w-[640px] text-white/80">{t("notifPage.subtitle", "Événements liés à votre compte NEV Climate Data.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <NevCard as="section" padding="md" className="relative z-20 -mt-10 mb-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl shadow-card">
          <p className="text-sm text-body-color">{"loading" === status ? t("hero.loading", "Chargement…") : total + " notification(s) au total"}</p>
          {unreadCount > 0 && (
            <NevButton variant="outline" size="md" disabled={markingAll} onClick={markAllAsRead}>
              {t("notifPage.markAllRead", "Tout marquer comme lu")}
            </NevButton>
          )}
        </NevCard>

        <section className="pb-20">
          <NevDataState
            state={status}
            loadingText={t("hero.loading", "Chargement…")}
            emptyText={t("notifPage.empty", "Vous n'avez aucune notification pour le moment.")}
            errorText={errorMessage}
            onRetry={() => loadNotifications(page)}
          >
            <div>
              <ul className="space-y-3">
                {items.map((item) => (
                  <li key={item.id} className={"flex items-start justify-between gap-4 rounded-lg border p-5 " + (item.isRead ? "border-stroke bg-white" : "border-primary/30 bg-surface-alt")}>
                    {/* The whole content block is a link to item.destination
                        (A2.10) - the real frontend page the event is about -
                        kept separate from "Marquer comme lue" so clicking to
                        navigate never silently marks it read as a side effect. */}
                    <a
                      href={item.destination}
                      className="block flex-1 rounded-sm transition hover:opacity-80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    >
                      <NevBadge tone="info" className="mb-1.5">
                        {EVENT_TYPE_LABELS[item.eventType] || item.eventType}
                      </NevBadge>
                      <p className="text-sm text-dark">{item.content}</p>
                      <p className="mt-1 text-xs text-dark-6">{formatDate(item.createdAt)}</p>
                    </a>
                    {!item.isRead && (
                      <NevButton variant="outline" size="sm" className="shrink-0" onClick={() => markAsRead(item.id)}>
                        Marquer comme lue
                      </NevButton>
                    )}
                  </li>
                ))}
              </ul>

              <div className="mt-8">
                <NevPagination
                  pageLabel={"Page " + page + " sur " + totalPages}
                  onPrevious={() => loadNotifications(page - 1)}
                  onNext={() => loadNotifications(page + 1)}
                  disabledPrevious={page <= 1}
                  disabledNext={page >= totalPages}
                  previousLabel={t("dataPage.previous", "Précédent")}
                  nextLabel={t("dataPage.next", "Suivant")}
                />
              </div>
            </div>
          </NevDataState>
        </section>
      </div>
    </>
  );
}
