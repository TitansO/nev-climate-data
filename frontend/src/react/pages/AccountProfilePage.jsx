import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";

const ROLE_LABELS = {
  super_admin: "Super administrateur",
  admin: "Administrateur",
  internal_analyst: "Analyste interne",
  external_partner: "Partenaire externe",
};

export default function AccountProfilePage() {
  const { requireAuth, getCurrentUser, logout } = useAuth();
  const { t } = useI18n();
  const [user, setUser] = useState(null);

  useEffect(() => {
    requireAuth();
    let cancelled = false;
    getCurrentUser().then((fetchedUser) => {
      if (cancelled) {
        return;
      }
      if (null === fetchedUser) {
        window.location.href = "login.html";
        return;
      }
      setUser(fetchedUser);
    });
    return () => {
      cancelled = true;
    };
  }, [requireAuth, getCurrentUser]);

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-16 pt-[140px] text-center text-white lg:pt-[160px]">
        <div className="container mx-auto px-4">
          <h1 className="mb-3 text-3xl font-bold sm:text-4xl">{t("profilePage.title", "Mon profil")}</h1>
          <p className="mx-auto max-w-[640px] text-white/80">{t("profilePage.subtitle", "Informations de votre compte NEV Climate Data.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <section className="relative z-20 -mt-10 mb-16 rounded-2xl bg-white p-8 shadow-2 sm:p-10">
          {null === user ? (
            <div className="py-10 text-center text-sm text-body-color">{t("hero.loading", "Chargement…")}</div>
          ) : (
            <div className="mx-auto max-w-[480px]">
              <dl className="divide-y divide-stroke rounded-xl border border-stroke">
                <div className="flex items-center justify-between px-5 py-4">
                  <dt className="text-sm text-dark-5">{t("profilePage.email", "Adresse e-mail")}</dt>
                  <dd className="text-sm font-semibold text-dark">{user.email}</dd>
                </div>
                <div className="flex items-center justify-between px-5 py-4">
                  <dt className="text-sm text-dark-5">{t("profilePage.role", "Rôle")}</dt>
                  <dd className="text-sm font-semibold text-dark">{ROLE_LABELS[user.role] || user.role}</dd>
                </div>
              </dl>

              <div className="mt-8 flex flex-wrap gap-3">
                <a
                  href="account-api-keys.html"
                  className="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-dark"
                >
                  {t("profilePage.myApiKeys", "Mes clés API")}
                </a>
                {/* Only ever rendered for a confirmed SuperAdmin - never
                    present in the DOM for anyone else, matching the
                    vanilla version's "insert into the DOM, don't just
                    hide" approach. */}
                {"super_admin" === user.role && (
                  <a
                    href="account-users.html"
                    className="inline-flex items-center justify-center gap-2 rounded-md border border-primary px-6 py-2.5 text-sm font-semibold text-primary transition hover:bg-surface-alt"
                  >
                    {t("profilePage.manageUsers", "Gérer les utilisateurs")}
                  </a>
                )}
                <button
                  type="button"
                  className="rounded-md border border-stroke px-6 py-2.5 text-sm font-semibold text-dark-4 transition hover:bg-gray-2"
                  onClick={async () => {
                    await logout();
                    window.location.href = "index.html";
                  }}
                >
                  {t("profilePage.logout", "Déconnexion")}
                </button>
              </div>
            </div>
          )}
        </section>
      </div>
    </>
  );
}
