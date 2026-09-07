import NevModal from "./ui/NevModal";
import NevButton from "./ui/NevButton";

/**
 * Report detail modal (new, from the "Climate NEV" template integration -
 * the mockup's card-click-to-detail pattern, wired to the real report
 * object already in memory in ReportsPage, no extra fetch). Built on
 * NevModal's generic shell; `badge` is a pre-rendered <NevBadge> node so
 * this component doesn't duplicate ReportsPage's TYPE_META mapping.
 */
export default function ReportDetailModal({ report, badge, formattedDate, apiBaseUrl, t, open, onClose }) {
  if (!report) {
    return null;
  }

  return (
    <NevModal open={open} onClose={onClose} titleId="nev-report-modal-title" maxWidth="max-w-lg">
      <div className="flex items-start justify-between gap-3">
        {badge}
        <button
          type="button"
          onClick={onClose}
          aria-label={t("reportsPage.close", "Fermer")}
          className="rounded-md p-1 text-dark-5 transition hover:bg-gray-2 hover:text-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <h2 id="nev-report-modal-title" className="mb-3 mt-2 text-lg font-bold leading-snug text-dark">
        {report.title}
      </h2>
      <dl className="mb-5 space-y-1.5 text-sm text-dark-5">
        {formattedDate && (
          <div className="flex justify-between gap-3">
            <dt>{t("reportsPage.date", "Date")}</dt>
            <dd className="font-medium text-dark-3">{formattedDate}</dd>
          </div>
        )}
        {(report.country || report.region) && (
          <div className="flex justify-between gap-3">
            <dt>{t("dataPage.country", "Pays")}</dt>
            <dd className="font-medium text-dark-3">{report.country ? report.country.name : report.region}</dd>
          </div>
        )}
        <div className="flex justify-between gap-3">
          <dt>{t("reportsPage.downloads", "Téléchargements")}</dt>
          <dd className="font-medium tabular-nums text-dark-3">{report.downloadCount}</dd>
        </div>
      </dl>
      <NevButton as="a" href={apiBaseUrl + report.downloadUrl} variant="primary" className="w-full">
        {t("reportsPage.view", "Consulter")}
      </NevButton>
    </NevModal>
  );
}
