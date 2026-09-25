import { useEffect, useMemo, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import RequireAuth from "../components/RequireAuth";
import NevCard from "../components/ui/NevCard";
import NevBadge from "../components/ui/NevBadge";
import NevInput from "../components/ui/NevInput";
import NevSelect from "../components/ui/NevSelect";
import NevDataState from "../components/ui/NevDataState";
import { formatUsd } from "../data/analyticsConstants";
import { BuildingIcon, DocumentIcon, MailIcon, LinkIcon } from "../components/ui/icons";

const STATUS_LABELS = { open: "Ouvert", upcoming: "À venir", closed: "Fermé" };
const STATUS_TONES = { open: "success", upcoming: "info", closed: "neutral" };

// A3.13 : miroir de App\Entity\Enum\TenderCategory côté backend.
const CATEGORY_LABELS = {
  consulting: "Consulting / études",
  goods: "Fournitures",
  works: "Travaux",
  services: "Services",
};

function formatDate(isoDate) {
  if (!isoDate) {
    return null;
  }
  const date = new Date(isoDate + "T00:00:00");
  return Number.isNaN(date.getTime()) ? isoDate : date.toLocaleDateString("fr-FR", { year: "numeric", month: "short", day: "2-digit" });
}

function stateFromUrl() {
  const params = new URLSearchParams(window.location.search);
  return { search: params.get("search") || "", status: params.get("status") || "", category: params.get("category") || "" };
}

/**
 * A3.13 : "Tender Radar" - GET /api/tenders. Jumeau de OpportunityRadarPage
 * (C1.8) pour les appels d'offres (marchés de prestation) plutôt que les
 * appels à projets (financement) - voir App\Entity\Tender pour la
 * différence de fond entre les deux concepts.
 */
export default function TendersPage() {
  const { API_BASE_URL, isAuthenticated, authorizedFetch } = useAuth();
  const { t } = useI18n();
  const initial = stateFromUrl();

  const [search, setSearch] = useState(initial.search);
  const [status, setStatus] = useState(initial.status);
  const [category, setCategory] = useState(initial.category);
  const [state, setState] = useState("loading");
  const [tenders, setTenders] = useState([]);
  const [message, setMessage] = useState("");

  const queryString = useMemo(() => {
    const params = new URLSearchParams();
    if (search.trim()) {
      params.set("search", search.trim());
    }
    if (status) {
      params.set("status", status);
    }
    if (category) {
      params.set("category", category);
    }
    return params.toString();
  }, [search, status, category]);

  useEffect(() => {
    let cancelled = false;
    setState("loading");
    window.history.replaceState(null, "", window.location.pathname + (queryString ? "?" + queryString : ""));

    (async () => {
      let response;
      try {
        response = await authorizedFetch(API_BASE_URL + "/api/tenders" + (queryString ? "?" + queryString : ""), { headers: { Accept: "application/json" } });
      } catch (networkError) {
        if (!cancelled) {
          setState("error");
          setMessage(t("tenderPage.networkError", "Impossible de contacter le serveur."));
        }
        return;
      }
      if (cancelled) {
        return;
      }
      if (!response.ok) {
        setState("error");
        setMessage(t("tenderPage.genericError", "Une erreur est survenue."));
        return;
      }
      const body = await response.json();
      if (cancelled) {
        return;
      }
      setTenders(body.data);
      setState(0 === body.data.length ? "empty" : "success");
    })();

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [queryString]);

  if (!isAuthenticated()) {
    return <RequireAuth />;
  }

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-[60px] pt-[48px] text-left text-white">
        <div className="container mx-auto px-4">
          <span className="mb-[18px] inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-accent">
            <BuildingIcon className="h-3 w-3" /> {t("tenderPage.pill", "Radar des appels d'offres")}
          </span>
          <h1 className="mb-[14px] text-[clamp(1.7rem,4vw,2.6rem)] font-bold leading-[1.2]">{t("tenderPage.title", "Marchés de prestation ouverts")}</h1>
          <p className="max-w-[620px] text-base text-white/80">
            {t(
              "tenderPage.subtitle",
              "Études, fournitures, travaux et services liés au financement climatique - à ne pas confondre avec les appels à projets : ici, vous êtes payé pour livrer une prestation, pas financé pour votre propre projet."
            )}
          </p>
          <a href="opportunities.html" className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-white/90 hover:underline">
            {t("tenderPage.seeOpportunities", "Vous cherchez plutôt un financement pour votre projet ? Voir les appels à projets →")}
          </a>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <NevCard padding="md" className="relative z-20 -mt-10 mb-8">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <NevInput
              id="tender-search"
              label={t("tenderPage.search", "Recherche (titre, organisme)")}
              placeholder={t("tenderPage.searchPlaceholder", "Ex : AfDB, solaire, transport...")}
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
            <NevSelect id="tender-category" label={t("tenderPage.category", "Catégorie")} value={category} onChange={(event) => setCategory(event.target.value)}>
              <option value="">{t("tenderPage.allCategories", "Toutes les catégories")}</option>
              {Object.entries(CATEGORY_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </NevSelect>
            <NevSelect id="tender-status" label={t("tenderPage.status", "Statut")} value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="">{t("tenderPage.allStatuses", "Tous les statuts")}</option>
              <option value="open">{t("tenderPage.statusOpen", "Ouvert")}</option>
              <option value="upcoming">{t("tenderPage.statusUpcoming", "À venir")}</option>
              <option value="closed">{t("tenderPage.statusClosed", "Fermé")}</option>
            </NevSelect>
          </div>
        </NevCard>

        <section className="pb-16">
          <NevDataState
            state={state}
            loadingText={t("tenderPage.loading", "Chargement des appels d'offres…")}
            errorText={message}
            emptyText={t("tenderPage.empty", "Aucun appel d'offres ne correspond à ces critères.")}
          >
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              {tenders.map((tender) => (
                <NevCard key={tender.id} padding="lg" interactive className="flex flex-col">
                  <div className="mb-1 flex items-start justify-between gap-3">
                    <h3 className="text-lg font-bold text-dark">{tender.title}</h3>
                    <div className="flex shrink-0 flex-col items-end gap-1.5">
                      <NevBadge tone={STATUS_TONES[tender.status]}>{STATUS_LABELS[tender.status]}</NevBadge>
                      <NevBadge tone="neutral">{CATEGORY_LABELS[tender.category] || tender.category}</NevBadge>
                    </div>
                  </div>
                  <p className="mb-3 flex items-center gap-1.5 text-sm font-semibold text-primary-dark">
                    <BuildingIcon className="h-3.5 w-3.5 shrink-0" /> {tender.issuingOrganization}
                    {tender.referenceNumber && <span className="font-normal text-dark-5">· {tender.referenceNumber}</span>}
                  </p>
                  <p className="mb-3 text-sm text-body-color">{tender.description}</p>
                  <p className="mb-4 text-xs text-dark-5">
                    <span className="font-semibold text-dark-4">{t("tenderPage.eligibility", "Éligibilité")} : </span>
                    {tender.eligibilityCriteria}
                  </p>
                  <dl className="space-y-2 border-t border-stroke pt-4 text-sm">
                    {tender.sector && (
                      <div className="flex justify-between">
                        <dt className="text-dark-5">{t("tenderPage.sector", "Secteur")}</dt>
                        <dd className="font-semibold text-dark-3">{tender.sector}</dd>
                      </div>
                    )}
                    <div className="flex justify-between">
                      <dt className="text-dark-5">{t("tenderPage.estimatedValue", "Valeur estimée")}</dt>
                      <dd className="font-semibold text-dark-3">{null === tender.estimatedValue ? t("tenderPage.undisclosed", "Non communiquée") : formatUsd(tender.estimatedValue)}</dd>
                    </div>
                    <div className="flex justify-between">
                      <dt className="text-dark-5">{t("tenderPage.published", "Publié le")}</dt>
                      <dd className="font-semibold text-dark-3">{formatDate(tender.publishedAt)}</dd>
                    </div>
                    <div className="flex justify-between">
                      <dt className="text-dark-5">{t("tenderPage.deadline", "Dépôt des offres avant")}</dt>
                      <dd className="font-semibold text-dark-3">{formatDate(tender.submissionDeadline)}</dd>
                    </div>
                    {tender.contactEmail && (
                      <div className="flex justify-between">
                        <dt className="text-dark-5">{t("tenderPage.contact", "Contact")}</dt>
                        <dd>
                          <a href={"mailto:" + tender.contactEmail} className="inline-flex items-center gap-1 font-semibold text-primary-dark hover:underline">
                            <MailIcon className="h-3.5 w-3.5" /> {tender.contactEmail}
                          </a>
                        </dd>
                      </div>
                    )}
                  </dl>
                  {tender.documentsUrl && (
                    <a
                      href={tender.documentsUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="mt-4 inline-flex items-center justify-center gap-1.5 self-start rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark"
                    >
                      <DocumentIcon className="h-3.5 w-3.5" /> {t("tenderPage.viewDossier", "Consulter le dossier complet")} <LinkIcon className="h-3.5 w-3.5" />
                    </a>
                  )}
                </NevCard>
              ))}
            </div>
          </NevDataState>
        </section>
      </div>
    </>
  );
}
