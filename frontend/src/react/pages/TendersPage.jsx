import { useEffect, useMemo, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import RequireAuth from "../components/RequireAuth";
import NevCard from "../components/ui/NevCard";
import NevBadge from "../components/ui/NevBadge";
import NevInput from "../components/ui/NevInput";
import NevSelect from "../components/ui/NevSelect";
import NevButton from "../components/ui/NevButton";
import NevDataState from "../components/ui/NevDataState";
import NevTable from "../components/ui/NevTable";
import { formatUsd } from "../data/analyticsConstants";
import { BuildingIcon, DocumentIcon, MailIcon, LinkIcon, BellIcon } from "../components/ui/icons";

const STATUS_LABELS = { open: "Ouvert", upcoming: "À venir", closed: "Fermé" };
const STATUS_TONES = { open: "success", upcoming: "info", closed: "neutral" };

// A3.13 : miroir de App\Entity\Enum\TenderCategory côté backend.
const CATEGORY_LABELS = {
  consulting: "Consulting / études",
  goods: "Fournitures",
  works: "Travaux",
  services: "Services",
};

// Audit Prompt Maitre #3 (point 6) : fenetre du widget "cloture imminente" -
// arbitraire mais raisonnable pour un appel a consultance (delai de montage
// de dossier habituel), pas une regle metier documentee ailleurs.
const CLOSING_SOON_DAYS = 14;

function formatDate(isoDate) {
  if (!isoDate) {
    return null;
  }
  const date = new Date(isoDate + "T00:00:00");
  return Number.isNaN(date.getTime()) ? isoDate : date.toLocaleDateString("fr-FR", { year: "numeric", month: "short", day: "2-digit" });
}

// Jours restants avant minuit (heure locale) de submissionDeadline, par
// rapport a maintenant - meme calendrier que "status" (deja calcule cote
// backend par rapport a maintenant, jamais stocke), jamais negatif pour un
// appel deja ferme (ceux-la sont exclus du widget avant meme d'appeler ceci).
function daysUntil(isoDate) {
  const deadline = new Date(isoDate + "T00:00:00");
  if (Number.isNaN(deadline.getTime())) {
    return null;
  }
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return Math.round((deadline.getTime() - today.getTime()) / 86400000);
}

function stateFromUrl() {
  const params = new URLSearchParams(window.location.search);
  return { search: params.get("search") || "", status: params.get("status") || "", category: params.get("category") || "" };
}

/**
 * A3.13 : "Tender Radar" - GET /api/tenders. Jumeau de OpportunityRadarPage
 * (C1.8) pour les appels à consultance (marchés de prestation, libelle
 * "Appel d'offres" renomme le 2026-09-28) plutôt que les
 * appels à projets (financement) - voir App\Entity\Tender pour la
 * différence de fond entre les deux concepts.
 *
 * Audit Prompt Maitre #3 (point 6) : vues ajoutees sans changement de
 * modele de donnees - `Tender` n'a aucun champ geographique (ni pays ni
 * region), donc une vue carte est hors-scope ici (voir le docblock de
 * App\Entity\Tender). Bascule liste/cartes, tri (echeance/valeur) et
 * widget "cloture imminente" sont tous calcules cote client a partir du
 * meme jeu de resultats deja charge - aucun nouvel appel API.
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
  const [viewMode, setViewMode] = useState("cards");
  const [sortBy, setSortBy] = useState("deadline");

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

  // "deadline" reproduit l'ordre deja renvoye par /api/tenders (le plus
  // urgent d'abord) - un tri client ici n'est donc utile que pour "value"
  // (non supporte cote API), mais garde les deux options symetriques plutot
  // que de traiter "deadline" comme un simple passthrough.
  const sortedTenders = useMemo(() => {
    const copy = [...tenders];
    if ("value" === sortBy) {
      copy.sort((a, b) => (null === b.estimatedValue ? -1 : null === a.estimatedValue ? 1 : b.estimatedValue - a.estimatedValue));
    } else {
      copy.sort((a, b) => new Date(a.submissionDeadline) - new Date(b.submissionDeadline));
    }
    return copy;
  }, [tenders, sortBy]);

  // Widget "cloture imminente" : uniquement les appels reellement ouverts
  // (jamais "a venir"/"ferme") dont l'echeance tombe dans la fenetre -
  // calcule sur `tenders` (pas `sortedTenders`) pour rester independant du
  // tri choisi par l'utilisateur pour la liste principale.
  const closingSoon = useMemo(() => {
    return tenders
      .filter((tender) => "open" === tender.status)
      .map((tender) => ({ tender, days: daysUntil(tender.submissionDeadline) }))
      .filter(({ days }) => null !== days && days >= 0 && days <= CLOSING_SOON_DAYS)
      .sort((a, b) => a.days - b.days);
  }, [tenders]);

  const tenderColumns = [
    {
      key: "title",
      label: t("tenderPage.title", "Marchés de prestation ouverts"),
      render: (tender) => (
        <div>
          <span className="font-semibold text-dark">{tender.title}</span>
          {tender.referenceNumber && <span className="ml-1.5 text-xs text-dark-5">· {tender.referenceNumber}</span>}
        </div>
      ),
    },
    { key: "issuingOrganization", label: t("tenderPage.contact", "Contact") },
    {
      key: "category",
      label: t("tenderPage.category", "Catégorie"),
      render: (tender) => <NevBadge tone="neutral">{CATEGORY_LABELS[tender.category] || tender.category}</NevBadge>,
    },
    {
      key: "status",
      label: t("tenderPage.status", "Statut"),
      render: (tender) => <NevBadge tone={STATUS_TONES[tender.status]}>{STATUS_LABELS[tender.status]}</NevBadge>,
    },
    {
      key: "estimatedValue",
      label: t("tenderPage.estimatedValue", "Valeur estimée"),
      align: "right",
      render: (tender) => (null === tender.estimatedValue ? t("tenderPage.undisclosed", "Non communiquée") : formatUsd(tender.estimatedValue)),
    },
    {
      key: "submissionDeadline",
      label: t("tenderPage.deadline", "Dépôt des offres avant"),
      align: "right",
      width: "w-36",
      render: (tender) => formatDate(tender.submissionDeadline),
    },
  ];

  if (!isAuthenticated()) {
    return <RequireAuth />;
  }

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-[60px] pt-[48px] text-left text-white">
        <div className="container mx-auto px-4">
          <span className="mb-[18px] inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-accent">
            <BuildingIcon className="h-3 w-3" /> {t("tenderPage.pill", "Radar des appels à consultance")}
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
          <div className="mt-4 flex flex-wrap items-end justify-between gap-4 border-t border-stroke pt-4">
            <NevSelect
              id="tender-sort"
              wrapperClassName="min-w-[14rem]"
              label={t("tenderPage.sortBy", "Trier par")}
              value={sortBy}
              onChange={(event) => setSortBy(event.target.value)}
            >
              <option value="deadline">{t("tenderPage.sortDeadline", "Échéance (la plus proche d'abord)")}</option>
              <option value="value">{t("tenderPage.sortValue", "Valeur estimée (la plus élevée d'abord)")}</option>
            </NevSelect>
            <div className="flex items-center gap-1.5 rounded-full border border-stroke bg-surface-alt p-1" role="group" aria-label={t("tenderPage.viewMode", "Mode d'affichage")}>
              <NevButton
                type="button"
                variant={"cards" === viewMode ? "primary" : "ghost"}
                size="sm"
                className="rounded-full"
                onClick={() => setViewMode("cards")}
                aria-pressed={"cards" === viewMode}
              >
                {t("tenderPage.viewCards", "Cartes")}
              </NevButton>
              <NevButton
                type="button"
                variant={"list" === viewMode ? "primary" : "ghost"}
                size="sm"
                className="rounded-full"
                onClick={() => setViewMode("list")}
                aria-pressed={"list" === viewMode}
              >
                {t("tenderPage.viewList", "Liste")}
              </NevButton>
            </div>
          </div>
        </NevCard>

        {closingSoon.length > 0 && (
          <NevCard padding="md" className="mb-8 border-l-4 border-l-warning bg-status-demo-bg">
            <div className="mb-2 flex items-center gap-2 text-sm font-bold text-dark">
              <BellIcon className="h-4 w-4 shrink-0 text-warning" />
              {t("tenderPage.closingSoonTitle", "Clôture imminente (sous {days} jours)").replace("{days}", CLOSING_SOON_DAYS)}
            </div>
            <ul className="divide-y divide-stroke/60">
              {closingSoon.map(({ tender, days }) => (
                <li key={tender.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                  <span className="text-dark-4">{tender.title}</span>
                  <span className="shrink-0 font-semibold tabular-nums text-warning">
                    {0 === days ? t("tenderPage.closingToday", "Aujourd'hui") : t("tenderPage.closingInDays", "{n} j").replace("{n}", days)}
                  </span>
                </li>
              ))}
            </ul>
          </NevCard>
        )}

        <section className="pb-16">
          <NevDataState
            state={state}
            loadingText={t("tenderPage.loading", "Chargement des appels à consultance…")}
            errorText={message}
            emptyText={t("tenderPage.empty", "Aucun appel à consultance ne correspond à ces critères.")}
          >
            {"list" === viewMode ? (
              <NevTable columns={tenderColumns} rows={sortedTenders} rowKey={(tender) => tender.id} />
            ) : (
              <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {sortedTenders.map((tender) => (
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
            )}
          </NevDataState>
        </section>
      </div>
    </>
  );
}
