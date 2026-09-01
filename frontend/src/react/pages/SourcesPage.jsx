import { useI18n } from "../providers/I18nProvider";

function ActiveBadge({ label }) {
  return (
    <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary">
      <span className="h-2 w-2 rounded-full bg-primary"></span>
      <span>{label}</span>
    </span>
  );
}

function SourceCard({ name, typeLabel, typeClass = "bg-surface-alt text-deep-3", badge, description, dataType, updateFrequency, reliability, t }) {
  return (
    <div className="rounded-2xl border border-stroke bg-white p-7 shadow-2 transition hover:-translate-y-1 hover:shadow-card">
      <div className="mb-4 flex items-start justify-between">
        <div>
          <h3 className="text-lg font-bold text-dark">{name}</h3>
          <span className={"mt-1 inline-block rounded-full px-3 py-1 text-xs font-semibold " + typeClass}>{typeLabel}</span>
        </div>
        {badge}
      </div>
      <p className="mb-5 text-sm text-body-color">{description}</p>
      <dl className="space-y-2 border-t border-stroke pt-4 text-sm">
        <div className="flex justify-between">
          <dt className="text-dark-5">{t("sourcesPage.dataType", "Type de donnée")}</dt>
          <dd className="font-semibold text-dark-3">{dataType}</dd>
        </div>
        <div className="flex justify-between">
          <dt className="text-dark-5">{t("sourcesPage.updateFrequency", "Fréquence de mise à jour")}</dt>
          <dd className="font-semibold text-dark-3">{updateFrequency}</dd>
        </div>
        <div className="flex justify-between">
          <dt className="text-dark-5">{t("sourcesPage.reliability", "Fiabilité")}</dt>
          <dd className="font-semibold text-dark-3">{reliability}</dd>
        </div>
      </dl>
    </div>
  );
}

export default function SourcesPage() {
  const { t } = useI18n();
  const active = <ActiveBadge label={t("sourcesPage.active", "Active")} />;

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-16 pt-[140px] text-center text-white lg:pt-[160px]">
        <div className="container mx-auto px-4">
          <h1 className="mb-3 text-3xl font-bold sm:text-4xl">{t("sourcesPage.title", "Sources de données")}</h1>
          <p className="mx-auto max-w-[640px] text-white/80">{t("sourcesPage.subtitle", "Les origines des données collectées, structurées et diffusées par la plateforme.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <section className="relative z-20 -mt-10 pb-20">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <SourceCard
              name="World Bank Data API"
              typeLabel="API officielle"
              badge={active}
              description="Indicateurs économiques et financement public par pays, collectés automatiquement."
              dataType="Indicateurs économiques"
              updateFrequency="Trimestrielle"
              reliability="Élevée"
              t={t}
            />
            <SourceCard
              name="Green Climate Fund - Rapport annuel (PDF)"
              typeLabel="Rapport PDF"
              badge={active}
              description="Projets et montants financés par pays et secteur, extraits des rapports publiés par le Fonds Vert pour le Climat."
              dataType="Financement de projets"
              updateFrequency="Annuelle"
              reliability="Moyenne"
              t={t}
            />
            <SourceCard
              name="GreenAccess Platform"
              typeLabel="Évènements applicatifs"
              badge={
                <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-dark-5">
                  <span className="h-2 w-2 rounded-full bg-dark-6"></span>
                  <span>{t("sourcesPage.planned", "Prévue (Volet B)")}</span>
                </span>
              }
              description="Scores climat, demandes de financement et contrats d'assurance, agrégés et anonymisés avant intégration."
              dataType="Indicateurs agrégés"
              updateFrequency="Quasi temps réel"
              reliability="Moyenne"
              t={t}
            />
            <SourceCard
              name="NEV Climate Data - Démonstration interne"
              typeLabel="Démonstration interne"
              typeClass="bg-status-demo-bg text-status-demo"
              badge={active}
              description="Jeu de données de démonstration généré pour le développement et la présentation de la plateforme (A1.6)."
              dataType="Toutes catégories"
              updateFrequency="Sur rechargement des fixtures"
              reliability="Faible (démonstration)"
              t={t}
            />
          </div>
          <p className="mt-8 text-xs text-dark-5">
            {t("sourcesPage.disclaimer", "Descriptions basées sur les connecteurs prévus par le plan d'implémentation (Volet B). Les statuts « Active »/« Prévue » restent statiques à ce stade.")}
          </p>
        </section>
      </div>
    </>
  );
}
