import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";
import NevDataState from "../components/ui/NevDataState";
import NevBadge from "../components/ui/NevBadge";
import NevButton from "../components/ui/NevButton";
import { UserIcon, BellIcon } from "../components/ui/icons";

const ROLE_LABELS = {
  super_admin: "Super administrateur",
  admin: "Administrateur",
  internal_analyst: "Analyste interne",
  external_partner: "Partenaire externe",
};

// C3.8 : les 4 conditions détectées par App\Service\AlertEvaluationService
// ("app:alerts:evaluate") - même ordre que App\Entity\Enum\AlertRuleType.
const ALERT_RULE_TYPES = [
  { value: "gap_critical", label: "Gap critique", description: "Un pays ne couvre pas au moins 25 % de son besoin CDN déclaré." },
  { value: "opportunity_closing", label: "Opportunité qui ferme", description: "Une opportunité de financement ferme dans les 14 prochains jours." },
  { value: "score_degraded", label: "Score dégradé", description: "Un score (équité, transparence…) chute d'au moins 10 points depuis son dernier calcul." },
  { value: "abnormal_flow", label: "Flux anormal", description: "Un pays reçoit un montant très supérieur à sa moyenne historique." },
];

/**
 * C3.8 : "réglages d'alertes dans le profil" - GET/POST/PATCH
 * /api/alert-rules, jamais construit lors du premier passage sur C3 (le
 * backend existait déjà, y compris la commande "app:alerts:evaluate" qui
 * évalue ces règles chaque jour). PATCH /api/alert-rules/{id} (désactiver)
 * a dû être ajouté ici : seuls GET+POST existaient, ce qui ne permettait
 * jamais de désactiver une règle une fois activée.
 */
function AlertSettingsSection() {
  const { authorizedFetch, API_BASE_URL } = useAuth();
  const { t } = useI18n();
  const [state, setState] = useState("loading");
  const [rulesByType, setRulesByType] = useState({});
  const [pendingType, setPendingType] = useState(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const response = await authorizedFetch(API_BASE_URL + "/api/alert-rules", { headers: { Accept: "application/json" } });
        if (!response.ok) {
          throw new Error("http " + response.status);
        }
        const body = await response.json();
        if (!cancelled) {
          const byType = {};
          body.forEach((rule) => {
            byType[rule.ruleType] = rule;
          });
          setRulesByType(byType);
          setState("success");
        }
      } catch (error) {
        if (!cancelled) {
          setState("error");
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [authorizedFetch, API_BASE_URL]);

  async function toggle(ruleType) {
    setPendingType(ruleType);
    try {
      const existing = rulesByType[ruleType];
      let response;
      if (existing) {
        response = await authorizedFetch(API_BASE_URL + "/api/alert-rules/" + existing.id, {
          method: "PATCH",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({ active: !existing.active }),
        });
      } else {
        response = await authorizedFetch(API_BASE_URL + "/api/alert-rules", {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({ ruleType }),
        });
      }
      if (!response.ok) {
        throw new Error("http " + response.status);
      }
      const updated = await response.json();
      setRulesByType((current) => ({ ...current, [ruleType]: updated }));
    } catch (error) {
      // Best-effort: leave the previous state visible rather than an error
      // screen for a single toggle failure - the user can simply retry.
    } finally {
      setPendingType(null);
    }
  }

  return (
    <NevCard as="section" padding="lg" className="mb-16">
      <div className="mb-5 flex items-center gap-2">
        <BellIcon className="h-5 w-5 text-primary" />
        <h2 className="text-lg font-bold text-dark">{t("profilePage.alertsTitle", "Alertes")}</h2>
      </div>
      <NevDataState state={state} loadingText={t("hero.loading", "Chargement…")} errorText={t("profilePage.alertsError", "Impossible de charger vos réglages d'alertes.")}>
        <ul className="divide-y divide-stroke">
          {ALERT_RULE_TYPES.map((ruleType) => {
            const rule = rulesByType[ruleType.value];
            const active = !!rule && rule.active;
            const busy = pendingType === ruleType.value;
            return (
              <li key={ruleType.value} className="flex items-center justify-between gap-4 py-4">
                <div>
                  <p className="text-sm font-semibold text-dark">{ruleType.label}</p>
                  <p className="mt-0.5 text-xs text-body-color">{ruleType.description}</p>
                </div>
                <button
                  type="button"
                  role="switch"
                  aria-checked={active}
                  aria-label={ruleType.label}
                  disabled={busy}
                  onClick={() => toggle(ruleType.value)}
                  className={
                    "relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition disabled:opacity-50 " + (active ? "bg-primary" : "bg-gray-3")
                  }
                >
                  <span className={"inline-block h-4 w-4 transform rounded-full bg-white transition " + (active ? "translate-x-6" : "translate-x-1")} />
                </button>
              </li>
            );
          })}
        </ul>
      </NevDataState>
    </NevCard>
  );
}

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

        {user && <AlertSettingsSection />}
      </div>
    </>
  );
}
