import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";

const ARROW_ICON = (
  <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
    <path strokeLinecap="round" strokeLinejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
  </svg>
);

function formatCount(value) {
  return Number(value).toLocaleString("fr-FR");
}

/**
 * Port of assets/js/hero-stats.js (A2.7): the 4 numbers start as "…" (not
 * hard-coded demo values) so there is no window where a mocked figure
 * could be mistaken for a real one - loading, success and error all only
 * ever set real API-derived text.
 */
function useHeroStats() {
  const { API_BASE_URL } = useAuth();
  const [values, setValues] = useState({ countries: "…", sectors: "…", funding: "…", sources: "…" });
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
          setValues({ countries: "-", sectors: "-", funding: "-", sources: "-" });
          setNote("Donnée non disponible.");
          return;
        }

        setValues({
          countries: formatCount(body.countriesCovered),
          sectors: formatCount(body.sectorsTracked),
          funding: formatCount(body.fundingRecords),
          sources: formatCount(body.activeSources),
        });
        setNote("");
      } catch (error) {
        if (!cancelled) {
          setValues({ countries: "-", sectors: "-", funding: "-", sources: "-" });
          setNote(error.message || "Impossible de contacter le serveur.");
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [API_BASE_URL]);

  return { values, note };
}

export default function IndexPage() {
  const { t } = useI18n();
  const { values: heroStats, note: heroStatsNote } = useHeroStats();

  return (
    <>
      {/* ====== Hero ====== */}
      <section className="relative overflow-hidden bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-20 pt-[150px] lg:pb-28 lg:pt-[180px]">
        <div className="pointer-events-none absolute inset-0 opacity-40" aria-hidden="true">
          <div className="absolute -left-24 -top-24 h-96 w-96 rounded-full bg-primary/20 blur-3xl"></div>
          <div className="absolute -bottom-24 -right-24 h-96 w-96 rounded-full bg-primary-light/10 blur-3xl"></div>
        </div>
        <div className="container relative mx-auto px-4">
          <div className="mx-auto max-w-[800px] text-center">
            <span className="mb-6 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-5 py-2 text-sm font-medium text-accent">
              {t("hero.badge", "🌍 Plateforme de données climatiques et de financement")}
            </span>
            <h1
              className="mb-6 text-3xl font-bold leading-tight text-white sm:text-4xl lg:text-5xl lg:leading-[1.15]"
              dangerouslySetInnerHTML={{
                __html: t("hero.title", 'Comprendre le climat. Structurer les données.<br class="hidden sm:block" />Éclairer les décisions.'),
              }}
            />
            <p className="mx-auto mb-10 max-w-[640px] text-base leading-relaxed text-white/80 sm:text-lg">
              {t("hero.subtitle", "Une plateforme centralisée pour explorer, structurer et diffuser les données climatiques et les données de financement.")}
            </p>
            <div className="flex flex-wrap items-center justify-center gap-4">
              <a
                href="data.html"
                className="inline-flex items-center gap-2 rounded-md bg-primary px-7 py-3.5 text-base font-semibold text-white shadow-1 transition duration-300 ease-in-out hover:bg-primary-dark"
              >
                <span>{t("hero.exploreData", "Explorer les données")}</span>
                {ARROW_ICON}
              </a>
              <a
                href="reports.html"
                className="inline-flex items-center gap-2 rounded-md border border-white/30 bg-white/10 px-7 py-3.5 text-base font-semibold text-white backdrop-blur transition duration-300 ease-in-out hover:bg-white/20"
              >
                <span>{t("hero.viewReports", "Consulter les rapports")}</span>
              </a>
            </div>
          </div>
        </div>
      </section>

      {/* ====== Stats ====== */}
      <section className="relative z-20 -mt-10 px-4">
        <div className="container mx-auto rounded-2xl bg-white p-6 shadow-2 sm:p-8">
          <div className="grid grid-cols-2 gap-6 lg:grid-cols-4">
            <div className="rounded-xl bg-surface p-6 text-center transition hover:-translate-y-1 hover:shadow-card">
              <div className="mb-1 text-3xl font-extrabold text-deep-3" aria-live="polite">
                {heroStats.countries}
              </div>
              <div className="text-sm font-medium text-dark-4">{t("stats.countries", "Pays couverts")}</div>
            </div>
            <div className="rounded-xl bg-surface p-6 text-center transition hover:-translate-y-1 hover:shadow-card">
              <div className="mb-1 text-3xl font-extrabold text-deep-3" aria-live="polite">
                {heroStats.sectors}
              </div>
              <div className="text-sm font-medium text-dark-4">{t("stats.sectors", "Secteurs suivis")}</div>
            </div>
            <div className="rounded-xl bg-surface p-6 text-center transition hover:-translate-y-1 hover:shadow-card">
              <div className="mb-1 text-3xl font-extrabold text-deep-3" aria-live="polite">
                {heroStats.funding}
              </div>
              <div className="text-sm font-medium text-dark-4">{t("stats.funding", "Données de financement")}</div>
            </div>
            <div className="rounded-xl bg-surface p-6 text-center transition hover:-translate-y-1 hover:shadow-card">
              <div className="mb-1 text-3xl font-extrabold text-deep-3" aria-live="polite">
                {heroStats.sources}
              </div>
              <div className="text-sm font-medium text-dark-4">{t("stats.sources", "Sources actives")}</div>
            </div>
          </div>
          <p className="mt-4 text-center text-xs text-dark-5">{heroStatsNote || t("hero.loading", "Chargement…")}</p>
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
              { key: "domains.climate", fallback: "Climat", path: "M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c1.657 0 3-4.03 3-9s-1.343-9-3-9-3 4.03-3 9 1.343 9 3 9zM3.5 9h17M3.5 15h17" },
              { key: "domains.funding", fallback: "Financement", path: "M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V6m0 2v8m0 0v2m0-2c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" },
              { key: "domains.energy", fallback: "Énergie", path: "M13 10V3L4 14h7v7l9-11h-7z" },
              { key: "domains.environment", fallback: "Environnement", path: "M12 3c-4.5 4-7 8-7 11.5A7 7 0 0012 21a7 7 0 007-6.5C19 11 16.5 7 12 3z" },
              { key: "domains.sustainability", fallback: "Développement durable", path: "M5 13l4 4L19 7" },
            ].map((domain) => (
              <div key={domain.key} className="group rounded-xl border border-stroke bg-white p-6 text-center transition hover:-translate-y-1 hover:shadow-card">
                <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary transition group-hover:bg-primary group-hover:text-white">
                  <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                    <path strokeLinecap="round" strokeLinejoin="round" d={domain.path} />
                  </svg>
                </div>
                <h3 className="text-base font-semibold text-dark">{t(domain.key, domain.fallback)}</h3>
              </div>
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
          <div className="grid grid-cols-1 gap-8 md:grid-cols-3">
            <div className="rounded-2xl border border-stroke p-8 transition hover:-translate-y-1 hover:shadow-card">
              <div className="mb-6 flex h-14 w-14 items-center justify-center rounded-xl bg-deep-3 text-white">
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M4 7v10c0 1.657 3.582 3 8 3s8-1.343 8-3V7M4 7c0 1.657 3.582 3 8 3s8-1.343 8-3M4 7c0-1.657 3.582-3 8-3s8 1.343 8 3m0 5c0 1.657-3.582 3-8 3s-8-1.343-8-3" />
                </svg>
              </div>
              <h3 className="mb-2 text-xl font-bold text-dark">{t("categories.funding.title", "Données de financement")}</h3>
              <p className="mb-6 text-body-color">{t("categories.funding.desc", "Financements publics, privés et multilatéraux par pays, secteur et année.")}</p>
              <a href="data.html" className="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:text-primary-dark">
                <span>{t("categories.funding.cta", "Explorer")}</span> {ARROW_ICON}
              </a>
            </div>
            <div className="rounded-2xl border border-stroke p-8 transition hover:-translate-y-1 hover:shadow-card">
              <div className="mb-6 flex h-14 w-14 items-center justify-center rounded-xl bg-primary text-white">
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M9 19V6l7 6.5L9 19z" />
                  <path strokeLinecap="round" strokeLinejoin="round" d="M3 21V3M3 21h18" />
                </svg>
              </div>
              <h3 className="mb-2 text-xl font-bold text-dark">{t("categories.viz.title", "Visualisations et tendances")}</h3>
              <p className="mb-6 text-body-color">{t("categories.viz.desc", "Évolution du financement, répartition sectorielle et indicateurs par région.")}</p>
              <a href="visualizations.html" className="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:text-primary-dark">
                <span>{t("categories.viz.cta", "Visualiser")}</span> {ARROW_ICON}
              </a>
            </div>
            <div className="rounded-2xl border border-stroke p-8 transition hover:-translate-y-1 hover:shadow-card">
              <div className="mb-6 flex h-14 w-14 items-center justify-center rounded-xl bg-status-review text-white">
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M19 20H7a2 2 0 01-2-2V6a2 2 0 012-2h7l5 5v9a2 2 0 01-2 2z" />
                  <path strokeLinecap="round" strokeLinejoin="round" d="M14 4v5h5M9 13h6M9 17h6" />
                </svg>
              </div>
              <h3 className="mb-2 text-xl font-bold text-dark">{t("categories.reports.title", "Rapports et analyses")}</h3>
              <p className="mb-6 text-body-color">{t("categories.reports.desc", "Rapports annuels, études sectorielles et publications régionales.")}</p>
              <a href="reports.html" className="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:text-primary-dark">
                <span>{t("categories.reports.cta", "Consulter")}</span> {ARROW_ICON}
              </a>
            </div>
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
              <div key={step.n} className="relative rounded-2xl bg-white p-8 text-center shadow-1">
                <div className={"mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full text-lg font-bold text-white " + step.bg}>{step.n}</div>
                <h3 className="mb-2 text-lg font-semibold text-dark">{t(step.titleKey, step.titleFallback)}</h3>
                <p className="text-sm text-body-color">{t(step.descKey, step.descFallback)}</p>
              </div>
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
            <a href="api-docs.html" className="rounded-md bg-primary px-7 py-3.5 text-base font-semibold text-white transition hover:bg-primary-dark">
              {t("nav.apiDocs", "Documentation API")}
            </a>
            <a href="login.html" className="rounded-md border border-white/30 bg-white/10 px-7 py-3.5 text-base font-semibold text-white backdrop-blur transition hover:bg-white/20">
              {t("ctaApi.getKeyBtn", "Obtenir une clé API")}
            </a>
          </div>
        </div>
      </section>
    </>
  );
}
