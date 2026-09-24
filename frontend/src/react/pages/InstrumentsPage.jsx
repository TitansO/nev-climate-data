import { useEffect, useMemo, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import RequireAuth from "../components/RequireAuth";
import NevChartCard from "../components/ui/NevChartCard";
import NevTable from "../components/ui/NevTable";
import NevDataState from "../components/ui/NevDataState";
import { INSTRUMENT_LABELS, INSTRUMENT_COLORS, formatUsd } from "../data/analyticsConstants";
import { BanknoteIcon } from "../components/ui/icons";

const DATA_SOURCE_LABEL = "Source : API NEV Climate Data (GET /api/analytics/instrument-distribution)";
const DATA_PERIOD_LABEL = "Mise à jour toutes les 15 minutes";
const CROSS_TAB_LIMIT = 12;

function instrumentLabel(value) {
  return INSTRUMENT_LABELS[value] || value;
}

/**
 * C1.7 : répartition des financements par instrument financier ("part de
 * concessionnalité") + les deux croisements que GET /api/analytics/
 * instrument-distribution expose (instrument x secteur, instrument x
 * pays). Les croisements sont déjà triés par montant décroissant côté API
 * - cette page en affiche les CROSS_TAB_LIMIT premières lignes de chacun
 * (une vraie table de contribution la plus significative, pas une matrice
 * complète qui deviendrait vite illisible avec 7 instruments x 5 secteurs
 * x 54 pays).
 */
export default function InstrumentsPage() {
  const { API_BASE_URL, isAuthenticated } = useAuth();
  const { t } = useI18n();
  const [state, setState] = useState("loading");
  const [profile, setProfile] = useState(null);
  const [message, setMessage] = useState("");

  useEffect(() => {
    let cancelled = false;
    (async () => {
      let response;
      try {
        response = await fetch(API_BASE_URL + "/api/analytics/instrument-distribution", { headers: { Accept: "application/json" } });
      } catch (networkError) {
        if (!cancelled) {
          setState("error");
          setMessage(t("instrumentsPage.networkError", "Impossible de contacter le serveur."));
        }
        return;
      }
      if (cancelled) {
        return;
      }
      if (!response.ok) {
        setState("error");
        setMessage(t("instrumentsPage.genericError", "Une erreur est survenue."));
        return;
      }
      const body = await response.json();
      if (cancelled) {
        return;
      }
      setProfile(body);
      setState(0 === body.data.length ? "empty" : "success");
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const topBySector = useMemo(() => (profile ? profile.bySector.slice(0, CROSS_TAB_LIMIT) : []), [profile]);
  const topByCountry = useMemo(() => (profile ? profile.byCountry.slice(0, CROSS_TAB_LIMIT) : []), [profile]);

  if (!isAuthenticated()) {
    return <RequireAuth />;
  }

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-[60px] pt-[48px] text-left text-white">
        <div className="container mx-auto px-4">
          <span className="mb-[18px] inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-accent">
            <BanknoteIcon className="h-3 w-3" /> {t("instrumentsPage.pill", "Instruments financiers")}
          </span>
          <h1 className="mb-[14px] text-[clamp(1.7rem,4vw,2.6rem)] font-bold leading-[1.2]">{t("instrumentsPage.title", "Instruments de financement")}</h1>
          <p className="max-w-[560px] text-base text-white/80">
            {t("instrumentsPage.subtitle", "La part de dons, prêts, garanties et autres instruments dans le financement climatique - et qui en bénéficie.")}
          </p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <section className="relative z-20 -mt-10 mb-8">
          <NevDataState state={state} loadingText={t("instrumentsPage.loading", "Chargement…")} errorText={message} emptyText={t("instrumentsPage.empty", "Aucune donnée disponible.")}>
            {profile && (
              <NevChartCard title={t("instrumentsPage.distribution", "Répartition par instrument")} sourceLabel={DATA_SOURCE_LABEL} periodLabel={DATA_PERIOD_LABEL}>
                <div className="space-y-3">
                  {profile.data.map((row) => (
                    <div key={row.instrument}>
                      <div className="mb-1 flex items-center justify-between text-sm">
                        <span className="flex items-center gap-2 font-medium text-dark">
                          <span className="h-2 w-2 rounded-full" style={{ backgroundColor: INSTRUMENT_COLORS[row.instrument] || "#9ca3af" }}></span>
                          {instrumentLabel(row.instrument)}
                        </span>
                        <span className="font-semibold text-dark-4">
                          {formatUsd(row.amount)} · {row.percentage}%
                        </span>
                      </div>
                      <div className="h-1.5 w-full overflow-hidden rounded-full bg-surface-alt">
                        <div
                          className="h-full rounded-full"
                          style={{ width: row.percentage + "%", backgroundColor: INSTRUMENT_COLORS[row.instrument] || "#9ca3af" }}
                        ></div>
                      </div>
                    </div>
                  ))}
                </div>
              </NevChartCard>
            )}
          </NevDataState>
        </section>

        {profile && topBySector.length > 0 && (
          <section className="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <NevChartCard title={t("instrumentsPage.bySector", "Principaux croisements instrument × secteur")} sourceLabel={DATA_SOURCE_LABEL}>
              <NevTable
                bordered={false}
                rowKey={(row) => row.instrument + "-" + row.sector}
                columns={[
                  { key: "instrument", label: t("instrumentsPage.instrument", "Instrument"), render: (row) => instrumentLabel(row.instrument) },
                  { key: "sector", label: t("instrumentsPage.sector", "Secteur") },
                  { key: "amount", label: t("instrumentsPage.amount", "Montant"), align: "right", render: (row) => formatUsd(row.amount) },
                ]}
                rows={topBySector}
              />
            </NevChartCard>
            <NevChartCard title={t("instrumentsPage.byCountry", "Principaux croisements instrument × pays")} sourceLabel={DATA_SOURCE_LABEL}>
              <NevTable
                bordered={false}
                rowKey={(row) => row.instrument + "-" + row.isoCode}
                columns={[
                  { key: "instrument", label: t("instrumentsPage.instrument", "Instrument"), render: (row) => instrumentLabel(row.instrument) },
                  { key: "country", label: t("instrumentsPage.country", "Pays") },
                  { key: "amount", label: t("instrumentsPage.amount", "Montant"), align: "right", render: (row) => formatUsd(row.amount) },
                ]}
                rows={topByCountry}
              />
            </NevChartCard>
          </section>
        )}

        <p className="pb-16 text-xs text-dark-5">
          {t(
            "instrumentsPage.disclaimer",
            "Répartition et croisements : données réelles (GET /api/analytics/instrument-distribution, C1.7), mises en cache 15 minutes. Les croisements affichent les 12 combinaisons les plus significatives de chaque axe."
          )}
        </p>
      </div>
    </>
  );
}
