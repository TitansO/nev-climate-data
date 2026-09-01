import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";

export default function LoginPage() {
  const { isAuthenticated, login } = useAuth();
  const { t } = useI18n();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  // Same as the static page's inline script: bounce an already-logged-in
  // visitor straight to their profile instead of showing the form again.
  useEffect(() => {
    if (isAuthenticated()) {
      window.location.href = "account-profile.html";
    }
  }, [isAuthenticated]);

  async function handleSubmit(event) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await login(email.trim(), password);
      window.location.href = "account-profile.html";
    } catch (err) {
      setError(err.message);
      setSubmitting(false);
    }
  }

  return (
    <section className="flex min-h-screen items-center bg-gradient-to-br from-deep via-deep-2 to-deep-3 px-4 pb-16 pt-[110px]">
      <div className="container mx-auto">
        <div className="mx-auto w-full max-w-[440px] rounded-2xl bg-white p-8 shadow-2 sm:p-10">
          <div className="mb-8 text-center">
            <img src="assets/images/logo/logo.svg" alt="NEV Climate Data" className="mx-auto mb-6 h-8 w-auto" />
            <h1 className="mb-2 text-xl font-bold text-dark">{t("nav.login", "Connexion")}</h1>
            <p className="text-sm text-body-color">{t("loginPage.subtitle", "Accédez à votre espace NEV Climate Data.")}</p>
          </div>

          {error && (
            <div className="mb-5 rounded-md border border-status-demo/30 bg-status-demo-bg/40 px-4 py-3 text-sm text-status-demo">{error}</div>
          )}

          <form onSubmit={handleSubmit} noValidate>
            <div className="mb-5">
              <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-dark-3">
                {t("profilePage.email", "Adresse e-mail")}
              </label>
              <input
                id="email"
                type="email"
                required
                autoComplete="email"
                placeholder="vous@organisation.org"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                className="w-full rounded-md border border-stroke bg-white px-4 py-3 text-sm text-dark shadow-input placeholder:text-dark-6 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
              />
            </div>
            <div className="mb-2">
              <label htmlFor="password" className="mb-1.5 block text-sm font-medium text-dark-3">
                {t("loginPage.password", "Mot de passe")}
              </label>
              <input
                id="password"
                type="password"
                required
                autoComplete="current-password"
                placeholder="••••••••"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                className="w-full rounded-md border border-stroke bg-white px-4 py-3 text-sm text-dark shadow-input placeholder:text-dark-6 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
              />
            </div>
            <div className="mb-6 text-right">
              <a href="#" className="text-xs font-medium text-dark-5 hover:text-primary">
                {t("loginPage.forgotPassword", "Mot de passe oublié ?")}
              </a>
            </div>
            <button
              type="submit"
              disabled={submitting}
              className="w-full rounded-md bg-primary px-6 py-3 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-70"
            >
              {submitting ? "Connexion…" : t("loginPage.submit", "Se connecter")}
            </button>
          </form>

          <p
            className="mt-6 text-center text-xs text-dark-5"
            dangerouslySetInnerHTML={{
              __html: t("loginPage.disclaimer", 'Authentification JWT réelle (<code class="font-mono">POST /api/auth/login</code>).'),
            }}
          />
        </div>
      </div>
    </section>
  );
}
