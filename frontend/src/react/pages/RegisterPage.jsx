import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";
import NevInput from "../components/ui/NevInput";
import NevButton from "../components/ui/NevButton";
import NevAlert from "../components/ui/NevAlert";

/**
 * Structuration des accès (2026-09-24) : auto-inscription publique. Le
 * compte créé est toujours "external_partner" côté backend (le palier le
 * moins privilégié) - voir App\Controller\AuthenticationController::register().
 * Même validation de ?redirect= que LoginPage (voir sa docblock) : ce
 * paramètre transite simplement jusqu'à la connexion automatique qui suit
 * une inscription réussie.
 */
function safeRedirectTarget(rawValue) {
  if ("string" !== typeof rawValue || "" === rawValue) {
    return null;
  }
  if (!/^[a-zA-Z0-9_-]+\.html(\?[^\s]*)?$/.test(rawValue)) {
    return null;
  }
  return rawValue;
}

export default function RegisterPage() {
  const { isAuthenticated, register } = useAuth();
  const { t } = useI18n();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [error, setError] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  const redirectTarget = safeRedirectTarget(new URLSearchParams(window.location.search).get("redirect")) || "account-profile.html";

  useEffect(() => {
    if (isAuthenticated()) {
      window.location.href = redirectTarget;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAuthenticated]);

  async function handleSubmit(event) {
    event.preventDefault();
    setError(null);

    if (password !== confirmPassword) {
      setError(t("registerPage.passwordMismatch", "Les deux mots de passe ne correspondent pas."));
      return;
    }

    setSubmitting(true);
    try {
      await register(name.trim(), email.trim(), password);
      window.location.href = redirectTarget;
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
            <h1 className="mb-2 text-xl font-bold text-dark">{t("registerPage.title", "Créer un compte")}</h1>
            <p className="text-sm text-body-color">
              {t("registerPage.subtitle", "Accédez gratuitement aux analyses avancées de NEV Climate Data.")}
            </p>
          </div>

          {error ? (
            <NevAlert tone="danger" className="mb-5">
              {error}
            </NevAlert>
          ) : null}

          <form onSubmit={handleSubmit} noValidate className="space-y-5">
            <NevInput
              id="name"
              type="text"
              label={t("registerPage.name", "Nom complet")}
              required
              autoComplete="name"
              placeholder="Amina Diallo"
              value={name}
              onChange={(event) => setName(event.target.value)}
            />
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
            <NevInput
              id="password"
              type="password"
              label={t("loginPage.password", "Mot de passe")}
              required
              autoComplete="new-password"
              placeholder="••••••••"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              hint={t("registerPage.passwordHint", "Au moins 8 caractères, avec une lettre et un chiffre.")}
            />
            <NevInput
              id="confirm-password"
              type="password"
              label={t("registerPage.confirmPassword", "Confirmer le mot de passe")}
              required
              autoComplete="new-password"
              placeholder="••••••••"
              value={confirmPassword}
              onChange={(event) => setConfirmPassword(event.target.value)}
            />
            <NevButton type="submit" variant="primary" size="md" disabled={submitting} className="w-full py-3">
              {submitting ? "Création…" : t("registerPage.submit", "Créer mon compte")}
            </NevButton>
          </form>

          <p className="mt-6 text-center text-xs text-dark-5">
            {t("registerPage.hasAccount", "Vous avez déjà un compte ?")}{" "}
            <a href={"login.html?redirect=" + encodeURIComponent(redirectTarget)} className="font-semibold text-primary hover:underline">
              {t("nav.login", "Se connecter")}
            </a>
          </p>
        </NevCard>
      </div>
    </section>
  );
}
