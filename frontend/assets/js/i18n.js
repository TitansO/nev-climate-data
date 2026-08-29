/**
 * NEV Climate Data - lightweight FR/EN internationalization (A2.11).
 *
 * Convention: elements opt in via attributes -
 *   data-i18n="dict.key"            -> sets textContent
 *   data-i18n-html="dict.key"       -> sets innerHTML (only for the rare
 *                                      static string that embeds markup,
 *                                      e.g. the hero <br>; dictionaries are
 *                                      developer-authored, not user input)
 *   data-i18n-placeholder="dict.key"-> sets the "placeholder" attribute
 *   data-i18n-aria-label="dict.key" -> sets the "aria-label" attribute
 *
 * Dictionaries live in assets/i18n/{fr,en}.json. The active language is
 * kept in localStorage ("nev_lang", same convention as auth.js's
 * "nev_token") and re-applied instantly on toggle - no page reload -
 * via the #lang-switch-btn control present in the shared header.
 *
 * Global namespace (window.NevI18n), consistent with NevApi/NevAuth
 * elsewhere in this project (no bundler/module system here).
 */
(function (global) {
  "use strict";

  const STORAGE_KEY = "nev_lang";
  const SUPPORTED = ["fr", "en"];
  const DEFAULT_LANG = "fr";
  const dictionaries = {};
  let activeDict = null;

  function currentLang() {
    const stored = global.localStorage.getItem(STORAGE_KEY);
    return SUPPORTED.indexOf(stored) !== -1 ? stored : DEFAULT_LANG;
  }

  async function loadDictionary(lang) {
    if (dictionaries[lang]) {
      return dictionaries[lang];
    }
    const response = await fetch("assets/i18n/" + lang + ".json");
    dictionaries[lang] = await response.json();
    return dictionaries[lang];
  }

  function translate(dict, key) {
    return Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key;
  }

  function applyTranslations(dict) {
    activeDict = dict;
    document.querySelectorAll("[data-i18n]").forEach(function (el) {
      el.textContent = translate(dict, el.getAttribute("data-i18n"));
    });
    document.querySelectorAll("[data-i18n-html]").forEach(function (el) {
      el.innerHTML = translate(dict, el.getAttribute("data-i18n-html"));
    });
    document.querySelectorAll("[data-i18n-placeholder]").forEach(function (el) {
      el.setAttribute("placeholder", translate(dict, el.getAttribute("data-i18n-placeholder")));
    });
    document.querySelectorAll("[data-i18n-aria-label]").forEach(function (el) {
      el.setAttribute("aria-label", translate(dict, el.getAttribute("data-i18n-aria-label")));
    });
    const switchBtn = document.querySelector("#lang-switch-btn");
    if (switchBtn) {
      switchBtn.textContent = translate(dict, "lang.switchTo");
    }
  }

  async function setLang(lang) {
    if (SUPPORTED.indexOf(lang) === -1) {
      return;
    }
    global.localStorage.setItem(STORAGE_KEY, lang);
    document.documentElement.lang = lang;
    applyTranslations(await loadDictionary(lang));
  }

  async function init() {
    const lang = currentLang();
    document.documentElement.lang = lang;
    applyTranslations(await loadDictionary(lang));

    const switchBtn = document.querySelector("#lang-switch-btn");
    if (switchBtn) {
      switchBtn.addEventListener("click", function () {
        setLang(currentLang() === "fr" ? "en" : "fr");
      });
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  /**
   * Synchronous lookup against the currently active dictionary, for
   * scripts that inject markup after the page's initial translation pass
   * (e.g. auth.js swapping in the logged-in navbar) and need the right
   * string immediately rather than waiting on data-i18n's next pass.
   * Falls back to the key itself if called before the dictionary loads.
   */
  function t(key) {
    return activeDict ? translate(activeDict, key) : key;
  }

  /**
   * Re-applies the active dictionary to the current DOM - call this after
   * injecting new data-i18n-tagged markup (e.g. auth.js's navbar swap) so
   * it picks up the right language immediately, and stays correct if the
   * user switches language afterwards (data-i18n is a live attribute, not
   * a one-time substitution).
   */
  function refresh() {
    if (activeDict) {
      applyTranslations(activeDict);
    }
  }

  global.NevI18n = { setLang: setLang, currentLang: currentLang, t: t, refresh: refresh };
})(window);
