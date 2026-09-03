import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";

const CHECK_ICON = (
  <svg className="mt-0.5 h-5 w-5 flex-shrink-0 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
  </svg>
);

export default function AboutPage() {
  const { t } = useI18n();

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-16 pt-[140px] text-center text-white lg:pt-[160px]">
        <div className="container mx-auto px-4">
          <h1 className="mb-3 text-3xl font-bold sm:text-4xl">{t("aboutPage.title", "À propos de NEV Climate Data")}</h1>
          <p className="mx-auto max-w-[640px] text-white/80">{t("aboutPage.subtitle", "Une infrastructure de données au service de la décision climatique en Afrique.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <NevCard as="section" padding="lg" className="relative z-20 -mt-10 mb-16 rounded-2xl shadow-card sm:p-12">
          <div className="mx-auto max-w-[820px]">
            <h2 className="mb-4 text-2xl font-bold text-dark">{t("aboutPage.missionTitle", "Notre mission")}</h2>
            <p className="mb-8 leading-relaxed text-body-color">
              {t(
                "aboutPage.mission",
                "NEV Climate Data a pour mission de collecter, structurer et diffuser les données climatiques et de financement à travers le continent africain, afin de fournir une base d'information fiable, cohérente et accessible aux décideurs publics, aux bailleurs de fonds, aux chercheurs et aux organisations de la société civile."
              )}
            </p>

            <h2 className="mb-4 text-2xl font-bold text-dark">{t("aboutPage.problemTitle", "Le problème que nous adressons")}</h2>
            <p className="mb-8 leading-relaxed text-body-color">
              {t(
                "aboutPage.problem",
                "Les données de financement climatique sont aujourd'hui dispersées entre de multiples institutions - banques multilatérales, fonds internationaux, agences nationales - publiées dans des formats hétérogènes et rarement comparables entre elles. Cette fragmentation rend difficile toute analyse transversale : suivre l'évolution réelle des flux financiers, comparer les pays entre eux, ou mesurer l'impact des investissements sur le terrain."
              )}
            </p>

            <h2 className="mb-4 text-2xl font-bold text-dark">{t("aboutPage.objectivesTitle", "Nos objectifs")}</h2>
            <ul className="mb-8 space-y-3">
              <li className="flex gap-3">
                {CHECK_ICON}
                <span className="text-body-color">{t("aboutPage.obj1", "Centraliser les données climatiques et de financement issues de sources officielles reconnues.")}</span>
              </li>
              <li className="flex gap-3">
                {CHECK_ICON}
                <span className="text-body-color">
                  {t("aboutPage.obj2", "Structurer ces données selon un référentiel commun (pays, secteur, année, type de financement) pour les rendre comparables.")}
                </span>
              </li>
              <li className="flex gap-3">
                {CHECK_ICON}
                <span className="text-body-color">{t("aboutPage.obj3", "Garantir la traçabilité et le statut de validation de chaque donnée diffusée.")}</span>
              </li>
              <li className="flex gap-3">
                {CHECK_ICON}
                <span className="text-body-color">{t("aboutPage.obj4", "Rendre ces données accessibles via une interface claire et une API documentée.")}</span>
              </li>
            </ul>

            <h2 className="mb-4 text-2xl font-bold text-dark">{t("aboutPage.whyTitle", "Pourquoi des données fiables et structurées")}</h2>
            <p className="mb-8 leading-relaxed text-body-color">
              {t(
                "aboutPage.why",
                "Une décision d'investissement climatique - publique ou privée - ne vaut que ce que valent les données qui l'éclairent. En documentant précisément l'origine, la fiabilité et le statut de validation de chaque enregistrement, NEV Climate Data cherche à distinguer clairement une donnée démonstrative d'une donnée vérifiée, condition nécessaire à une utilisation responsable de l'information dans la prise de décision."
              )}
            </p>

            <h2 className="mb-4 text-2xl font-bold text-dark">{t("aboutPage.visionTitle", "Notre vision")}</h2>
            <p className="leading-relaxed text-body-color">
              {t(
                "aboutPage.vision",
                "Devenir une référence de confiance pour l'analyse du financement climatique en Afrique - une infrastructure de données ouverte, structurée et durable, utile aussi bien aux institutions qu'aux chercheurs et aux citoyens."
              )}
            </p>
          </div>
        </NevCard>

        <section className="pb-20">
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
            <NevCard padding="md" className="text-center">
              <div className="mb-1 text-3xl font-extrabold tabular-nums text-deep-3">54</div>
              <div className="text-sm text-dark-4">{t("aboutPage.statCountries", "Pays d'Afrique couverts")}</div>
            </NevCard>
            <NevCard padding="md" className="text-center">
              <div className="mb-1 text-3xl font-extrabold tabular-nums text-deep-3">5</div>
              <div className="text-sm text-dark-4">{t("aboutPage.statSectors", "Secteurs de financement suivis")}</div>
            </NevCard>
            <NevCard padding="md" className="text-center">
              <div className="mb-1 text-3xl font-extrabold tabular-nums text-deep-3">2</div>
              <div className="text-sm text-dark-4">{t("aboutPage.statVolets", "Volets : application et pipeline de données")}</div>
            </NevCard>
          </div>
        </section>
      </div>
    </>
  );
}
