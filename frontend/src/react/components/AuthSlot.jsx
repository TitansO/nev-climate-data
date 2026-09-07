import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";

/**
 * React port of the navbar's "Connexion" slot (auth.js's
 * renderNavbarAuthState()): a plain login link when logged out, or the
 * user's email + a logout button once a session is confirmed. Unlike the
 * vanilla version (which swaps HTML in place after the fact), React
 * renders the right state directly - no outerHTML replacement needed.
 */
export default function AuthSlot() {
  const { isAuthenticated, getCurrentUser, logout } = useAuth();
  const { t } = useI18n();
  const [user, setUser] = useState(null);

  useEffect(() => {
    if (!isAuthenticated()) {
      setUser(null);
      return;
    }
    let cancelled = false;
    getCurrentUser().then((fetchedUser) => {
      if (!cancelled) {
        setUser(fetchedUser);
      }
    });
    return () => {
      cancelled = true;
    };
  }, [isAuthenticated, getCurrentUser]);

  if (!isAuthenticated() || null === user) {
    return (
      <a
        href="login.html"
        id="auth-nav-slot"
        className="loginBtn signUpBtn rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white shadow-xs transition duration-300 ease-in-out hover:bg-primary-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      >
        {t("nav.login", "Connexion")}
      </a>
    );
  }

  return (
    <div className="flex items-center gap-3">
      <a
        href="account-profile.html"
        className="loginBtn rounded-sm text-sm font-medium text-dark-4 transition hover:text-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      >
        {user.email}
      </a>
      <button
        type="button"
        id="auth-logout-btn"
        className="loginBtn rounded-md border border-stroke px-4 py-2 text-sm font-semibold text-dark-4 transition duration-300 ease-in-out hover:bg-gray-2 hover:text-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        onClick={async () => {
          await logout();
          window.location.href = "index.html";
        }}
      >
        {t("profilePage.logout", "Déconnexion")}
      </button>
    </div>
  );
}
