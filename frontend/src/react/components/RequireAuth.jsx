import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "./ui/NevCard";
import NevButton from "./ui/NevButton";
import { LockIcon } from "./ui/icons";

/**
 * Structuration des accès (2026-09-24) : garde de page pour toute
 * fonctionnalité réservée à ROLE_USER. Contrairement à
 * AuthProvider::requireAuth() (redirection immédiate et silencieuse vers
 * login.html, utilisée par les 4 pages déjà protégées avant cette
 * structuration - account-profile, account-api-keys, notifications,
 * project-pipeline - laissées telles quelles pour ne rien casser), ce
 * composant affiche une interface explicite plutôt que de rediriger sans
 * explication : un visiteur qui clique sur une fonctionnalité protégée doit
 * comprendre POURQUOI il atterrit sur une page de connexion, pas juste y
 * être renvoyé.
 *
 * Rappel important (voir aussi security.yaml) : ce composant est une
 * commodité d'UX, jamais une frontière de sécurité - la vraie protection
 * est le access_control de chaque endpoint côté Symfony, qui refuse déjà
 * tout appel non authentifié avec un 401 quel que soit l'état de ce
 * composant.
 *
 * `redirectTarget` est calculé à partir de window.location.pathname (la
 * page elle-même), jamais d'une valeur saisie par le visiteur - login.html
 * revalide quand même ce paramètre avant de l'utiliser (voir sa propre
 * docblock) pour se prémunir d'un open redirect même dans ce cas déjà sûr.
 */
export default function RequireAuth({ children }) {
  const { isAuthenticated } = useAuth();
  const { t } = useI18n();

  if (isAuthenticated()) {
    return children;
  }

  const redirectTarget = (window.location.pathname.split("/").pop() || "index.html") + window.location.search;

  return (
    <section className="flex min-h-[60vh] items-center justify-center px-4 py-20">
      <NevCard as="div" padding="lg" className="mx-auto w-full max-w-[440px] rounded-2xl text-center shadow-card">
        <div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary" aria-hidden="true">
          <LockIcon className="h-6 w-6" />
        </div>
        <h1 className="mb-2 text-lg font-bold text-dark">{t("requireAuth.title", "Connexion requise")}</h1>
        <p className="mb-6 text-sm leading-relaxed text-body-color">
          {t(
            "requireAuth.description",
            "Cette fonctionnalité nécessite un compte NEV Climate Data. Créez votre compte gratuitement pour accéder aux analyses avancées de NEV."
          )}
        </p>
        <div className="flex flex-col gap-3 sm:flex-row sm:justify-center">
          <NevButton as="a" href={"login.html?redirect=" + encodeURIComponent(redirectTarget)} variant="primary" size="md" className="w-full sm:w-auto">
            {t("nav.login", "Se connecter")}
          </NevButton>
          <NevButton as="a" href="register.html" variant="outline" size="md" className="w-full sm:w-auto">
            {t("requireAuth.register", "Créer un compte")}
          </NevButton>
        </div>
      </NevCard>
    </section>
  );
}
