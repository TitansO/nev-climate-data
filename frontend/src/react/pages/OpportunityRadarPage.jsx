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
import { SparklesIcon, DocumentIcon, MailIcon, LinkIcon } from "../components/ui/icons";

const STATUS_LABELS = { open: "Ouverte", upcoming: "À venir", closed: "Fermée" };
const STATUS_TONES = { open: "success", upcoming: "info", closed: "neutral" };

// A3.12 : miroir de App\Entity\Enum\FundingInstrument côté backend (mêmes
// valeurs string) - un seul endroit à mettre à jour si un cas est ajouté
// côté PHP, cf. le même motif pour FUNDING_TYPE_LABELS (DataPage.jsx).
const INSTRUMENT_LABELS = {
  grant: "Subvention",
  loan_concessional: "Prêt concessionnel",
  loan_non_concessional: "Prêt non concessionnel",
  guarantee: "Garantie",
  equity: "Prise de participation",
  results_based: "Financement basé sur les résultats",
  unknown: "Non précisé",
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
  return { country: params.get("country") || "", status: params.get("status") || "", instrument: params.get("instrument") || "" };
}

/**
 * C1.8 : "Opportunity Radar" - GET /api/opportunities. Filtre par pays
 * (recherche texte dans le texte libre d'éligibilité - voir
 * App\Dto\OpportunityCriteria côté backend pour le "pourquoi" : chaque
 * appel à projets réel est régional/multi-pays, il n'existe pas de pays
 * éligible unique à choisir dans un menu) et par statut (ouverte/à venir/
 * fermée, calculé côté serveur par rapport à aujourd'hui). "Secteur"
 * n'est délibérément pas un filtre ici, même raison que C1.2/C1.3 : aucun
 * endpoint n'expose encore Sector.id à côté de son nom pour alimenter un
 * menu (le filtre backend, lui, est prêt : GET /api/opportunities?sector=).
 */
export default function OpportunityRadarPage() {
  const { API_BASE_URL, isAuthenticated, authorizedFetch } = useAuth();
  const { t } = useI18n();
  const initial = stateFromUrl();

  const [country, setCountry] = useState(initial.country);
  const [status, setStatus] = useState(initial.status);
  const [instrument, setInstrument] = useState(initial.instrument);
  const [state, setState] = useState("loading");
  const [opportunities, setOpportunities] = useState([]);
  const [message, setMessage] = useState("");

  const queryString = useMemo(() => {
    const params = new URLSearchParams();
    if (country.trim()) {
      params.set("country", country.trim());
    }
    if (status) {
      params.set("status", status);
    }
    if (instrument) {
      params.set("instrument", instrument);
    }
    return params.toString();
  }, [country, status, instrument]);

  useEffect(() => {
    let cancelled = false;
    setState("loading");
    window.history.replaceState(null, "", window.location.pathname + (queryString ? "?" + queryString : ""));

    (async () => {
      let response;
      try {
        response = await authorizedFetch(API_BASE_URL + "/api/opportunities" + (queryString ? "?" + queryString : ""), { headers: { Accept: "application/json" } });
      } catch (networkError) {
        if (!cancelled) {
          setState("error");
          setMessage(t("opportunityPage.networkError", "Impossible de contacter le serveur."));
        }
        return;
      }
      if (cancelled) {
        return;
      }
      if (!response.ok) {
        setState("error");
        setMessage(t("opportunityPage.genericError", "Une erreur est survenue."));
        return;
      }
      const body = await response.json();
      if (cancelled) {
        return;
      }
      setOpportunities(body.data);
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
            <SparklesIcon className="h-3 w-3" /> {t("opportunityPage.pill", "Radar des opportunités")}
          </span>
          <h1 className="mb-[14px] text-[clamp(1.7rem,4vw,2.6rem)] font-bold leading-[1.2]">{t("opportunityPage.title", "Appels à projets ouverts")}</h1>
          <p className="max-w-[560px] text-base text-white/80">
            {t("opportunityPage.subtitle", "Les fenêtres de financement ouvertes par les bailleurs, saisies manuellement (F4) - triées par échéance la plus proche.")}
          </p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <NevCard padding="md" className="relative z-20 -mt-10 mb-8">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <NevInput
              id="opportunity-country"
              label={t("opportunityPage.country", "Recherche dans le texte d'éligibilité")}
              placeholder={t("opportunityPage.countryPlaceholder", "Ex : Senegal, Sahel, IDA...")}
              value={country}
              onChange={(event) => setCountry(event.target.value)}
            />
            <NevSelect id="opportunity-status" label={t("opportunityPage.status", "Statut")} value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="">{t("opportunityPage.allStatuses", "Tous les statuts")}</option>
              <option value="open">{t("opportunityPage.statusOpen", "Ouverte")}</option>
              <option value="upcoming">{t("opportunityPage.statusUpcoming", "À venir")}</option>
              <option value="closed">{t("opportunityPage.statusClosed", "Fermée")}</option>
            </NevSelect>
            <NevSelect id="opportunity-instrument" label={t("opportunityPage.instrument", "Type d'instrument")} value={instrument} onChange={(event) => setInstrument(event.target.value)}>
              <option value="">{t("opportunityPage.allInstruments", "Tous les instruments")}</option>
              {Object.entries(INSTRUMENT_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </NevSelect>
          </div>
          {/* C2.2/C2.3: cette page liste les appels à projets des bailleurs
              (le "quoi chercher") ; le pipeline de projets, lui, suit les
              projets qui candidatent à ces mêmes appels, avec leur score de
              correspondance (le "où en est ma candidature") - lien
              contextuel plutôt qu'une entrée de navbar, même raisonnement
              que instruments.html/opportunities.html en C1.7/C1.8. */}
          <a href="project-pipeline.html" className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-primary-dark hover:underline">
            {t("opportunityPage.seeProjectPipeline", "Suivre mes projets et leurs correspondances →")}
          </a>
        </NevCard>

        <section className="pb-16">
          <NevDataState
            state={state}
            loadingText={t("opportunityPage.loading", "Chargement des appels à projets…")}
            errorText={message}
            emptyText={t("opportunityPage.empty", "Aucun appel à projets ne correspond à ces critères.")}
          >
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              {opportunities.map((opportunity) => (
                <NevCard key={opportunity.id} padding="lg" interactive className="flex flex-col">
                  <div className="mb-3 flex items-start justify-between gap-3">
                    <h3 className="text-lg font-bold text-dark">{opportunity.title}</h3>
                    <div className="flex shrink-0 flex-col items-end gap-1.5">
                      <NevBadge tone={STATUS_TONES[opportunity.status]}>{STATUS_LABELS[opportunity.status]}</NevBadge>
                      {opportunity.instrument && <NevBadge tone="neutral">{INSTRUMENT_LABELS[opportunity.instrument] || opportunity.instrument}</NevBadge>}
                    </div>
                  </div>
                  <p className="mb-4 text-sm text-body-color">{opportunity.eligibilityCriteria}</p>
                  <dl className="space-y-2 border-t border-stroke pt-4 text-sm">
                    <div className="flex justify-between">
                      <dt className="text-dark-5">{t("opportunityPage.source", "Bailleur")}</dt>
                      <dd className="font-semibold text-dark-3">{opportunity.sourceName}</dd>
                    </div>
                    {opportunity.sector && (
                      <div className="flex justify-between">
                        <dt className="text-dark-5">{t("opportunityPage.sector", "Secteur")}</dt>
                        <dd className="font-semibold text-dark-3">{opportunity.sector}</dd>
                      </div>
                    )}
                    <div className="flex justify-between">
                      <dt className="text-dark-5">{t("opportunityPage.envelope", "Enveloppe")}</dt>
                      <dd className="font-semibold text-dark-3">{formatUsd(opportunity.envelopeAmount)}</dd>
                    </div>
                    {(opportunity.applicationWindowOpensAt || opportunity.applicationWindowClosesAt) && (
                      <div className="flex justify-between">
                        <dt className="text-dark-5">{t("opportunityPage.window", "Fenêtre")}</dt>
                        <dd className="font-semibold text-dark-3">
                          {formatDate(opportunity.applicationWindowOpensAt) || "?"} → {formatDate(opportunity.applicationWindowClosesAt) || "?"}
                        </dd>
                      </div>
                    )}
                    {opportunity.contactEmail && (
                      <div className="flex justify-between">
                        <dt className="text-dark-5">{t("opportunityPage.contact", "Contact")}</dt>
                        <dd>
                          <a href={"mailto:" + opportunity.contactEmail} className="inline-flex items-center gap-1 font-semibold text-primary-dark hover:underline">
                            <MailIcon className="h-3.5 w-3.5" /> {opportunity.contactEmail}
                          </a>
                        </dd>
                      </div>
                    )}
                  </dl>
                  {opportunity.requiredDocuments && (
                    <div className="mt-4 flex gap-2 rounded-md bg-surface-alt px-3 py-2.5 text-xs text-dark-4">
                      <DocumentIcon className="mt-0.5 h-4 w-4 shrink-0 text-dark-5" />
                      <p>
                        <span className="font-semibold text-dark-3">{t("opportunityPage.requiredDocuments", "Documents requis")} : </span>
                        {opportunity.requiredDocuments}
                      </p>
                    </div>
                  )}
                  {opportunity.applicationUrl && (
                    <a
                      href={opportunity.applicationUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="mt-4 inline-flex items-center justify-center gap-1.5 self-start rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark"
                    >
                      {t("opportunityPage.apply", "Candidater sur le site du bailleur")} <LinkIcon className="h-3.5 w-3.5" />
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
