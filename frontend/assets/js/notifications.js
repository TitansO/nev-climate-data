/**
 * NEV Climate Data - navbar notification bell (A2.4).
 *
 * Self-contained, like assets/js/auth.js: renders the bell (hidden by
 * default in the HTML) only when a session exists, fetches the unread
 * count, and sends a click through to notifications.html. Does not touch
 * auth.js - a separate module keeps the "swap the Connexion slot" concern
 * (auth.js) and the "notification badge" concern independent, and each
 * degrades safely if the other's markup is absent from a given page.
 *
 * No framework/bundler exists in this project - plain DOM APIs, matching
 * the rest of assets/js/*.
 */
(function (global) {
  "use strict";

  function resolveApiBaseUrl() {
    const host = global.location.hostname;
    if (host === "localhost" || host === "127.0.0.1") {
      return "http://localhost:8080";
    }
    const codespaceMatch = host.match(/^(.+)-8123\.app\.github\.dev$/);
    if (codespaceMatch) {
      return "https://" + codespaceMatch[1] + "-8080.app.github.dev";
    }
    return "http://localhost:8080";
  }

  const API_BASE_URL = resolveApiBaseUrl();

  async function fetchUnreadCount() {
    const response = await NevAuth.authorizedFetch(API_BASE_URL + "/api/notifications/unread-count", {
      headers: { Accept: "application/json" },
    });

    if (!response.ok) {
      throw new Error("Unable to fetch unread notification count (" + response.status + ").");
    }

    const body = await response.json();
    return body.count;
  }

  function renderBadge(badgeEl, count) {
    if (count > 0) {
      badgeEl.textContent = count > 99 ? "99+" : String(count);
      badgeEl.classList.remove("hidden");
    } else {
      badgeEl.classList.add("hidden");
    }
  }

  async function initBell() {
    const button = document.getElementById("notif-bell-btn");
    if (!button || !NevAuth.isAuthenticated()) {
      return;
    }

    const badge = document.getElementById("notif-bell-badge");
    button.classList.remove("hidden");
    button.addEventListener("click", function () {
      window.location.href = "notifications.html";
    });

    try {
      renderBadge(badge, await fetchUnreadCount());
    } catch (error) {
      // Bell stays visible with no badge - a failed count fetch shouldn't
      // hide the entry point to the notifications page itself.
    }
  }

  document.addEventListener("DOMContentLoaded", initBell);

  global.NevNotifications = {
    fetchUnreadCount: fetchUnreadCount,
  };
})(window);
