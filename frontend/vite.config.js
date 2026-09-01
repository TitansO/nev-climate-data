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
 * account-users.html, notifications.html. Lot 3 (this commit):
 * data.html, reports.html.
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
        about: resolve(__dirname, "about.html"),
        sources: resolve(__dirname, "sources.html"),
        "api-docs": resolve(__dirname, "api-docs.html"),
        "account-profile": resolve(__dirname, "account-profile.html"),
        "account-api-keys": resolve(__dirname, "account-api-keys.html"),
        "account-users": resolve(__dirname, "account-users.html"),
        notifications: resolve(__dirname, "notifications.html"),
        data: resolve(__dirname, "data.html"),
        reports: resolve(__dirname, "reports.html"),
      },
    },
  },
});
