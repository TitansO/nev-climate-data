import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import RequireAuth from "../components/RequireAuth";
import NevCard from "../components/ui/NevCard";
import NevSelect from "../components/ui/NevSelect";
import { formatCount } from "../data/analyticsConstants";
import { ChartBarIcon } from "../components/ui/icons";

const WATERFALL_COLORS = { "Financement public": "#0F7A37", "Capital privé mobilisé": "#16A34A", "Total mobilisé": "#10231A" };

/**
 * C3.4 : "Blended Finance Simulator" - curseur de montant public +
 * curseur du mélange d'instruments (don ↔ garantie, les deux extrêmes du
 * multiplicateur de levier de App\Service\BlendedFinanceService), appelle
 * POST /api/blended/simulate à chaque changement. Le graphe en cascade
 * (waterfall) est une visualisation maison en barres empilées - aucune
 * bibliothèque de graphique de ce projet n'a de type "waterfall" natif.
 */
export default function BlendedFinancePage() {
  const { API_BASE_URL, isAuthenticated } = useAuth();
  const { t } = useI18n();

  const [sectors, setSectors] = useState([]);
  const [sectorId, setSectorId] = useState("");
  const [publicAmount, setPublicAmount] = useState(5_000_000);
  const [guaranteeShare, setGuaranteeShare] = useState(50);
  const [result, setResult] = useState(null);
  const [status, setStatus] = useState("loading");

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const response = await fetch(API_BASE_URL + "/api/sectors", { headers: { Accept: "application/json" } });
        if (!response.ok) {
          throw new Error("http " + response.status);
        }
        const body = await response.json();
        if (!cancelled) {
          setSectors(body.data);
          if (body.data.length > 0) {
            setSectorId(String(body.data[0].id));
          }
          setStatus("idle");
        }
      } catch (error) {
        if (!cancelled) {
          setStatus("error");
        }
      }
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [API_BASE_URL]);

  useEffect(() => {
    if (!sectorId) {
      return undefined;
    }
    let cancelled = false;
    (async () => {
      try {
        const response = await fetch(API_BASE_URL + "/api/blended/simulate", {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({
            sector: Number(sectorId),
            publicAmount: Number(publicAmount) || 0,
            instrumentMix: { guarantee: guaranteeShare / 100, grant: 1 - guaranteeShare / 100 },
          }),
        });
        if (!response.ok) {
          throw new Error("http " + response.status);
        }
        const body = await response.json();
        if (!cancelled) {
          setResult(body);
        }
      } catch (error) {
        if (!cancelled) {
          setResult(null);
        }
      }
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [API_BASE_URL, sectorId, publicAmount, guaranteeShare]);

  const maxWaterfallAmount = result ? Math.max(...result.waterfall.map((step) => step.amountUsd)) : 0;

  if (!isAuthenticated()) {
    return <RequireAuth />;
  }

  return (
    <div className="container mx-auto px-4 py-10">
      <div className="mb-8">
        <span className="mb-2 inline-flex items-center gap-1.5 rounded-full bg-surface-alt px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-primary-dark">
          <ChartBarIcon className="h-3 w-3" /> {t("blendedFinance.pill", "Finance mixte")}
        </span>
        <h1 className="text-2xl font-bold text-dark">{t("blendedFinance.title", "Simulateur de finance mixte")}</h1>
        <p className="mt-1 max-w-2xl text-sm text-body-color">
          {t(
            "blendedFinance.subtitle",
            "Simule l'effet de levier d'un montage public/privé : combien de capital privé un montant public pourrait mobiliser, selon le secteur et les instruments utilisés."
          )}
        </p>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[380px_1fr]">
        <NevCard padding="lg">
          <div className="space-y-6">
            <NevSelect id="blended-sector" label={t("blendedFinance.sector", "Secteur")} value={sectorId} onChange={(event) => setSectorId(event.target.value)} disabled={"idle" !== status && "success" !== status}>
              {sectors.map((sector) => (
                <option key={sector.id} value={sector.id}>
                  {sector.name}
                </option>
              ))}
            </NevSelect>

            <div>
              <label htmlFor="blended-amount" className="mb-1.5 flex items-center justify-between text-sm font-medium text-dark">
                <span>{t("blendedFinance.publicAmount", "Financement public (USD)")}</span>
                <span className="font-semibold text-primary-dark">{formatCount(publicAmount)}</span>
              </label>
              <input
                id="blended-amount"
                type="range"
                min="100000"
                max="50000000"
                step="100000"
                value={publicAmount}
                onChange={(event) => setPublicAmount(Number(event.target.value))}
                className="w-full accent-primary"
              />
            </div>

            <div>
              <label htmlFor="blended-mix" className="mb-1.5 flex items-center justify-between text-sm font-medium text-dark">
                <span>{t("blendedFinance.instrumentMix", "Mélange d'instruments")}</span>
                <span className="font-semibold text-primary-dark">{guaranteeShare}% {t("blendedFinance.guarantee", "garantie")}</span>
              </label>
              <input
                id="blended-mix"
                type="range"
                min="0"
                max="100"
                step="5"
                value={guaranteeShare}
                onChange={(event) => setGuaranteeShare(Number(event.target.value))}
                className="w-full accent-primary"
              />
              <div className="mt-1 flex justify-between text-xs text-body-color">
                <span>{t("blendedFinance.allGrant", "100% don")}</span>
                <span>{t("blendedFinance.allGuarantee", "100% garantie")}</span>
              </div>
            </div>
          </div>
        </NevCard>

        <div>
          {!result && (
            <NevCard padding="lg" className="flex h-full items-center justify-center text-center text-sm text-body-color">
              {"error" === status ? t("blendedFinance.error", "Impossible de calculer cette simulation.") : t("hero.loading", "Chargement…")}
            </NevCard>
          )}

          {result && (
            <NevCard padding="lg">
              <div className="mb-6 grid grid-cols-3 gap-4">
                <div className="rounded-lg border border-stroke p-4 text-center">
                  <p className="text-xs font-semibold uppercase tracking-wide text-body-color">{t("blendedFinance.leverage", "Effet de levier")}</p>
                  <p className="mt-1 text-2xl font-extrabold tabular-nums text-dark">{result.leverageRatio}x</p>
                </div>
                <div className="rounded-lg border border-stroke p-4 text-center">
                  <p className="text-xs font-semibold uppercase tracking-wide text-body-color">{t("blendedFinance.private", "Capital privé")}</p>
                  <p className="mt-1 text-2xl font-extrabold tabular-nums text-dark">{formatCount(Math.round(result.privateAmountMobilizedUsd))}</p>
                </div>
                <div className="rounded-lg border border-stroke p-4 text-center">
                  <p className="text-xs font-semibold uppercase tracking-wide text-body-color">{t("blendedFinance.total", "Total mobilisé")}</p>
                  <p className="mt-1 text-2xl font-extrabold tabular-nums text-dark">{formatCount(Math.round(result.totalMobilizedUsd))}</p>
                </div>
              </div>

              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-body-color">{t("blendedFinance.waterfall", "Cascade de financement")}</p>
              <div className="space-y-3">
                {result.waterfall.map((step) => (
                  <div key={step.stage} className="flex items-center gap-3">
                    <span className="w-40 shrink-0 text-xs text-body-color">{step.stage}</span>
                    <div className="h-8 flex-1 overflow-hidden rounded-md bg-gray-2">
                      <div
                        className="flex h-full items-center justify-end rounded-md px-2 text-xs font-semibold text-white"
                        style={{
                          width: maxWaterfallAmount > 0 ? Math.max(6, (100 * step.amountUsd) / maxWaterfallAmount) + "%" : "0%",
                          background: WATERFALL_COLORS[step.stage] || "#16A34A",
                        }}
                      >
                        {formatCount(Math.round(step.amountUsd))}
                      </div>
                    </div>
                  </div>
                ))}
              </div>

              <p className="mt-6 text-xs text-body-color">{result.methodologySource}</p>
            </NevCard>
          )}
        </div>
      </div>
    </div>
  );
}
