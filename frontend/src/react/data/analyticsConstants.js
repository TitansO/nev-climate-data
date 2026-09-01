/**
 * Shared constants for visualizations.html's charts - ported verbatim from
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

export const SECTOR_COLORS = ["#16a34a", "#22c55e", "#86efac", "#14532d", "#0b3d24"];

// Same 3 colors used consistently across every chart on this page that
// breaks financing down by type (the bar chart and the donut) - so a color
// always means the same type no matter which chart it's in.
export const TYPE_COLORS = { public: "#16a34a", private: "#052e1c", multilateral: "#2563eb" };
export const TYPE_LABELS = { public: "Public", private: "Privé", multilateral: "Multilatéral" };

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
