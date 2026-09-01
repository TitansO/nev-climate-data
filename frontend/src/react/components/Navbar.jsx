import { useEffect, useState } from "react";
import { useI18n } from "../providers/I18nProvider";

/**
 * React port of the "standard" header (assets/js/main.js's sticky-on-
 * scroll + mobile-toggle behavior, ported to React state instead of direct
 * classList manipulation) as it appears on login.html - logo, nav links,
 * language switch. Search box / notification bell / auth-nav-slot are NOT
 * included yet: login.html itself doesn't carry them, and other pages that
 * do haven't been migrated yet (later lots will extend this component
 * rather than guessing their shape now).
 *
 * DOM ids (#navbarToggler, #navbarCollapse, #lang-switch-btn) are kept
 * identical to the static-HTML version on purpose: src/input.css's
 * `.sticky #navbarCollapse li > a` (and siblings) select by these exact
 * ids - dropping them would silently break styling that already shipped
 * and was hand-verified earlier this project.
 */
const NAV_LINKS = [
  { href: "index.html", key: "nav.home", fallback: "Accueil" },
  { href: "data.html", key: "nav.data", fallback: "Données" },
  { href: "visualizations.html", key: "nav.visualizations", fallback: "Visualisations" },
  { href: "reports.html", key: "nav.reports", fallback: "Rapports" },
  { href: "sources.html", key: "nav.sources", fallback: "Sources" },
  { href: "about.html", key: "nav.about", fallback: "À propos" },
  { href: "api-docs.html", key: "nav.apiDocs", fallback: "Documentation API" },
];

export default function Navbar({ activeHref }) {
  const { t, toggleLang } = useI18n();
  const [sticky, setSticky] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);

  useEffect(() => {
    function onScroll() {
      // Matches main.js exactly: header.offsetTop is 0 (absolute, top-0),
      // so this is effectively "has the page scrolled at all".
      setSticky(window.pageYOffset > 0);
    }
    window.addEventListener("scroll", onScroll);
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const logoSrc = sticky ? "assets/images/logo/logo.svg" : "assets/images/logo/logo-white.svg";

  function closeMobileMenu() {
    setMobileOpen(false);
  }

  return (
    <header className={"ud-header absolute left-0 top-0 z-40 flex w-full items-center bg-transparent" + (sticky ? " sticky" : "")}>
      <div className="container mx-auto px-4">
        <div className="relative -mx-4 flex items-center justify-between">
          <div className="w-72 max-w-full px-4">
            <a href="index.html" className="navbar-logo block w-full py-5">
              <img src={logoSrc} alt="NEV Climate Data" className="header-logo h-12 w-auto" />
            </a>
          </div>
          <div className="flex w-full items-center justify-between px-4">
            <div className="w-full">
              <button
                id="navbarToggler"
                type="button"
                aria-label="Ouvrir le menu"
                className={"absolute right-4 top-1/2 block -translate-y-1/2 rounded-lg px-3 py-[6px] ring-primary focus:ring-2 lg:hidden" + (mobileOpen ? " navbarTogglerActive" : "")}
                onClick={() => setMobileOpen((open) => !open)}
              >
                <span className="relative my-[6px] block h-[2px] w-[28px] bg-white"></span>
                <span className="relative my-[6px] block h-[2px] w-[28px] bg-white"></span>
                <span className="relative my-[6px] block h-[2px] w-[28px] bg-white"></span>
              </button>
              <nav
                id="navbarCollapse"
                className={
                  "absolute right-4 top-full z-50 w-full max-w-[280px] rounded-lg bg-white py-5 shadow-lg lg:static lg:z-auto lg:flex lg:w-full lg:max-w-full lg:items-center lg:justify-between lg:bg-transparent lg:px-4 lg:py-0 lg:shadow-none xl:px-6" +
                  (mobileOpen ? "" : " hidden")
                }
              >
                <ul className="block lg:flex lg:items-center">
                  {NAV_LINKS.map((link) => (
                    <li key={link.href}>
                      <a
                        href={link.href}
                        onClick={closeMobileMenu}
                        className={
                          "mx-6 flex py-2 text-sm lg:mx-4 lg:inline-flex lg:py-6 " +
                          (link.href === activeHref ? "nav-link-active font-semibold text-white" : "font-medium text-white/90 hover:text-white")
                        }
                      >
                        {t(link.key, link.fallback)}
                      </a>
                    </li>
                  ))}
                </ul>
                <div className="mt-4 lg:mt-0">
                  <button
                    id="lang-switch-btn"
                    type="button"
                    className="rounded-md px-2 py-2 text-sm font-semibold text-white/90 transition hover:bg-white/10 hover:text-white"
                    aria-label="Changer de langue"
                    onClick={toggleLang}
                  >
                    {t("lang.switchTo", "EN")}
                  </button>
                </div>
              </nav>
            </div>
          </div>
        </div>
      </div>
    </header>
  );
}
