import { useI18n } from "../providers/I18nProvider";

/**
 * React port of the shared footer (byte-for-byte identical across every
 * static page today, confirmed at exploration time) - one component now
 * instead of the markup duplicated on 13 pages.
 */
export default function Footer() {
  const { t } = useI18n();

  return (
    <footer className="border-t border-primary-light/20 bg-deep pt-16 text-gray-7">
      <div className="container mx-auto px-4">
        <div className="grid grid-cols-1 gap-10 pb-12 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <a href="index.html" className="mb-4 inline-block">
              <img src="assets/images/logo/logo-white.svg" alt="NEV Climate Data" className="h-8 w-auto" />
            </a>
            <p className="max-w-[260px] text-sm leading-relaxed">
              {t("footer.tagline", "Plateforme de collecte, structuration et diffusion des données climatiques et de financement.")}
            </p>
          </div>
          <div>
            <h4 className="mb-5 text-sm font-semibold uppercase tracking-wide text-white">{t("footer.navHeading", "Navigation")}</h4>
            <ul className="space-y-3 text-sm">
              <li>
                <a href="data.html" className="transition hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:rounded-sm">
                  {t("nav.data", "Données")}
                </a>
              </li>
              <li>
                <a href="visualizations.html" className="transition hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:rounded-sm">
                  {t("nav.visualizations", "Visualisations")}
                </a>
              </li>
              <li>
                <a href="reports.html" className="transition hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:rounded-sm">
                  {t("nav.reports", "Rapports")}
                </a>
              </li>
              <li>
                <a href="sources.html" className="transition hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:rounded-sm">
                  {t("nav.sources", "Sources")}
                </a>
              </li>
            </ul>
          </div>
          <div>
            <h4 className="mb-5 text-sm font-semibold uppercase tracking-wide text-white">{t("footer.docHeading", "Documentation")}</h4>
            <ul className="space-y-3 text-sm">
              <li>
                <a href="api-docs.html" className="transition hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:rounded-sm">
                  {t("nav.apiDocs", "Documentation API")}
                </a>
              </li>
              <li>
                <a href="about.html" className="transition hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:rounded-sm">
                  {t("nav.about", "À propos")}
                </a>
              </li>
              <li>
                <a href="login.html" className="transition hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:rounded-sm">
                  {t("nav.login", "Connexion")}
                </a>
              </li>
            </ul>
          </div>
          <div>
            <h4 className="mb-5 text-sm font-semibold uppercase tracking-wide text-white">{t("footer.contactHeading", "Contact")}</h4>
            <ul className="space-y-3 text-sm">
              <li>contact@nev-climate-data.org</li>
              <li>{t("footer.location", "Dakar, Sénégal")}</li>
            </ul>
          </div>
        </div>
        <div className="border-t border-white/10 py-6 text-center text-xs">{t("footer.copyright", "© 2026 NEV Climate Data. Tous droits réservés.")}</div>
      </div>
    </footer>
  );
}
