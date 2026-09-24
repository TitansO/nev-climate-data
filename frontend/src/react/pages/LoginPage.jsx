import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";
import NevInput from "../components/ui/NevInput";
import NevButton from "../components/ui/NevButton";
import NevAlert from "../components/ui/NevAlert";

/**
 * Structuration des accès (2026-09-24) : ?redirect= permet de ramener le
 * visiteur exactement là où il voulait aller avant d'être arrêté par
 * RequireAuth (voir sa docblock). Toujours revalidé ici avant tout usage,
 * même si le seul émetteur actuel (RequireAuth) construit déjà cette
 * valeur à partir de la page courante - jamais depuis une saisie visiteur -
 * pour ne jamais faire confiance à un paramètre d'URL sans le vérifier,
 * qui reste manipulable par quiconque construit son propre lien. N'autorise
 * qu'un nom de fichier .html relatif de ce même site (pas de "//", pas de
 * "://", pas de chemin remontant) - une valeur absente ou invalide retombe
 * silencieusement sur la page de profil par défaut plutôt que d'échouer.
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

export default function LoginPage() {
  const { isAuthenticated, login } = useAuth();
  const { t } = useI18n();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  const redirectTarget = safeRedirectTarget(new URLSearchParams(window.location.search).get("redirect")) || "account-profile.html";

  // Same as the static page's inline script: bounce an already-logged-in
  // visitor straight to where they were headed instead of showing the form again.
  useEffect(() => {
    if (isAuthenticated()) {
      window.location.href = redirectTarget;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAuthenticated]);

  async function handleSubmit(event) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await login(email.trim(), password);
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

          <p className="mt-6 text-center text-xs text-dark-5">
            {t("loginPage.noAccount", "Pas encore de compte ?")}{" "}
            <a href={"register.html?redirect=" + encodeURIComponent(redirectTarget)} className="font-semibold text-primary hover:underline">
              {t("requireAuth.register", "Créer un compte")}
            </a>
          </p>

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
