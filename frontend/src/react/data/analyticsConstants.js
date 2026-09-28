/**
 * Shared constants for dashboard.html's charts (2026-09-28: absorbed
 * visualizations.html, now retired) - ported verbatim from
 * assets/js/analytics.js (same values, same comments where the reasoning
 * still applies) so every chart keeps meaning the same thing by the same
 * color it always has.
 */
export const SECTOR_LABELS = {
  "Renewable Energy": "Énergies renouvelables",
  "Sustainable Transport": "Transport durable",
  Agriculture: "Agriculture",
  Forestry: "Foresterie",
  Adaptation: "Adaptation",
};

// Audit pre-production 2026-09-23 (P3) : cette plateforme n'a qu'une
// seule couleur de marque (le meme "#16a34a" que SECTOR_COLORS[0]/
// TYPE_COLORS.public/INSTRUMENT_COLORS.grant ci-dessous), mais elle
// etait encore recopiee en dur dans MovingAverageChart.jsx et
// SankeyFlow.jsx plutot que reference d'ici - le genre de duplication
// qui finit par diverger silencieusement si la teinte change un jour.
export const PRIMARY_COLOR = "#16a34a";

export const SECTOR_COLORS = [PRIMARY_COLOR, "#22c55e", "#86efac", "#14532d", "#0b3d24"];

// Same 3 colors used consistently across every chart on this page that
// breaks financing down by type (the bar chart and the donut) - so a color
// always means the same type no matter which chart it's in.
export const TYPE_COLORS = { public: PRIMARY_COLOR, private: "#052e1c", multilateral: "#2563eb" };
export const TYPE_LABELS = { public: "Public", private: "Privé", multilateral: "Multilatéral" };

/**
 * C1.2: labels for the CountryMap layer selector's "Instrument" filter -
 * App\Entity\Enum\FundingInstrument's values verbatim (backend is the
 * source of truth; this is display text only, never sent as-is to the API).
 */
export const INSTRUMENT_LABELS = {
  grant: "Don",
  loan_concessional: "Prêt concessionnel",
  loan_non_concessional: "Prêt non concessionnel",
  guarantee: "Garantie",
  equity: "Prise de participation",
  results_based: "Financement basé sur les résultats",
  unknown: "Inconnu",
};

/** C1.7: one color per FundingInstrument case, same palette family as SECTOR_COLORS (this platform's green scale) plus two distinct hues for the two loan variants so they read apart on a donut. */
export const INSTRUMENT_COLORS = {
  grant: PRIMARY_COLOR,
  loan_concessional: "#2563eb",
  loan_non_concessional: "#1d4ed8",
  guarantee: "#0b3d24",
  equity: "#86efac",
  results_based: "#22c55e",
  unknown: "#9ca3af",
};

// One color per Sankey layer (bailleur/pays/secteur), independent of the
// fixed SECTOR_COLORS/INSTRUMENT_COLORS palettes above - here color
// encodes *position in the flow* (which hop a node belongs to), not what
// the node itself represents. Every "Autres X" bucket, whichever layer
// it's in, shares one muted gray so it always reads as "aggregated",
// never mistaken for a real distinguishable entity. Moved here from
// SankeyFlow.jsx (audit pre-production 2026-09-23, P3) so its "source"
// entry stays in sync with PRIMARY_COLOR instead of repeating the literal.
export const SANKEY_LAYER_COLORS = { source: PRIMARY_COLOR, country: "#c98a2c", sector: "#2563eb", other: "#9aa5a0" };

/**
 * ISO 3166-1 alpha-3 -> alpha-2, the 54 African countries seeded by
 * backend/src/DataFixtures/CountryFixtures.php (same list as
 * backend/src/Reference/AfricanCurrencies.php::COUNTRY_CURRENCY).
 * /api/analytics/country-distribution reports alpha-3, but jsVectorMap's
 * bundled "world" map keys every region by alpha-2 - this is the real,
 * standard ISO 3166-1 conversion table for exactly those 54 countries.
 */
export const AFRICA_ISO_ALPHA3_TO_ALPHA2 = {
  DZA: "DZ", EGY: "EG", LBY: "LY", MAR: "MA", SDN: "SD", TUN: "TN",
  BEN: "BJ", BFA: "BF", CPV: "CV", CIV: "CI", GMB: "GM", GHA: "GH", GIN: "GN", GNB: "GW", LBR: "LR", MLI: "ML", MRT: "MR", NER: "NE", NGA: "NG", SEN: "SN", SLE: "SL", TGO: "TG",
  AGO: "AO", CMR: "CM", CAF: "CF", TCD: "TD", COG: "CG", COD: "CD", GNQ: "GQ", GAB: "GA", STP: "ST",
  BDI: "BI", COM: "KM", DJI: "DJ", ERI: "ER", ETH: "ET", KEN: "KE", MDG: "MG", MWI: "MW", MUS: "MU", MOZ: "MZ", RWA: "RW", SYC: "SC", SOM: "SO", SSD: "SS", TZA: "TZ", UGA: "UG", ZMB: "ZM", ZWE: "ZW",
  BWA: "BW", SWZ: "SZ", LSO: "LS", NAM: "NA", ZAF: "ZA",
};

/**
 * setFocus({regions}) fits the map's zoom to the bounding box of every code
 * passed in. Far-offshore island nations (Cabo Verde, Seychelles, Mauritius,
 * Comoros, São Tomé) stretch that bounding box far past continental Africa,
 * so they're excluded from the *framing* only - they stay fully colored/
 * interactive on the map (AFRICA_ISO_ALPHA3_TO_ALPHA2 above is untouched).
 */
export const AFRICA_FOCUS_ALPHA2 = Object.entries(AFRICA_ISO_ALPHA3_TO_ALPHA2)
  .filter(([alpha3]) => !["CPV", "STP", "SYC", "MUS", "COM"].includes(alpha3))
  .map(([, alpha2]) => alpha2);

// Light -> dark, all 5 already defined in src/input.css's @theme (accent,
// primary-light, primary, primary-dark, deep-3) - same palette as every
// other chart on this page.
export const MAP_COLOR_RAMP_LIGHT_TO_DARK = ["#86efac", "#22c55e", "#16a34a", "#15803d", "#14532d"];

export function formatCompactUsd(value) {
  return new Intl.NumberFormat("fr-FR", { notation: "compact", maximumFractionDigits: 1 }).format(value) + " USD";
}

export function formatUsd(value) {
  return Math.round(value).toLocaleString("fr-FR") + " USD";
}

export function formatCount(value) {
  return Number(value).toLocaleString("fr-FR");
}
