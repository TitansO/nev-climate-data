import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";
import NevBadge from "../components/ui/NevBadge";
import { LinkIcon } from "../components/ui/icons";

// Metadonnees non portees par GET /api/public/institutions (description,
// type de donnee, frequence) - cle = Source.name exact (voir
// backend/src/DataFixtures/SourceFixtures.php, seule source de verite
// pour ces noms). Toute source reelle non listee ici retombe sur un
// descriptif generique derive de son `type` (voir FALLBACK_BY_TYPE) -
// jamais masquee.
const SOURCE_METADATA = {
  "World Bank Data API": {
    description: "Financement climat multilatéral et contrepartie publique gouvernementale par pays, collectés automatiquement (API Projects).",
    dataType: "Financement de projets",
    updateFrequency: "Trimestrielle",
  },
  "Green Climate Fund - Annual Report (PDF)": {
    description: "Projets et montants financés par pays et secteur, extraits du rapport annuel publié par le Fonds Vert pour le Climat.",
    dataType: "Financement de projets",
    updateFrequency: "Annuelle",
  },
  "Green Climate Fund - IATI Datastore": {
    description: "Portefeuille de projets du Fonds Vert pour le Climat, publié au standard international IATI.",
    dataType: "Financement de projets",
    updateFrequency: "Trimestrielle",
  },
  "African Development Bank Group - IATI Datastore": {
    description: "Financement multilatéral et cofinancement gouvernemental de la Banque Africaine de Développement, publié au standard IATI.",
    dataType: "Financement de projets",
    updateFrequency: "Trimestrielle",
  },
  "UN SDG Global Database - Indicator 9.4.1 (IEA)": {
    description: "Émissions de CO2 par pays (indicateur ODD 9.4.1), données attribuées à l'Agence Internationale de l'Énergie.",
    dataType: "Indicateurs environnementaux",
    updateFrequency: "Trimestrielle",
  },
  "OPEC Fund - Climate Finance Report (PDF, Gemini-assisted)": {
    description: "Projets climat financés par le Fonds OPEP, extraits du rapport annuel via une extraction assistée par IA.",
    dataType: "Financement de projets",
    updateFrequency: "Annuelle",
  },
  "U.S. DFC - Annual Project Data (Excel)": {
    description: "Financement climat privé de la U.S. International Development Finance Corporation (projets énergies renouvelables).",
    dataType: "Financement de projets",
    updateFrequency: "Trimestrielle",
  },
  "Norfund - Investment Overview (Excel)": {
    description: "Financement climat privé du fonds souverain norvégien d'investissement pour les pays en développement.",
    dataType: "Financement de projets",
    updateFrequency: "Trimestrielle",
  },
  "Proparco - Open Data AFD": {
    description: "Financement climat privé de Proparco, filiale secteur privé de l'Agence Française de Développement.",
    dataType: "Financement de projets",
    updateFrequency: "Trimestrielle",
  },
  "OECD CRS - Private Development Finance": {
    description: "Financement climat privé des pays du Comité d'Aide au Développement de l'OCDE, avec Marqueurs de Rio officiels.",
    dataType: "Financement de projets",
    updateFrequency: "Trimestrielle",
  },
  "Agence Francaise de Developpement - IATI Datastore": {
    description: "Financement climat public bilatéral de l'Agence Française de Développement, publié au standard IATI.",
    dataType: "Financement de projets",
    updateFrequency: "Trimestrielle",
  },
  "British International Investment - IATI Datastore": {
    description: "Financement climat privé de British International Investment (institution de financement du développement du Royaume-Uni).",
    dataType: "Financement de projets",
    updateFrequency: "Trimestrielle",
  },
  "GreenAccess Platform Events": {
    description: "Scores climat, demandes de financement et contrats d'assurance, agrégés et anonymisés avant intégration.",
    dataType: "Indicateurs agrégés",
    updateFrequency: "Quasi temps réel",
    planned: true,
  },
  "NEV Climate Data - Internal Demonstration": {
    description: "Jeu de données de démonstration généré pour le développement et la présentation de la plateforme.",
    dataType: "Toutes catégories",
    updateFrequency: "Sur rechargement des fixtures",
  },
};

const TYPE_LABELS = {
  official_api: "API officielle",
  pdf_report: "Rapport PDF",
  green_access_event: "Évènements applicatifs",
  internal_demo: "Démonstration interne",
};

const FALLBACK_BY_TYPE = {
  official_api: { description: "Source de données officielle, collectée automatiquement.", dataType: "Financement de projets", updateFrequency: "Trimestrielle" },
  pdf_report: { description: "Données extraites d'un rapport publié par l'institution.", dataType: "Financement de projets", updateFrequency: "Annuelle" },
  green_access_event: { description: "Données issues de la plateforme GreenAccess.", dataType: "Indicateurs agrégés", updateFrequency: "Quasi temps réel", planned: true },
  internal_demo: { description: "Jeu de données de démonstration interne.", dataType: "Toutes catégories", updateFrequency: "Sur rechargement des fixtures" },
};

function ActiveBadge({ label }) {
  return (
    <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary">
      <span className="h-2 w-2 rounded-full bg-primary"></span>
      <span>{label}</span>
    </span>
  );
}

function PlannedBadge({ label }) {
  return (
    <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-dark-5">
      <span className="h-2 w-2 rounded-full bg-dark-6"></span>
      <span>{label}</span>
    </span>
  );
}

function SourceCard({ name, typeLabel, typeTone = "neutral", badge, description, dataType, updateFrequency, reliability, t }) {
  return (
    <NevCard padding="lg" interactive>
      <div className="mb-4 flex items-start justify-between gap-3">
        <div>
          <h3 className="text-lg font-bold text-dark">{name}</h3>
          <NevBadge tone={typeTone} className="mt-1.5">
            {typeLabel}
          </NevBadge>
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
    </NevCard>
  );
}

const RELIABILITY_LABELS = { low: "Faible", medium: "Moyenne", high: "Élevée" };

function useSources() {
  const { API_BASE_URL } = useAuth();
  const [state, setState] = useState("loading"); // loading | success | empty | error
  const [sources, setSources] = useState([]);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        // Seule surface publique (non authentifiee) listant les sources
        // reelles - voir App\Controller\PublicController::institutions().
        // Toute nouvelle source ajoutee en base (pipeline/collectors/*)
        // apparait ici automatiquement, sans modification de cette page.
        const response = await fetch(API_BASE_URL + "/api/public/institutions", { headers: { Accept: "application/json" } });
        if (!response.ok) {
          throw new Error("Une erreur est survenue (" + response.status + ").");
        }
        const body = await response.json();
        if (cancelled) {
          return;
        }
        setSources(body);
        setState(body.length > 0 ? "success" : "empty");
      } catch (error) {
        if (!cancelled) {
          setState("error");
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [API_BASE_URL]);

  return { state, sources };
}

export default function SourcesPage() {
  const { t } = useI18n();
  const { state, sources } = useSources();
  const active = <ActiveBadge label={t("sourcesPage.active", "Active")} />;
  const planned = <PlannedBadge label={t("sourcesPage.planned", "Prévue (Volet B)")} />;

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-[60px] pt-[48px] text-left text-white">
        <div className="container mx-auto px-4">
          <span className="mb-[18px] inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-accent">
            <LinkIcon className="h-3 w-3" /> {t("sourcesPage.pill", "Sources")}
          </span>
          <h1 className="mb-[14px] text-[clamp(1.7rem,4vw,2.6rem)] font-bold leading-[1.2]">{t("sourcesPage.title", "Sources de données")}</h1>
          <p className="max-w-[520px] text-base text-white/80">{t("sourcesPage.subtitle", "Les origines des données collectées, structurées et diffusées par la plateforme.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <section className="relative z-20 -mt-10 pb-20">
          {state === "loading" && (
            <p className="py-10 text-center text-sm text-dark-5">{t("sourcesPage.loading", "Chargement des sources…")}</p>
          )}
          {state === "error" && (
            <p className="py-10 text-center text-sm text-red-600">{t("sourcesPage.error", "Impossible de charger les sources pour le moment.")}</p>
          )}
          {state === "empty" && (
            <p className="py-10 text-center text-sm text-dark-5">{t("sourcesPage.empty", "Aucune source disponible pour le moment.")}</p>
          )}
          {state === "success" && (
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              {sources.map((source) => {
                const metadata = SOURCE_METADATA[source.name] || FALLBACK_BY_TYPE[source.type] || {};
                return (
                  <SourceCard
                    key={source.id}
                    name={source.name}
                    typeLabel={TYPE_LABELS[source.type] || source.type}
                    typeTone={source.type === "internal_demo" ? "warning" : "neutral"}
                    badge={metadata.planned ? planned : active}
                    description={metadata.description || ""}
                    dataType={metadata.dataType || "—"}
                    updateFrequency={metadata.updateFrequency || "—"}
                    reliability={RELIABILITY_LABELS[source.reliability] || source.reliability}
                    t={t}
                  />
                );
              })}
            </div>
          )}
          <p className="mt-8 text-xs text-dark-5">
            {t("sourcesPage.disclaimer", "Liste des institutions réellement connectées à la plateforme, à jour automatiquement.")}
          </p>
          {/* C1.8: these sources/bailleurs are also the ones opening the calls for
              proposals listed there (Opportunity.source) - natural next step from
              "who provides the data" to "who is currently funding". */}
          <a href="opportunities.html" className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-primary-dark hover:underline">
            {t("sourcesPage.seeOpportunities", "Voir les appels à projets ouverts par ces bailleurs →")}
          </a>
        </section>
      </div>
    </>
  );
}
