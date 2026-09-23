import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevButton from "../components/ui/NevButton";
import NevCard from "../components/ui/NevCard";
import HeroIllustration from "../components/HeroIllustration";
import { GlobeIcon, BanknoteIcon, ChartBarIcon, DocumentIcon } from "../components/ui/icons";
import { SECTOR_LABELS } from "../data/analyticsConstants";

const ARROW_ICON = (
  <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
    <path strokeLinecap="round" strokeLinejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
  </svg>
);

function formatCount(value) {
  return Number(value).toLocaleString("fr-FR");
}

/**
 * Port of assets/js/hero-stats.js (A2.7): the 4 numbers start as loading
 * (not hard-coded demo values) so there is no window where a mocked
 * figure could be mistaken for a real one - loading, success and error
 * all only ever set real API-derived text.
 */
function useHeroStats() {
  const { API_BASE_URL } = useAuth();
  const [state, setState] = useState("loading"); // loading | success | empty | error
  const [values, setValues] = useState({ countries: 0, sectors: 0, funding: 0, sources: 0 });
  const [note, setNote] = useState("");

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const response = await fetch(API_BASE_URL + "/api/analytics/hero-stats", { headers: { Accept: "application/json" } });
        if (!response.ok) {
          throw new Error("Une erreur est survenue (" + response.status + ").");
        }
        const body = await response.json();
        if (cancelled) {
          return;
        }

        const allZero = 0 === body.countriesCovered && 0 === body.sectorsTracked && 0 === body.fundingRecords && 0 === body.activeSources;
        if (allZero) {
          setState("empty");
          setNote("Donnée non disponible.");
          return;
        }

        setValues({
          countries: body.countriesCovered,
          sectors: body.sectorsTracked,
          funding: body.fundingRecords,
          sources: body.activeSources,
        });
        setState("success");
      } catch (error) {
        if (!cancelled) {
          setState("error");
          setNote(error.message || "Impossible de contacter le serveur.");
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [API_BASE_URL]);

  return { state, values, note };
}

const TRUSTED_SOURCES = ["World Bank Data API", "Green Climate Fund"];

export default function IndexPage() {
  const { t } = useI18n();
  const { state: heroState, values: heroStats, note: heroStatsNote } = useHeroStats();

  return (
    <>
      {/* ====== Hero ====== */}
      <section className="relative overflow-hidden bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-[100px] pt-[80px]">
        <div className="pointer-events-none absolute inset-0 opacity-40" aria-hidden="true">
          <div className="absolute -left-24 -top-24 h-96 w-96 rounded-full bg-primary/20 blur-3xl"></div>
          <div className="absolute -bottom-24 -right-24 h-96 w-96 rounded-full bg-primary-light/10 blur-3xl"></div>
        </div>
        <div className="container relative mx-auto px-4">
          <div className="grid items-start gap-12 lg:grid-cols-[1.1fr_0.9fr]">
            <div className="text-center lg:text-left">
              <span className="mb-[18px] inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-accent">
                <GlobeIcon className="h-3 w-3" /> {t("hero.badge", "Plateforme de données climatiques et de financement")}
              </span>
              <h1
                className="mb-[14px] text-[clamp(1.7rem,4vw,2.6rem)] font-bold leading-[1.2] text-white"
                dangerouslySetInnerHTML={{
                  __html: t("hero.title", 'Comprendre le climat. Structurer les données.<br class="hidden sm:block" />Éclairer les décisions.'),
                }}
              />
              <p className="mx-auto mb-[28px] max-w-[520px] text-base text-white/80 lg:mx-0">
                {t("hero.subtitle", "Une plateforme centralisée pour explorer, structurer et diffuser les données climatiques et les données de financement.")}
              </p>
              <div className="flex flex-wrap items-center justify-center gap-3 lg:justify-start">
                <NevButton as="a" href="data.html" variant="primary" className="px-[18px] py-[9px] text-[0.82rem] shadow-1">
                  <span>{t("hero.exploreData", "Explorer les données")}</span>
                  {ARROW_ICON}
                </NevButton>
                <NevButton as="a" href="reports.html" variant="outline" className="border-white/30 bg-white/10 px-[18px] py-[9px] text-[0.82rem] text-white backdrop-blur hover:bg-white/20 hover:text-white">
                  <span>{t("hero.viewReports", "Consulter les rapports")}</span>
                </NevButton>
              </div>
            </div>
            <div className="mx-auto flex w-full max-w-[420px] justify-center lg:max-w-none lg:justify-end">
              <HeroIllustration />
            </div>
          </div>
        </div>
      </section>

      {/* ====== Stats ====== */}
      <section className="relative z-20 -mt-10 px-4">
        <div className="container mx-auto rounded-2xl bg-white p-[22px] shadow-card sm:p-8">
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            {[
              { value: heroStats.countries, color: "#16a34a", label: t("stats.countries", "Pays couverts") },
              { value: heroStats.sectors, color: "#0ea5e9", label: t("stats.sectors", "Secteurs suivis") },
              { value: heroStats.funding, color: "#f59e0b", label: t("stats.funding", "Données de financement") },
              { value: heroStats.sources, color: "#7c3aed", label: t("stats.sources", "Sources actives") },
            ].map((stat) => (
              <div key={stat.label} className="text-center">
                <div className="text-[2rem] font-bold tabular-nums" style={{ color: stat.color }} aria-live="polite">
                  {"loading" === heroState ? "…" : "success" === heroState ? formatCount(stat.value) : "-"}
                </div>
                <div className="mt-1 text-[0.82rem] text-dark-5">{stat.label}</div>
              </div>
            ))}
          </div>
          {heroStatsNote ? <p className="mt-4 text-center text-xs text-dark-5">{heroStatsNote}</p> : null}
        </div>
      </section>

      {/* ====== Value banner ====== */}
      <section className="pt-16">
        <div className="container mx-auto px-4">
          <div className="rounded-2xl border border-stroke bg-surface-alt px-6 py-8 text-center sm:px-12">
            <p className="text-lg font-semibold text-deep-3 sm:text-xl">{t("valueBanner.text", "Des données structurées pour mieux comprendre les enjeux climatiques.")}</p>
          </div>
        </div>
      </section>

      {/* ====== Domaines ====== */}
      <section className="py-20">
        <div className="container mx-auto px-4">
          <div className="mx-auto mb-14 max-w-[600px] text-center">
            <span className="mb-2 block text-sm font-semibold uppercase tracking-wide text-primary">{t("domains.eyebrow", "Domaines couverts")}</span>
            <h2 className="mb-3 text-3xl font-bold text-dark sm:text-4xl">{t("domains.title", "Cinq domaines, une seule plateforme")}</h2>
            <p className="text-body-color">{t("domains.subtitle", "Les données sont organisées autour des grands enjeux du financement climatique.")}</p>
          </div>
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-5">
            {[
              { key: "domains.renewableEnergy", fallback: SECTOR_LABELS["Renewable Energy"], image: "assets/images/domains/renewable-energy.jpg" },
              { key: "domains.sustainableTransport", fallback: SECTOR_LABELS["Sustainable Transport"], image: "assets/images/domains/sustainable-transport.jpg" },
              { key: "domains.agriculture", fallback: SECTOR_LABELS.Agriculture, image: "assets/images/domains/agriculture.jpg" },
              { key: "domains.forestry", fallback: SECTOR_LABELS.Forestry, image: "assets/images/domains/forestry.jpg" },
              { key: "domains.adaptation", fallback: SECTOR_LABELS.Adaptation, image: "assets/images/domains/adaptation.jpg" },
            ].map((domain) => (
              <NevCard key={domain.key} padding="md" interactive className="group text-center">
                <img
                  src={domain.image}
                  alt={t(domain.key, domain.fallback)}
                  className="mb-4 aspect-square w-full rounded-lg object-cover transition group-hover:opacity-90"
                />
                <h3 className="text-base font-semibold text-dark">{t(domain.key, domain.fallback)}</h3>
              </NevCard>
            ))}
          </div>
        </div>
      </section>

      {/* ====== Catégories de données ====== */}
      <section className="bg-white py-20">
        <div className="container mx-auto px-4">
          <div className="mx-auto mb-14 max-w-[650px] text-center">
            <span className="mb-2 block text-sm font-semibold uppercase tracking-wide text-primary">{t("categories.eyebrow", "Ce que vous pouvez explorer")}</span>
            <h2 className="mb-3 text-3xl font-bold text-dark sm:text-4xl">{t("categories.title", "Catégories de données")}</h2>
            <p className="text-body-color">{t("categories.subtitle", "Accédez à des données structurées sur le financement climatique, les sources et les rapports publiés.")}</p>
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <NevCard padding="md" interactive>
              <img
                src="assets/images/categories/funding.jpg"
                alt={t("categories.funding.title", "Données de financement")}
                className="mb-4 aspect-[4/3] w-full rounded-lg object-cover"
              />
              <div className="mb-[10px] h-6 w-6 text-primary" aria-hidden="true">
                <BanknoteIcon />
              </div>
              <h3 className="mb-2 text-[0.95rem] font-bold text-dark">{t("categories.funding.title", "Données de financement")}</h3>
              <p className="mb-[14px] text-[0.82rem] leading-[1.55] text-body-color">{t("categories.funding.desc", "Financements publics, privés et multilatéraux par pays, secteur et année.")}</p>
              <NevButton as="a" href="data.html" size="sm" className="px-[13px] py-[6px] text-[0.78rem]">
                {t("categories.funding.cta", "Explorer")}
              </NevButton>
            </NevCard>
            <NevCard padding="md" interactive>
              <img
                src="assets/images/categories/visualizations.jpg"
                alt={t("categories.viz.title", "Visualisations et tendances")}
                className="mb-4 aspect-[4/3] w-full rounded-lg object-cover"
              />
              <div className="mb-[10px] h-6 w-6 text-primary" aria-hidden="true">
                <ChartBarIcon />
              </div>
              <h3 className="mb-2 text-[0.95rem] font-bold text-dark">{t("categories.viz.title", "Visualisations et tendances")}</h3>
              <p className="mb-[14px] text-[0.82rem] leading-[1.55] text-body-color">{t("categories.viz.desc", "Évolution du financement, répartition sectorielle et indicateurs par région.")}</p>
              <NevButton as="a" href="visualizations.html" size="sm" className="px-[13px] py-[6px] text-[0.78rem]">
                {t("categories.viz.cta", "Visualiser")}
              </NevButton>
            </NevCard>
            <NevCard padding="md" interactive>
              <img
                src="assets/images/categories/reports.jpg"
                alt={t("categories.reports.title", "Rapports et analyses")}
                className="mb-4 aspect-[4/3] w-full rounded-lg object-cover"
              />
              <div className="mb-[10px] h-6 w-6 text-primary" aria-hidden="true">
                <DocumentIcon />
              </div>
              <h3 className="mb-2 text-[0.95rem] font-bold text-dark">{t("categories.reports.title", "Rapports et analyses")}</h3>
              <p className="mb-[14px] text-[0.82rem] leading-[1.55] text-body-color">{t("categories.reports.desc", "Rapports annuels, études sectorielles et publications régionales.")}</p>
              <NevButton as="a" href="reports.html" size="sm" className="px-[13px] py-[6px] text-[0.78rem]">
                {t("categories.reports.cta", "Consulter")}
              </NevButton>
            </NevCard>
          </div>
        </div>
      </section>

      {/* ====== Sources / crédibilité ====== */}
      <section className="py-16">
        <div className="container mx-auto px-4">
          <div className="flex flex-col items-center gap-6 rounded-2xl border border-stroke bg-surface-alt px-6 py-10 text-center sm:px-12">
            <span className="text-sm font-semibold uppercase tracking-wide text-primary">{t("trustBand.eyebrow", "Données sourcées et traçables")}</span>
            <div className="flex flex-wrap items-center justify-center gap-3">
              {TRUSTED_SOURCES.map((name) => (
                <span key={name} className="rounded-md border border-stroke bg-white px-4 py-2 text-sm font-semibold text-dark-3 shadow-xs">
                  {name}
                </span>
              ))}
            </div>
            <a
              href="sources.html"
              className="inline-flex items-center gap-1 rounded-sm text-sm font-semibold text-primary transition hover:text-primary-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            >
              <span>{t("trustBand.cta", "Voir toutes les sources")}</span> {ARROW_ICON}
            </a>
          </div>
        </div>
      </section>

      {/* ====== Comment ça fonctionne ====== */}
      <section className="py-20">
        <div className="container mx-auto px-4">
          <div className="mx-auto mb-14 max-w-[600px] text-center">
            <span className="mb-2 block text-sm font-semibold uppercase tracking-wide text-primary">{t("howItWorks.eyebrow", "Méthodologie")}</span>
            <h2 className="mb-3 text-3xl font-bold text-dark sm:text-4xl">{t("howItWorks.title", "Comment ça fonctionne ?")}</h2>
          </div>
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            {[
              { n: 1, titleKey: "howItWorks.step1.title", titleFallback: "Collecte", descKey: "howItWorks.step1.desc", descFallback: "Agrégation depuis les sources officielles et partenaires.", bg: "bg-deep-3" },
              {
                n: 2,
                titleKey: "howItWorks.step2.title",
                titleFallback: "Structuration",
                descKey: "howItWorks.step2.desc",
                descFallback: "Normalisation par pays, secteur, année et type de financement.",
                bg: "bg-deep-3",
              },
              {
                n: 3,
                titleKey: "howItWorks.step3.title",
                titleFallback: "Validation",
                descKey: "howItWorks.step3.desc",
                descFallback: "Contrôle qualité et statut de validation par enregistrement.",
                bg: "bg-deep-3",
              },
              { n: 4, titleKey: "howItWorks.step4.title", titleFallback: "Diffusion", descKey: "howItWorks.step4.desc", descFallback: "Accès via l'interface, l'export et l'API documentée.", bg: "bg-primary" },
            ].map((step) => (
              <NevCard key={step.n} padding="lg" className="relative text-center">
                <div className={"mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full text-lg font-bold text-white " + step.bg}>{step.n}</div>
                <h3 className="mb-2 text-lg font-semibold text-dark">{t(step.titleKey, step.titleFallback)}</h3>
                <p className="text-sm text-body-color">{t(step.descKey, step.descFallback)}</p>
              </NevCard>
            ))}
          </div>
        </div>
      </section>

      {/* ====== CTA API ====== */}
      <section className="relative overflow-hidden bg-gradient-to-br from-deep via-deep-2 to-deep-3 py-20 text-center text-white">
        <div className="container relative mx-auto px-4">
          <h2 className="mb-4 text-2xl font-bold sm:text-3xl">{t("ctaApi.title", "Accès API pour développeurs")}</h2>
          <p className="mx-auto mb-8 max-w-[600px] text-white/80">
            {t("ctaApi.subtitle", "Intégrez les données NEV Climate Data directement dans vos applications via l'API REST authentifiée par JWT ou clé API.")}
          </p>
          <div className="flex flex-wrap items-center justify-center gap-4">
            <NevButton as="a" href="api-docs.html" variant="primary" className="px-7 py-3.5 text-base">
              {t("nav.apiDocs", "Documentation API")}
            </NevButton>
            <NevButton as="a" href="login.html" variant="outline" className="border-white/30 bg-white/10 px-7 py-3.5 text-base text-white backdrop-blur hover:bg-white/20 hover:text-white">
              {t("ctaApi.getKeyBtn", "Obtenir une clé API")}
            </NevButton>
          </div>
        </div>
      </section>
    </>
  );
}
