import { useEffect, useMemo, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";
import NevSelect from "../components/ui/NevSelect";
import NevDataState from "../components/ui/NevDataState";
import NevBadge from "../components/ui/NevBadge";
import ProjectDetailModal from "../components/ProjectDetailModal";
import { formatUsd } from "../data/analyticsConstants";
import { BuildingIcon } from "../components/ui/icons";

// C2.7 : mêmes 4 classes que ai/gemini_client.py::TAXONOMY_CLASSES -
// tenues manuellement en synchronisation, comme STAGES ci-dessous l'est
// déjà avec ProjectPipelineStage côté backend.
const TAXONOMY_LABELS = {
  mitigation: "Atténuation",
  adaptation: "Adaptation",
  both: "Atténuation + adaptation",
  not_relevant: "Non pertinent",
};

// C2.3: ProjectPipelineStage's own 6 cases, in the cycle-of-life order the
// entity's docblock describes - "Rejected" can be reached from any earlier
// column, not just "Fundraising", but is still drawn last (a dead end, not
// a step forward).
const STAGES = [
  { value: "idea", label: "Idée" },
  { value: "scoping", label: "Cadrage" },
  { value: "structuring", label: "Structuration" },
  { value: "fundraising", label: "Soumission" },
  { value: "financed", label: "Financé" },
  { value: "rejected", label: "Rejeté" },
];

function distinctSorted(values) {
  return Array.from(new Set(values)).sort((a, b) => a.localeCompare(b, "fr"));
}

/**
 * C2.3 : tableau Kanban du pipeline projet, glisser-déposer natif (HTML5
 * Drag and Drop API - aucune librairie ajoutée, le projet n'en a besoin que
 * pour ce seul écran). Chaque déplacement de carte appelle
 * PATCH /api/projects/{id} immédiatement (mise à jour optimiste, annulée si
 * l'appel échoue) - pas de bouton "Enregistrer" séparé.
 */
export default function ProjectPipelinePage() {
  const { requireAuth, authorizedFetch, API_BASE_URL } = useAuth();
  const { t } = useI18n();

  const [status, setStatus] = useState("loading");
  const [projects, setProjects] = useState([]);
  const [errorMessage, setErrorMessage] = useState("");
  const [countryFilter, setCountryFilter] = useState("");
  const [sectorFilter, setSectorFilter] = useState("");
  const [taxonomyFilter, setTaxonomyFilter] = useState("");
  const [draggedId, setDraggedId] = useState(null);
  const [selectedProject, setSelectedProject] = useState(null);

  async function loadProjects() {
    setStatus("loading");
    try {
      const response = await authorizedFetch(API_BASE_URL + "/api/projects", { headers: { Accept: "application/json" } });
      if (!response.ok) {
        throw new Error("http " + response.status);
      }
      const body = await response.json();
      setProjects(body.data);
      setStatus("success");
    } catch (error) {
      setErrorMessage(t("projectPipeline.loadError", "Impossible de charger les projets."));
      setStatus("error");
    }
  }

  useEffect(() => {
    requireAuth();
    loadProjects();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const countries = useMemo(() => distinctSorted(projects.map((project) => project.country)), [projects]);
  const sectors = useMemo(() => distinctSorted(projects.map((project) => project.sector)), [projects]);

  const filtered = useMemo(
    () =>
      projects.filter(
        (project) =>
          ("" === countryFilter || project.country === countryFilter) &&
          ("" === sectorFilter || project.sector === sectorFilter) &&
          ("" === taxonomyFilter || project.taxonomyClass === taxonomyFilter)
      ),
    [projects, countryFilter, sectorFilter, taxonomyFilter]
  );

  async function moveProject(id, newStage) {
    const project = projects.find((candidate) => candidate.id === id);
    if (!project || project.pipelineStage === newStage) {
      return;
    }

    const previousStage = project.pipelineStage;
    setProjects((prev) => prev.map((candidate) => (candidate.id === id ? { ...candidate, pipelineStage: newStage } : candidate)));

    try {
      const response = await authorizedFetch(API_BASE_URL + "/api/projects/" + id, {
        method: "PATCH",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ stage: newStage }),
      });
      if (!response.ok) {
        throw new Error("http " + response.status);
      }
    } catch (error) {
      // Rollback : l'affichage ne doit jamais prétendre qu'un déplacement a
      // réussi si le backend l'a refusé.
      setProjects((prev) => prev.map((candidate) => (candidate.id === id ? { ...candidate, pipelineStage: previousStage } : candidate)));
    }
  }

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-[60px] pt-[48px] text-left text-white">
        <div className="container mx-auto px-4">
          <span className="mb-[18px] inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-accent">
            <BuildingIcon className="h-3 w-3" /> {t("projectPipeline.pill", "Suivi des projets")}
          </span>
          <h1 className="mb-[14px] text-[clamp(1.7rem,4vw,2.6rem)] font-bold leading-[1.2]">{t("projectPipeline.title", "Pipeline de projets")}</h1>
          <p className="max-w-[560px] text-base text-white/80">
            {t("projectPipeline.subtitle", "Suivez vos projets à la recherche de financement d'un stade à l'autre, et découvrez les opportunités les plus pertinentes pour chacun.")}
          </p>
        </div>
      </section>

      <div className="container mx-auto px-4 pb-20">
        <NevCard as="section" padding="md" className="relative z-20 -mt-10 mb-6 flex flex-wrap items-end gap-4 rounded-2xl shadow-card">
          <NevSelect id="filter-country" label={t("projectPipeline.filterCountry", "Pays")} value={countryFilter} onChange={(event) => setCountryFilter(event.target.value)} wrapperClassName="min-w-[180px]">
            <option value="">{t("projectPipeline.allCountries", "Tous les pays")}</option>
            {countries.map((country) => (
              <option key={country} value={country}>
                {country}
              </option>
            ))}
          </NevSelect>
          <NevSelect id="filter-sector" label={t("projectPipeline.filterSector", "Secteur")} value={sectorFilter} onChange={(event) => setSectorFilter(event.target.value)} wrapperClassName="min-w-[180px]">
            <option value="">{t("projectPipeline.allSectors", "Tous les secteurs")}</option>
            {sectors.map((sector) => (
              <option key={sector} value={sector}>
                {sector}
              </option>
            ))}
          </NevSelect>
          <NevSelect id="filter-taxonomy" label={t("projectPipeline.filterTaxonomy", "Classe taxonomie")} value={taxonomyFilter} onChange={(event) => setTaxonomyFilter(event.target.value)} wrapperClassName="min-w-[200px]">
            <option value="">{t("projectPipeline.allTaxonomies", "Toutes les classes")}</option>
            {Object.entries(TAXONOMY_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </NevSelect>
        </NevCard>

        <NevDataState state={status} loadingText={t("hero.loading", "Chargement…")} errorText={errorMessage} onRetry={loadProjects}>
          <div className="grid grid-cols-1 gap-4 overflow-x-auto sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            {STAGES.map((stage) => {
              const projectsInStage = filtered.filter((project) => project.pipelineStage === stage.value);
              return (
                <div
                  key={stage.value}
                  onDragOver={(event) => event.preventDefault()}
                  onDrop={(event) => {
                    event.preventDefault();
                    if (null !== draggedId) {
                      moveProject(draggedId, stage.value);
                    }
                  }}
                  className="flex min-h-[200px] flex-col gap-3 rounded-lg border border-stroke bg-gray-1 p-3"
                >
                  <h3 className="flex items-center justify-between text-xs font-bold uppercase tracking-wide text-dark-4">
                    <span>{t("projectPipeline.stage." + stage.value, stage.label)}</span>
                    <span className="rounded-full bg-white px-2 py-0.5 text-dark-5">{projectsInStage.length}</span>
                  </h3>
                  {projectsInStage.map((project) => (
                    <div
                      key={project.id}
                      draggable
                      onDragStart={() => setDraggedId(project.id)}
                      onDragEnd={() => setDraggedId(null)}
                      onClick={() => setSelectedProject(project)}
                      role="button"
                      tabIndex={0}
                      onKeyDown={(event) => "Enter" === event.key && setSelectedProject(project)}
                      className="cursor-grab rounded-md border border-stroke bg-white p-3 text-left shadow-sm transition hover:shadow-card active:cursor-grabbing"
                    >
                      <p className="text-sm font-semibold text-dark">{project.title}</p>
                      <p className="mt-1 text-xs text-body-color">
                        {project.country} · {project.sector}
                      </p>
                      <p className="mt-1 text-xs font-semibold text-primary-dark">{formatUsd(project.amountSought)}</p>
                      {project.taxonomyClass && (
                        <NevBadge tone="info" className="mt-2">
                          {TAXONOMY_LABELS[project.taxonomyClass] || project.taxonomyClass}
                        </NevBadge>
                      )}
                    </div>
                  ))}
                </div>
              );
            })}
          </div>
        </NevDataState>
      </div>

      <ProjectDetailModal
        project={selectedProject}
        onClose={() => setSelectedProject(null)}
        onProjectUpdated={(updated) => {
          setProjects((prev) => prev.map((candidate) => (candidate.id === updated.id ? updated : candidate)));
          setSelectedProject(updated);
        }}
      />
    </>
  );
}
