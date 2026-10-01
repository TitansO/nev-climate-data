import { useMemo, useState } from "react";
import useAnalyticsFetch from "../../hooks/useAnalyticsFetch";
import ChartStateBox from "./ChartStateBox";
import CountryMap from "./CountryMap";
import { TYPE_LABELS, INSTRUMENT_LABELS } from "../../data/analyticsConstants";

const BASE_PATH = "/api/analytics/country-distribution";
// Audit post-C3 2026-10-01 : volontairement un <select> brut plutot que le
// NevSelect partage - NevSelect force `w-full` (non surchargeable, sa
// classe de base est concatenee avec celle fournie, jamais remplacee) et
// une rangee de label visible au-dessus du champ, pensés pour un champ de
// formulaire pleine largeur. Cette barre de filtres est une rangee
// horizontale compacte (3 selects + un bouton, flex-wrap, aria-label seul
// sans label visible) - y forcer NevSelect casserait la mise en page sans
// benefice reel, pour une simple coherence de composant.
const SELECT_CLASS =
  "rounded-md border border-stroke bg-white px-2.5 py-1.5 text-xs font-medium text-dark-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary";

/**
 * C1.2: "layer selector" for the country map - narrows the per-country
 * funding breakdown by financing type / instrument / year, using
 * GET /api/analytics/country-distribution's own C1.2 filters. Shared by
 * DashboardPage (used a plain CountryMap before C1.2; 2026-09-28: also
 * absorbed VisualizationsPage's own copy of this same map when the two
 * pages were merged, since both showed it identically).
 *
 * Sector is deliberately left out of this UI: unlike fundingType/instrument
 * (a handful of known enum values, see analyticsConstants.js) and year
 * (passed in via `availableYears`, already fetched by the caller for its
 * own financing-trends chart), no endpoint currently exposes Sector.id
 * alongside its name for a dropdown to use. The backend filter exists
 * (C1.2, FundingRepository::findCountryDistributionAggregate()) and is
 * ready for a future sector-aware UI once one does.
 */
export default function FilterableCountryMap({ availableYears }) {
  const [fundingType, setFundingType] = useState("");
  const [instrument, setInstrument] = useState("");
  const [year, setYear] = useState("");

  const path = useMemo(() => {
    const params = new URLSearchParams();
    if (fundingType) {
      params.set("fundingType", fundingType);
    }
    if (instrument) {
      params.set("instrument", instrument);
    }
    if (year) {
      params.set("year", year);
    }
    const qs = params.toString();
    return BASE_PATH + (qs ? "?" + qs : "");
  }, [fundingType, instrument, year]);

  const countries = useAnalyticsFetch(path, { emptyWhen: (body) => 0 === body.data.length });

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <span className="text-xs font-semibold uppercase tracking-wide text-body-color">Filtrer :</span>
        <select className={SELECT_CLASS} value={fundingType} onChange={(event) => setFundingType(event.target.value)} aria-label="Filtrer par type de financement">
          <option value="">Tous les types</option>
          {Object.entries(TYPE_LABELS).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
        <select className={SELECT_CLASS} value={instrument} onChange={(event) => setInstrument(event.target.value)} aria-label="Filtrer par instrument financier">
          <option value="">Tous les instruments</option>
          {Object.entries(INSTRUMENT_LABELS).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
        {availableYears && availableYears.length > 0 && (
          <select className={SELECT_CLASS} value={year} onChange={(event) => setYear(event.target.value)} aria-label="Filtrer par année">
            <option value="">Toutes les années</option>
            {availableYears.map((y) => (
              <option key={y} value={y}>
                {y}
              </option>
            ))}
          </select>
        )}
        {(fundingType || instrument || year) && (
          <button
            type="button"
            className="rounded-md px-2 py-1 text-xs font-semibold text-primary-dark underline-offset-2 hover:underline"
            onClick={() => {
              setFundingType("");
              setInstrument("");
              setYear("");
            }}
          >
            Réinitialiser
          </button>
        )}
      </div>
      <ChartStateBox state={countries.state} message={countries.message} onRetry={countries.reload} minHeight={false}>
        {countries.data && <CountryMap rows={countries.data.data} />}
      </ChartStateBox>
    </div>
  );
}
