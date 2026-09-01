import { createContext, useCallback, useContext, useEffect, useState } from "react";

/**
 * NEV Climate Data - React port of assets/js/i18n.js, for pages migrated
 * to React (A3.1/A3.2 plan). Reuses the *same* dictionaries
 * (assets/i18n/fr.json / en.json), fetched at runtime exactly like the
 * vanilla-JS version - no content duplicated into the JS bundle, no key
 * renaming, both a React page and a not-yet-migrated static page reading
 * the same localStorage["nev_lang"] agree on the active language.
 *
 * Where the vanilla version applies translations by re-querying
 * `[data-i18n]` elements after the dictionary loads/changes, a React
 * component instead calls `t("dict.key", "Texte par défaut")` directly in
 * JSX - the second argument mirrors the real French text that used to sit
 * as the element's default HTML content, so nothing flashes as a raw key
 * during the (very short) initial dictionary fetch.
 */

const STORAGE_KEY = "nev_lang";
const SUPPORTED = ["fr", "en"];
const DEFAULT_LANG = "fr";

function currentLangFromStorage() {
  const stored = window.localStorage.getItem(STORAGE_KEY);
  return -1 !== SUPPORTED.indexOf(stored) ? stored : DEFAULT_LANG;
}

async function loadDictionary(lang) {
  const response = await fetch("assets/i18n/" + lang + ".json");
  return response.json();
}

const I18nContext = createContext(null);

export function I18nProvider({ children }) {
  const [lang, setLangState] = useState(currentLangFromStorage);
  const [dict, setDict] = useState(null);

  useEffect(() => {
    let cancelled = false;
    document.documentElement.lang = lang;
    loadDictionary(lang).then((loaded) => {
      if (!cancelled) {
        setDict(loaded);
      }
    });
    return () => {
      cancelled = true;
    };
  }, [lang]);

  const setLang = useCallback((nextLang) => {
    if (-1 === SUPPORTED.indexOf(nextLang)) {
      return;
    }
    window.localStorage.setItem(STORAGE_KEY, nextLang);
    setLangState(nextLang);
  }, []);

  const toggleLang = useCallback(() => {
    setLang(currentLangFromStorage() === "fr" ? "en" : "fr");
  }, [setLang]);

  const t = useCallback(
    (key, fallback) => {
      const useFallback = undefined !== fallback ? fallback : key;
      if (!dict) {
        return useFallback;
      }
      return Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : useFallback;
    },
    [dict]
  );

  const value = { lang, setLang, toggleLang, t };

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n() {
  const ctx = useContext(I18nContext);
  if (!ctx) {
    throw new Error("useI18n() must be used inside <I18nProvider>.");
  }
  return ctx;
}
