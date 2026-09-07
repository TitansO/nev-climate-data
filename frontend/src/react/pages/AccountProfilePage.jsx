import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";
import NevDataState from "../components/ui/NevDataState";
import NevBadge from "../components/ui/NevBadge";
import NevButton from "../components/ui/NevButton";
import { UserIcon } from "../components/ui/icons";

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
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-[60px] pt-[48px] text-left text-white">
        <div className="container mx-auto px-4">
          <span className="mb-[18px] inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-accent">
            <UserIcon className="h-3 w-3" /> {t("profilePage.pill", "Profil")}
          </span>
          <h1 className="mb-[14px] text-[clamp(1.7rem,4vw,2.6rem)] font-bold leading-[1.2]">{t("profilePage.title", "Mon profil")}</h1>
          <p className="max-w-[520px] text-base text-white/80">{t("profilePage.subtitle", "Informations de votre compte NEV Climate Data.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <NevCard as="section" padding="lg" className="relative z-20 -mt-10 mb-16 rounded-2xl shadow-card">
          <NevDataState state={null === user ? "loading" : "success"} loadingText={t("hero.loading", "Chargement…")}>
            <div className="mx-auto max-w-[480px]">
              <dl className="divide-y divide-stroke rounded-lg border border-stroke">
                <div className="flex items-center justify-between px-5 py-4">
                  <dt className="text-sm text-dark-5">{t("profilePage.email", "Adresse e-mail")}</dt>
                  <dd className="text-sm font-semibold text-dark">{user && user.email}</dd>
                </div>
                <div className="flex items-center justify-between px-5 py-4">
                  <dt className="text-sm text-dark-5">{t("profilePage.role", "Rôle")}</dt>
                  <dd>
                    <NevBadge tone={user && "super_admin" === user.role ? "success" : "neutral"}>{user && (ROLE_LABELS[user.role] || user.role)}</NevBadge>
                  </dd>
                </div>
              </dl>

              <div className="mt-8 flex flex-wrap gap-3">
                <NevButton as="a" href="account-api-keys.html" variant="primary">
                  {t("profilePage.myApiKeys", "Mes clés API")}
                </NevButton>
                {/* Only ever rendered for a confirmed SuperAdmin - never
                    present in the DOM for anyone else, matching the
                    vanilla version's "insert into the DOM, don't just
                    hide" approach. */}
                {user && "super_admin" === user.role && (
                  <NevButton as="a" href="account-users.html" variant="outline">
                    {t("profilePage.manageUsers", "Gérer les utilisateurs")}
                  </NevButton>
                )}
                <NevButton
                  type="button"
                  variant="ghost"
                  onClick={async () => {
                    await logout();
                    window.location.href = "index.html";
                  }}
                >
                  {t("profilePage.logout", "Déconnexion")}
                </NevButton>
              </div>
            </div>
          </NevDataState>
        </NevCard>
      </div>
    </>
  );
}
