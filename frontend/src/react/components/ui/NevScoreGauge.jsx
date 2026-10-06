import NevCard from "./NevCard";
import NevBadge from "./NevBadge";

const GRADE_TONE = { A: "success", B: "success", C: "warning", D: "warning", E: "danger" };
const GRADE_BAR = { A: "bg-status-validated", B: "bg-status-validated", C: "bg-status-demo", D: "bg-status-demo", E: "bg-danger" };

/**
 * C2.4/C2.6/C2.8 : rendu partagé pour tout score renvoyé par
 * App\Service\Scoring\ScoreService::toArray() (value 0-100, grade A-E,
 * breakdown JSONB) - une seule fois ici plutôt que dupliquée par page,
 * comme ScoreService lui-même l'est déjà côté backend.
 */
export default function NevScoreGauge({ label, score, state = "success", loadingText = "Chargement…", unavailableText = "Non disponible.", errorText = "Une erreur est survenue.", className = "" }) {
  if ("loading" === state) {
    return (
      <NevCard padding="md" className={className}>
        <p className="text-xs font-semibold uppercase tracking-wide text-body-color">{label}</p>
        <p className="mt-3 text-sm text-body-color">{loadingText}</p>
      </NevCard>
    );
  }

  if ("error" === state) {
    return (
      <NevCard padding="md" className={className}>
        <p className="text-xs font-semibold uppercase tracking-wide text-body-color">{label}</p>
        <p className="mt-3 text-sm text-danger">{errorText}</p>
      </NevCard>
    );
  }

  if (!score) {
    return (
      <NevCard padding="md" className={className}>
        <p className="text-xs font-semibold uppercase tracking-wide text-body-color">{label}</p>
        <p className="mt-3 text-sm text-body-color">{unavailableText}</p>
      </NevCard>
    );
  }

  const value = Math.max(0, Math.min(100, score.value));

  return (
    <NevCard padding="md" className={className}>
      <div className="flex items-center justify-between gap-2">
        <p className="text-xs font-semibold uppercase tracking-wide text-body-color">{label}</p>
        <NevBadge tone={GRADE_TONE[score.grade] || "neutral"}>{score.grade}</NevBadge>
      </div>
      <p className="mt-2 text-2xl font-extrabold tabular-nums text-dark">
        {Math.round(value)}
        <span className="text-sm font-semibold text-body-color"> / 100</span>
      </p>
      <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-2">
        <div className={"h-full rounded-full " + (GRADE_BAR[score.grade] || "bg-gray-4")} style={{ width: value + "%" }} />
      </div>
      {score.breakdown && score.breakdown.length > 0 && (
        <ul className="mt-3 space-y-1.5">
          {score.breakdown.map((item) => (
            <li key={item.code} className="flex items-center justify-between gap-2 text-xs text-body-color">
              <span>{item.explanation}</span>
              <span className="shrink-0 font-semibold tabular-nums text-dark-4">{Math.round(100 * item.value)}%</span>
            </li>
          ))}
        </ul>
      )}
      {/* Fiabilisation 2026-10-06 : sous le détail du calcul, jamais avant
          - le chiffre et sa composition restent l'information principale,
          ceci n'est que sa justification pour qui veut la vérifier. */}
      {score.methodologyReferences && score.methodologyReferences.length > 0 && (
        <div className="mt-3 border-t border-gray-2 pt-3">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-body-color">Références méthodologiques</p>
          <ul className="mt-1.5 space-y-1">
            {score.methodologyReferences.map((reference) => (
              <li key={reference.framework} className="text-xs text-body-color">
                {reference.url ? (
                  <a href={reference.url} target="_blank" rel="noopener noreferrer" className="font-medium text-primary-dark hover:underline">
                    {reference.framework}
                  </a>
                ) : (
                  <span className="font-medium text-dark-4">{reference.framework}</span>
                )}
                {reference.note && <span> — {reference.note}</span>}
              </li>
            ))}
          </ul>
        </div>
      )}
    </NevCard>
  );
}
