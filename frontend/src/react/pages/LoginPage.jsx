import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";
import NevInput from "../components/ui/NevInput";
import NevButton from "../components/ui/NevButton";
import NevAlert from "../components/ui/NevAlert";

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
        <NevCard as="div" padding="lg" className="mx-auto w-full max-w-[440px] rounded-2xl shadow-card">
          <div className="mb-8 text-center">
            <img src="assets/images/logo/logo.svg" alt="NEV Climate Data" className="mx-auto mb-6 h-8 w-auto" />
            <h1 className="mb-2 text-xl font-bold text-dark">{t("nav.login", "Connexion")}</h1>
            <p className="text-sm text-body-color">{t("loginPage.subtitle", "Accédez à votre espace NEV Climate Data.")}</p>
          </div>

          {error ? (
            <NevAlert tone="danger" className="mb-5">
              {error}
            </NevAlert>
          ) : null}

          <form onSubmit={handleSubmit} noValidate className="space-y-5">
            <NevInput
              id="email"
              type="email"
              label={t("profilePage.email", "Adresse e-mail")}
              required
              autoComplete="email"
              placeholder="vous@organisation.org"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
            <div>
              <NevInput
                id="password"
                type="password"
                label={t("loginPage.password", "Mot de passe")}
                required
                autoComplete="current-password"
                placeholder="••••••••"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
              />
              <div className="mt-2 text-right">
                <a href="#" className="text-xs font-medium text-dark-5 transition hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:rounded-sm">
                  {t("loginPage.forgotPassword", "Mot de passe oublié ?")}
                </a>
              </div>
            </div>
            <NevButton type="submit" variant="primary" size="md" disabled={submitting} className="w-full py-3">
              {submitting ? "Connexion…" : t("loginPage.submit", "Se connecter")}
            </NevButton>
          </form>

          <p
            className="mt-6 text-center text-xs text-dark-5"
            dangerouslySetInnerHTML={{
              __html: t("loginPage.disclaimer", 'Authentification JWT réelle (<code class="font-mono">POST /api/auth/login</code>).'),
            }}
          />
        </NevCard>
      </div>
    </section>
  );
}
