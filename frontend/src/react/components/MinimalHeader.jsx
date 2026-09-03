import { useI18n } from "../providers/I18nProvider";

/**
 * The stripped-down header used only by 404.html: logo + language switch,
 * no nav links, no footer on that page either. Kept as its own component
 * rather than a variant of <Navbar> since 404.html is deliberately minimal
 * (see the original static page) and shouldn't inherit Navbar's sticky/
 * mobile-menu machinery it never uses.
 */
export default function MinimalHeader() {
  const { t, toggleLang } = useI18n();

  return (
    <header className="ud-header absolute left-0 top-0 z-40 flex w-full items-center bg-transparent">
      <div className="container mx-auto px-4">
        <div className="-mx-4 flex items-center justify-between px-4 py-5">
          <a href="index.html" className="navbar-logo">
            <img src="assets/images/logo/logo-white.svg" alt="NEV Climate Data" className="header-logo h-12 w-auto" />
          </a>
          <button
            type="button"
            id="lang-switch-btn"
            className="rounded-md px-2 py-2 text-sm font-semibold text-white/90 transition hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
            aria-label="Changer de langue"
            onClick={toggleLang}
          >
            {t("lang.switchTo", "EN")}
          </button>
        </div>
      </div>
    </header>
  );
}
