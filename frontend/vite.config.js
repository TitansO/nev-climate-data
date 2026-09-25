import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { resolve } from "path";

/**
 * NEV Climate Data - Vite config for the progressive React migration
 * (A3.1/A3.2 plan). "Multi-Page App" mode
 * (https://vite.dev/guide/build.html#multi-page-app): every migrated page
 * is its own entry, building to the *same filename* it already has today
 * (login.html, 404.html, ...) - so Netlify's existing "publish frontend/
 * as-is" setup and every external link/bookmark keep working completely
 * unchanged. No client-side router, no SPA - this stays a set of
 * independent HTML pages, exactly like it is today.
 *
 * outDir "." + emptyOutDir: false + assetsDir "assets/react": Vite writes
 * each built page's JS/CSS bundle into assets/react/ and overwrites only
 * that page's own .html file in place - every not-yet-migrated static
 * page and its existing assets/js/*.js stays completely untouched. This
 * means the migration can genuinely go page-by-page/lot-by-lot without
 * ever requiring a Netlify publish-directory change (that only becomes
 * necessary once *every* page has been migrated and the legacy static
 * files are retired for good).
 *
 * Pages are added to `input` one at a time as they're migrated - a page
 * not listed here is still the old static HTML, served exactly as
 * before. Lot 0: 404.html, login.html. Lot 1: about.html, sources.html,
 * api-docs.html. Lot 2: account-profile.html, account-api-keys.html,
 * account-users.html, notifications.html. Lot 3: data.html, reports.html.
 * Lot 4: index.html. Lot 5: visualizations.html.
 * C1.1: dashboard.html - new consolidated Dashboard view, not a migration
 * of an existing static page. C1.3: search.html - new full-page,
 * facetable search results view (GlobalSearch.jsx's navbar dropdown stays
 * the quick, capped-at-5-per-type entry point; this is where it links to).
 * C1.4: institution-profile.html - per-source profile page, not in the
 * main nav (reached via a search result's own destination, like
 * account-profile.html is reached from the account menu, not the navbar).
 * C1.7: instruments.html, C1.8: opportunities.html - not in the main nav
 * (kept short on purpose), reached instead via a contextual link from the
 * page closest to their role: VisualizationsPage's "type de financement"
 * card links to instruments.html, SourcesPage links to opportunities.html.
 * C2.3: project-pipeline.html - same "not in the main nav" reasoning,
 * reached via a contextual link from opportunities.html.
 * C2.5/C2.6/C2.8: impact-calculator.html, country-profile.html - same
 * "not in the main nav" reasoning, reached via contextual links (the
 * project detail modal's "Estimer l'impact" link, visualizations.html's
 * country map section).
 * C3.1/C3.3/C3.6/C3.7: gaps.html, equity.html, transparency.html,
 * recommendations.html - frontend built after the fact (the backend
 * endpoints existed from the original C3 batch, but had no UI, a gap
 * found during a later audit against the original implementation plan;
 * transparency.html's own endpoint, GET /api/analytics/transparency,
 * didn't exist yet either - only the per-source score did) - same
 * "not in the main nav" reasoning, reached via contextual links from
 * dashboard.html.
 */
export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: {
    outDir: ".",
    emptyOutDir: false,
    assetsDir: "assets/react",
    rollupOptions: {
      input: {
        404: resolve(__dirname, "404.html"),
        login: resolve(__dirname, "login.html"),
        register: resolve(__dirname, "register.html"),
        about: resolve(__dirname, "about.html"),
        sources: resolve(__dirname, "sources.html"),
        "api-docs": resolve(__dirname, "api-docs.html"),
        "account-profile": resolve(__dirname, "account-profile.html"),
        "account-api-keys": resolve(__dirname, "account-api-keys.html"),
        "account-users": resolve(__dirname, "account-users.html"),
        notifications: resolve(__dirname, "notifications.html"),
        data: resolve(__dirname, "data.html"),
        reports: resolve(__dirname, "reports.html"),
        index: resolve(__dirname, "index.html"),
        visualizations: resolve(__dirname, "visualizations.html"),
        dashboard: resolve(__dirname, "dashboard.html"),
        search: resolve(__dirname, "search.html"),
        "institution-profile": resolve(__dirname, "institution-profile.html"),
        instruments: resolve(__dirname, "instruments.html"),
        opportunities: resolve(__dirname, "opportunities.html"),
        tenders: resolve(__dirname, "tenders.html"),
        "project-pipeline": resolve(__dirname, "project-pipeline.html"),
        "impact-calculator": resolve(__dirname, "impact-calculator.html"),
        "country-profile": resolve(__dirname, "country-profile.html"),
        "blended-finance": resolve(__dirname, "blended-finance.html"),
        gaps: resolve(__dirname, "gaps.html"),
        equity: resolve(__dirname, "equity.html"),
        recommendations: resolve(__dirname, "recommendations.html"),
        transparency: resolve(__dirname, "transparency.html"),
      },
    },
  },
});
