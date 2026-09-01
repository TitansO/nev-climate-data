import { useI18n } from "../providers/I18nProvider";

const EXTERNAL_LINK_ICON = (
  <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
    <path strokeLinecap="round" strokeLinejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
  </svg>
);

export default function ApiDocsPage() {
  const { t } = useI18n();

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-16 pt-[140px] text-center text-white lg:pt-[160px]">
        <div className="container mx-auto px-4">
          <h1 className="mb-3 text-3xl font-bold sm:text-4xl">{t("nav.apiDocs", "Documentation API")}</h1>
          <p className="mx-auto max-w-[640px] text-white/80">{t("apiDocsPage.subtitle", "Intégrez les données NEV Climate Data dans vos applications via l'API REST.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <section className="relative z-20 -mt-10 mb-12 rounded-2xl bg-white p-8 shadow-2 sm:p-10">
          <h2 className="mb-3 text-xl font-bold text-dark">{t("apiDocsPage.overviewTitle", "Présentation")}</h2>
          <p
            className="mb-4 leading-relaxed text-body-color"
            dangerouslySetInnerHTML={{
              __html: t(
                "apiDocsPage.overview",
                'L\'API NEV Climate Data expose les données climatiques et de financement de la plateforme au format JSON, sous le préfixe <code class="rounded bg-surface-alt px-1.5 py-0.5 font-mono text-sm text-deep-3">/api</code>. Elle est actuellement en fondations (Phase A1) : l\'authentification et la gestion des clés d\'accès sont opérationnelles ; les endpoints de données métier (extraction, agrégats, recherche) seront ajoutés en Phase A2.'
              ),
            }}
          />
          <a href="/api/doc" target="_blank" rel="noopener" className="inline-flex items-center gap-2 rounded-md bg-primary px-6 py-3 text-sm font-semibold text-white transition hover:bg-primary-dark">
            <span>{t("apiDocsPage.openSwagger", "Ouvrir la documentation Swagger / OpenAPI")}</span>
            {EXTERNAL_LINK_ICON}
          </a>
          <p
            className="mt-2 text-xs text-dark-5"
            dangerouslySetInnerHTML={{
              __html: t("apiDocsPage.swaggerNote", 'Lien relatif au backend (<code class="font-mono">/api/doc</code>) - servi par le même serveur que l\'API, généré automatiquement depuis le code (NelmioApiDocBundle).'),
            }}
          />
        </section>

        <section className="mb-12 grid grid-cols-1 gap-6 lg:grid-cols-2">
          <div className="rounded-2xl border border-stroke bg-white p-7">
            <h3 className="mb-3 text-lg font-bold text-dark">{t("apiDocsPage.jwtTitle", "Authentification JWT")}</h3>
            <p className="mb-4 text-sm text-body-color">
              {t("apiDocsPage.jwtDesc", "Un compte utilisateur s'authentifie par identifiants (email / mot de passe) pour obtenir un jeton d'accès de courte durée et un jeton de rafraîchissement.")}
            </p>
            <div className="space-y-2 font-mono text-xs">
              <div className="rounded-md bg-dark px-4 py-2.5 text-gray-7">
                <span className="text-primary-light">POST</span> /api/auth/login
              </div>
              <div className="rounded-md bg-dark px-4 py-2.5 text-gray-7">
                <span className="text-primary-light">POST</span> /api/auth/refresh
              </div>
              <div className="rounded-md bg-dark px-4 py-2.5 text-gray-7">
                <span className="text-primary-light">GET</span> /api/auth/me
              </div>
              <div className="rounded-md bg-dark px-4 py-2.5 text-gray-7">
                <span className="text-primary-light">POST</span> /api/auth/logout
              </div>
            </div>
          </div>
          <div className="rounded-2xl border border-stroke bg-white p-7">
            <h3 className="mb-3 text-lg font-bold text-dark">{t("apiDocsPage.apiKeysTitle", "Clés API")}</h3>
            <p
              className="mb-4 text-sm text-body-color"
              dangerouslySetInnerHTML={{
                __html: t(
                  "apiDocsPage.apiKeysDesc",
                  'Une fois connecté, un utilisateur peut générer ses propres clés API pour un accès programmatique via l\'en-tête <code class="rounded bg-surface-alt px-1 font-mono">X-API-Key</code>, sans jeton JWT.'
                ),
              }}
            />
            <div className="space-y-2 font-mono text-xs">
              <div className="rounded-md bg-dark px-4 py-2.5 text-gray-7">
                <span className="text-primary-light">POST</span> /api/api-keys
              </div>
              <div className="rounded-md bg-dark px-4 py-2.5 text-gray-7">
                <span className="text-primary-light">GET</span> /api/api-keys
              </div>
              <div className="rounded-md bg-dark px-4 py-2.5 text-gray-7">
                <span className="text-primary-light">DELETE</span> /api/api-keys/{"{id}"}
              </div>
            </div>
            <p className="mt-4 text-xs text-dark-5">{t("apiDocsPage.apiKeyNote", "La clé brute n'est affichée qu'à la création - elle doit être sauvegardée immédiatement.")}</p>
          </div>
        </section>

        <section className="mb-16 rounded-2xl border border-stroke bg-white p-7">
          <h3 className="mb-4 text-lg font-bold text-dark">{t("apiDocsPage.exampleTitle", "Exemple d'appel")}</h3>
          <pre className="overflow-x-auto rounded-lg bg-dark p-5 text-xs leading-relaxed text-gray-7">
            <code>{'curl -H "X-API-Key: nev_<votre_cle>" \\\n  https://api.nev-climate-data.org/api/auth/me'}</code>
          </pre>
          <p className="mt-4 text-xs text-dark-5">
            {t("apiDocsPage.exampleNote", "Les endpoints de données (extraction, filtres, agrégats) ne sont pas encore disponibles - ils seront documentés ici lors des tâches A2.1 (backend) et suivantes.")}
          </p>
        </section>
      </div>
    </>
  );
}
