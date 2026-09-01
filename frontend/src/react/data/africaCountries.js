/**
 * Same 54-country, 5-region grouping as the static &lt;optgroup&gt; list
 * data.html used to hard-code - ported verbatim, not re-derived, so the
 * filter dropdown's exact labels/order/values don't drift from what was
 * already shipped and translated.
 */
export const COUNTRY_GROUPS = [
  {
    label: "Afrique du Nord",
    options: [
      { value: "DZA", label: "Algeria" },
      { value: "EGY", label: "Egypt" },
      { value: "LBY", label: "Libya" },
      { value: "MAR", label: "Morocco" },
      { value: "SDN", label: "Sudan" },
      { value: "TUN", label: "Tunisia" },
    ],
  },
  {
    label: "Afrique de l'Ouest",
    options: [
      { value: "BEN", label: "Benin" },
      { value: "BFA", label: "Burkina Faso" },
      { value: "CPV", label: "Cabo Verde" },
      { value: "CIV", label: "Côte d'Ivoire" },
      { value: "GMB", label: "Gambia" },
      { value: "GHA", label: "Ghana" },
      { value: "GIN", label: "Guinea" },
      { value: "GNB", label: "Guinea-Bissau" },
      { value: "LBR", label: "Liberia" },
      { value: "MLI", label: "Mali" },
      { value: "MRT", label: "Mauritania" },
      { value: "NER", label: "Niger" },
      { value: "NGA", label: "Nigeria" },
      { value: "SEN", label: "Senegal" },
      { value: "SLE", label: "Sierra Leone" },
      { value: "TGO", label: "Togo" },
    ],
  },
  {
    label: "Afrique centrale",
    options: [
      { value: "AGO", label: "Angola" },
      { value: "CMR", label: "Cameroon" },
      { value: "CAF", label: "Central African Republic" },
      { value: "TCD", label: "Chad" },
      { value: "COG", label: "Republic of the Congo" },
      { value: "COD", label: "Democratic Republic of the Congo" },
      { value: "GNQ", label: "Equatorial Guinea" },
      { value: "GAB", label: "Gabon" },
      { value: "STP", label: "São Tomé and Príncipe" },
    ],
  },
  {
    label: "Afrique de l'Est",
    options: [
      { value: "BDI", label: "Burundi" },
      { value: "COM", label: "Comoros" },
      { value: "DJI", label: "Djibouti" },
      { value: "ERI", label: "Eritrea" },
      { value: "ETH", label: "Ethiopia" },
      { value: "KEN", label: "Kenya" },
      { value: "MDG", label: "Madagascar" },
      { value: "MWI", label: "Malawi" },
      { value: "MUS", label: "Mauritius" },
      { value: "MOZ", label: "Mozambique" },
      { value: "RWA", label: "Rwanda" },
      { value: "SYC", label: "Seychelles" },
      { value: "SOM", label: "Somalia" },
      { value: "SSD", label: "South Sudan" },
      { value: "TZA", label: "Tanzania" },
      { value: "UGA", label: "Uganda" },
      { value: "ZMB", label: "Zambia" },
      { value: "ZWE", label: "Zimbabwe" },
    ],
  },
  {
    label: "Afrique australe",
    options: [
      { value: "BWA", label: "Botswana" },
      { value: "SWZ", label: "Eswatini" },
      { value: "LSO", label: "Lesotho" },
      { value: "NAM", label: "Namibia" },
      { value: "ZAF", label: "South Africa" },
    ],
  },
];
