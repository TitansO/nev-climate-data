import { useEffect, useMemo, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import RequireAuth from "../components/RequireAuth";
import NevCard from "../components/ui/NevCard";
import NevSelect from "../components/ui/NevSelect";
import NevInput from "../components/ui/NevInput";
import NevButton from "../components/ui/NevButton";
import { COUNTRY_GROUPS } from "../data/africaCountries";
import { formatCount } from "../data/analyticsConstants";
import { ChartBarIcon } from "../components/ui/icons";

const METRIC_LABELS = {
  co2AvoidedTonsPerYear: "tCO2 évitées / an",
  mwInstalled: "MW installés",
  beneficiaries: "Bénéficiaires touchés",
};

function paramsFromUrl() {
  const params = new URLSearchParams(window.location.search);
  return { sector: params.get("sector") || "", amount: params.get("amount") || "", country: (params.get("country") || "").toUpperCase() };
}

/**
 * C2.5 : "Impact Calculator" - formulaire + résultat avec fourchette, prévu
 * par le plan initial sur une page dédiée (+ un encart projet, voir le
 * lien "Estimer l'impact" de ProjectDetailModal qui pré-remplit ce
 * formulaire via les paramètres d'URL sector/amount/country) mais jamais
 * construit lors du premier passage sur C2.
 */
export default function ImpactCalculatorPage() {
  const { API_BASE_URL, isAuthenticated } = useAuth();
  const { t } = useI18n();
  const prefill = useMemo(() => paramsFromUrl(), []);

  const [sectors, setSectors] = useState([]);
  const [sectorsState, setSectorsState] = useState("loading");
  const [sectorId, setSectorId] = useState("");
  const [amountUsd, setAmountUsd] = useState(prefill.amount);
  const [country, setCountry] = useState(prefill.country);
  const [result, setResult] = useState(null);
  const [status, setStatus] = useState("idle");
  const [errorMessage, setErrorMessage] = useState("");

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const response = await fetch(API_BASE_URL + "/api/sectors", { headers: { Accept: "application/json" } });
        if (!response.ok) {
          throw new Error("http " + response.status);
        }
        const body = await response.json();
        if (cancelled) {
          return;
        }
        setSectors(body.data);
        setSectorsState("success");
        if (prefill.sector) {
          const match = body.data.find((sector) => sector.name === prefill.sector);
          if (match) {
            setSectorId(String(match.id));
          }
        }
      } catch (error) {
        if (!cancelled) {
          setSectorsState("error");
        }
      }
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [API_BASE_URL]);

  async function submit(event) {
    event.preventDefault();
    setStatus("loading");
    setErrorMessage("");
    setResult(null);
    try {
      const response = await fetch(API_BASE_URL + "/api/impact/estimate", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ sector: Number(sectorId), amountUsd: Number(amountUsd), country: country || undefined }),
      });
      if (!response.ok) {
        const body = await response.json().catch(() => null);
        throw new Error(body?.message || "http " + response.status);
      }
      const body = await response.json();
      setResult(body);
      setStatus("success");
    } catch (error) {
      setErrorMessage(t("impactCalculator.error", "Impossible de calculer cette estimation."));
      setStatus("error");
    }
  }

  if (!isAuthenticated()) {
    return <RequireAuth />;
  }

  return (
    <div className="container mx-auto px-4 py-10">
      <div className="mb-8">
        <span className="mb-2 inline-flex items-center gap-1.5 rounded-full bg-surface-alt px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-primary-dark">
          <ChartBarIcon className="h-3 w-3" /> {t("impactCalculator.pill", "Calculateur d'impact")}
        </span>
        <h1 className="text-2xl font-bold text-dark">{t("impactCalculator.title", "Calculateur d'impact")}</h1>
        <p className="mt-1 max-w-2xl text-sm text-body-color">
          {t(
            "impactCalculator.subtitle",
            "Estime l'impact d'un montant investi dans un secteur, à partir de facteurs statiques et documentés (IRENA, GCF) - jamais un modèle entraîné, toujours une fourchette basse/haute plutôt qu'un chiffre présenté comme certain."
          )}
        </p>
        {/* C3.4 : l'autre outil de simulation financière de la plateforme -
            même famille que ce calculateur, juste avant l'investissement
            plutôt qu'après. */}
        <a href="blended-finance.html" className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-primary-dark hover:underline">
          {t("impactCalculator.seeBlendedFinance", "Simuler un montage de finance mixte →")}
        </a>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[380px_1fr]">
        <NevCard padding="lg" as="form" onSubmit={submit}>
          <div className="space-y-4">
            <NevSelect
              id="impact-sector"
              label={t("impactCalculator.sector", "Secteur")}
              required
              value={sectorId}
              onChange={(event) => setSectorId(event.target.value)}
              disabled={"success" !== sectorsState}
            >
              <option value="">{"loading" === sectorsState ? t("hero.loading", "Chargement…") : t("impactCalculator.selectSector", "Sélectionner un secteur…")}</option>
              {sectors.map((sector) => (
                <option key={sector.id} value={sector.id}>
                  {sector.name}
                </option>
              ))}
            </NevSelect>

            <NevInput
              id="impact-amount"
              type="number"
              min="1"
              step="any"
              required
              label={t("impactCalculator.amount", "Montant (USD)")}
              value={amountUsd}
              onChange={(event) => setAmountUsd(event.target.value)}
            />

            <NevSelect id="impact-country" label={t("impactCalculator.country", "Pays (optionnel)")} value={country} onChange={(event) => setCountry(event.target.value)} hint={t("impactCalculator.countryHint", "Accepté mais sans effet sur le calcul pour l'instant : aucun facteur d'ajustement par pays n'existe encore.")}>
              <option value="">{t("impactCalculator.noCountry", "Aucun")}</option>
              {COUNTRY_GROUPS.map((group) => (
                <optgroup key={group.label} label={group.label}>
                  {group.options.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </optgroup>
              ))}
            </NevSelect>

            <NevButton type="submit" disabled={!sectorId || !amountUsd || "loading" === status} className="w-full">
              {"loading" === status ? t("impactCalculator.computing", "Calcul…") : t("impactCalculator.submit", "Estimer l'impact")}
            </NevButton>
            {errorMessage && <p className="text-xs text-danger">{errorMessage}</p>}
          </div>
        </NevCard>

        <div>
          {!result && "idle" === status && (
            <NevCard padding="lg" className="flex h-full items-center justify-center text-center text-sm text-body-color">
              {t("impactCalculator.empty", "Renseignez le formulaire pour obtenir une estimation.")}
            </NevCard>
          )}

          {result && !result.available && (
            <NevCard padding="lg" className="text-center text-sm text-body-color">
              {t("impactCalculator.unavailable", "Aucun facteur d'impact documenté pour ce secteur pour l'instant.")}
            </NevCard>
          )}

          {result && result.available && (
            <NevCard padding="lg">
              <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-body-color">
                {result.sector} · {formatCount(result.amountUsd)} USD
              </p>
              <p className="mb-5 text-xs text-body-color">
                {t("impactCalculator.uncertainty", "Marge d'incertitude : ± {n} %").replace("{n}", result.uncertaintyPercent)}
                {result.methodologySource && " · " + result.methodologySource}
              </p>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {Object.entries(result.estimates).map(([key, estimate]) => (
                  <div key={key} className="rounded-lg border border-stroke p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-body-color">{METRIC_LABELS[key] || key}</p>
                    <p className="mt-1 text-2xl font-extrabold tabular-nums text-dark">{formatCount(estimate.central)}</p>
                    <p className="mt-1 text-xs text-body-color">
                      {t("impactCalculator.range", "entre {low} et {high}").replace("{low}", formatCount(estimate.low)).replace("{high}", formatCount(estimate.high))}
                    </p>
                  </div>
                ))}
              </div>
            </NevCard>
          )}
        </div>
      </div>
    </div>
  );
}
