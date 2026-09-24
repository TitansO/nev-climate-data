import { useMemo, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import RequireAuth from "../components/RequireAuth";
import useAnalyticsFetch from "../hooks/useAnalyticsFetch";
import NevSelect from "../components/ui/NevSelect";
import NevCard from "../components/ui/NevCard";
import NevScoreGauge from "../components/ui/NevScoreGauge";
import { COUNTRY_GROUPS } from "../data/africaCountries";
import { GlobeIcon } from "../components/ui/icons";

function isoFromUrl() {
  const params = new URLSearchParams(window.location.search);
  const raw = (params.get("iso") || "").toUpperCase();
  return /^[A-Z]{3}$/.test(raw) ? raw : "";
}

/**
 * C2.6 + C2.8 + C3.2 (volet pays) : fiche pays prévue par le plan initial
 * ("encart sur la fiche pays / carte") mais jamais construite lors des
 * premiers passages sur C2/C3 - alignement CDN (C2.6), risque
 * investissement (C2.8) et part locale estimée (C3.2). Réservé à
 * ROLE_USER depuis la structuration des accès (2026-09-24) -
 * /api/countries/* était initialement PUBLIC_ACCESS. Route MPA autonome
 * (country-profile.html?iso=...), même famille que
 * institution-profile.html/project-pipeline.html.
 */
export default function CountryProfilePage() {
  const { t } = useI18n();
  const { isAuthenticated } = useAuth();
  const [iso, setIso] = useState(isoFromUrl());

  const countryName = useMemo(() => {
    for (const group of COUNTRY_GROUPS) {
      const match = group.options.find((option) => option.value === iso);
      if (match) {
        return match.label;
      }
    }
    return "";
  }, [iso]);

  const ndcAlignment = useAnalyticsFetch(iso ? "/api/countries/" + iso + "/scores/ndc-alignment" : "", { emptyWhen: (body) => false === body.available });
  const investmentRisk = useAnalyticsFetch(iso ? "/api/countries/" + iso + "/scores/investment-risk" : "");
  // C3.2 : "indicateur de part locale estimée, avec avertissement méthodo"
  // (plan original) - jamais construit lors du premier passage sur C3 ; le
  // backend (App\Service\LocalFinanceService) existait déjà sans aucune UI.
  const localFinance = useAnalyticsFetch(iso ? "/api/analytics/local-finance?country=" + iso : "", { emptyWhen: (body) => 0 === body.data.length });

  function selectIso(nextIso) {
    setIso(nextIso);
    const url = new URL(window.location.href);
    if (nextIso) {
      url.searchParams.set("iso", nextIso);
    } else {
      url.searchParams.delete("iso");
    }
    window.history.replaceState(null, "", url);
  }

  if (!isAuthenticated()) {
    return <RequireAuth />;
  }

  return (
    <div className="container mx-auto px-4 py-10">
      <div className="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
          <span className="mb-2 inline-flex items-center gap-1.5 rounded-full bg-surface-alt px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-primary-dark">
            <GlobeIcon className="h-3 w-3" /> {t("countryPage.pill", "Fiche pays")}
          </span>
          <h1 className="text-2xl font-bold text-dark">{countryName || t("countryPage.pickCountry", "Choisissez un pays")}</h1>
          {iso && <p className="mt-1 text-sm text-body-color">{t("countryPage.subtitle", "Alignement CDN, risque d'investissement et part locale estimée - calculés à partir de données ouvertes, jamais un modèle entraîné.")}</p>}
        </div>
        <NevSelect id="country-picker" label={t("countryPage.selectLabel", "Pays")} value={iso} onChange={(event) => selectIso(event.target.value)} wrapperClassName="min-w-[220px]">
          <option value="">{t("countryPage.select", "Sélectionner…")}</option>
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
      </div>

      {!iso && (
        <NevCard padding="lg" className="text-center text-sm text-body-color">
          {t("countryPage.empty", "Sélectionnez un pays ci-dessus pour afficher sa fiche.")}
        </NevCard>
      )}

      {iso && (
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
          <NevScoreGauge
            label={t("countryPage.ndcAlignment", "Alignement CDN")}
            score={ndcAlignment.data && ndcAlignment.data.available ? ndcAlignment.data : null}
            state={"loading" === ndcAlignment.state ? "loading" : "error" === ndcAlignment.state ? "error" : "success"}
            unavailableText={t("countryPage.ndcUnavailable", "Ce pays n'a pas de Contribution Déterminée au niveau National (CDN) chiffrée dans le jeu de données actuel.")}
            errorText={t("countryPage.scoreError", "Impossible de calculer ce score.")}
          />
          <NevScoreGauge
            label={t("countryPage.investmentRisk", "Risque d'investissement")}
            score={investmentRisk.data}
            state={investmentRisk.state}
            errorText={t("countryPage.scoreError", "Impossible de calculer ce score.")}
          />

          <NevCard padding="md" className="sm:col-span-2">
            <p className="text-xs font-semibold uppercase tracking-wide text-body-color">{t("countryPage.localFinance", "Part locale estimée")}</p>
            {("loading" === localFinance.state || "empty" === localFinance.state) && (
              <p className="mt-3 text-sm text-body-color">
                {"loading" === localFinance.state
                  ? t("hero.loading", "Chargement…")
                  : t("countryPage.localFinanceEmpty", "Aucun financement validé pour ce pays dans le jeu de données actuel.")}
              </p>
            )}
            {"error" === localFinance.state && <p className="mt-3 text-sm text-danger">{t("countryPage.scoreError", "Impossible de calculer ce score.")}</p>}
            {"success" === localFinance.state && localFinance.data && localFinance.data.data[0] && (
              <>
                <p className="mt-2 text-2xl font-extrabold tabular-nums text-dark">
                  {localFinance.data.data[0].localSharePercent}
                  <span className="text-sm font-semibold text-body-color"> %</span>
                </p>
                <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-2">
                  <div className="h-full rounded-full bg-primary" style={{ width: Math.min(100, localFinance.data.data[0].localSharePercent) + "%" }} />
                </div>
                <p className="mt-3 text-xs text-body-color">{localFinance.data.methodology}</p>
              </>
            )}
          </NevCard>
        </div>
      )}
    </div>
  );
}
