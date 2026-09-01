import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";

/**
 * React port of assets/js/notifications.js (A2.4): renders the bell only
 * when a session exists, fetches the unread count, sends a click through
 * to notifications.html. A failed count fetch leaves the bell visible with
 * no badge - it shouldn't hide the entry point to the notifications page.
 */
export default function NotificationBell() {
  const { isAuthenticated, authorizedFetch, API_BASE_URL } = useAuth();
  const [count, setCount] = useState(0);

  useEffect(() => {
    if (!isAuthenticated()) {
      return;
    }
    let cancelled = false;

    (async () => {
      try {
        const response = await authorizedFetch(API_BASE_URL + "/api/notifications/unread-count", {
          headers: { Accept: "application/json" },
        });
        if (!response.ok) {
          throw new Error("Unable to fetch unread notification count (" + response.status + ").");
        }
        const body = await response.json();
        if (!cancelled) {
          setCount(body.count);
        }
      } catch (error) {
        // Bell stays visible with no badge.
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [isAuthenticated, authorizedFetch, API_BASE_URL]);

  if (!isAuthenticated()) {
    return null;
  }

  return (
    <button
      type="button"
      id="notif-bell-btn"
      className="relative rounded-md p-2 text-white/90 transition hover:bg-white/10 hover:text-white"
      aria-label="Notifications"
      onClick={() => {
        window.location.href = "notifications.html";
      }}
    >
      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
        />
      </svg>
      {count > 0 && (
        <span
          id="notif-bell-badge"
          className="absolute -right-0.5 -top-0.5 inline-flex h-4 min-w-[16px] items-center justify-center rounded-full bg-status-demo px-1 text-[10px] font-bold leading-none text-white"
        >
          {count > 99 ? "99+" : String(count)}
        </span>
      )}
    </button>
  );
}
