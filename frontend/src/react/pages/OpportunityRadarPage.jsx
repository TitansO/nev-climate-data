import { useEffect, useMemo, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";
import NevBadge from "../components/ui/NevBadge";
import NevInput from "../components/ui/NevInput";
import NevSelect from "../components/ui/NevSelect";
import NevDataState from "../components/ui/NevDataState";
import { formatUsd } from "../data/analyticsConstants";
import { SparklesIcon } from "../components/ui/icons";

const STATUS_LABELS = { open: "Ouverte", upcoming: "À venir", closed: "Fermée" };
const STATUS_TONES = { open: "success", upcoming: "info", closed: "neutral" };

function formatDate(isoDate) {
  if (!isoDate) {
    return null;
  }
  const date = new Date(isoDate + "T00:00:00");
  return Number.isNaN(date.getTime()) ? isoDate : date.toLocaleDateString("fr-FR", { year: "numeric", month: "short", day: "2-digit" });
}

function stateFromUrl() {
  const params = new URLSearchParams(window.location.search);
  return { country: params.get("country") || "", status: params.get("status") || "" };
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
  const { API_BASE_URL } = useAuth();
  const { t } = useI18n();
  const initial = stateFromUrl();

  const [country, setCountry] = useState(initial.country);
  const [status, setStatus] = useState(initial.status);
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
    return params.toString();
  }, [country, status]);

  useEffect(() => {
    let cancelled = false;
    setState("loading");
    window.history.replaceState(null, "", window.location.pathname + (queryString ? "?" + queryString : ""));

    (async () => {
      let response;
      try {
        response = await fetch(API_BASE_URL + "/api/opportunities" + (queryString ? "?" + queryString : ""), { headers: { Accept: "application/json" } });
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
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                <NevCard key={opportunity.id} padding="lg" interactive>
                  <div className="mb-3 flex items-start justify-between gap-3">
                    <h3 className="text-lg font-bold text-dark">{opportunity.title}</h3>
                    <NevBadge tone={STATUS_TONES[opportunity.status]}>{STATUS_LABELS[opportunity.status]}</NevBadge>
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
                  </dl>
                </NevCard>
              ))}
            </div>
          </NevDataState>
        </section>
      </div>
    </>
  );
}
