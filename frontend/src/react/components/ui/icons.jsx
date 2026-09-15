/**
 * Small set of line icons replacing the emoji used in hero pill badges,
 * homepage category cards, sources cards, and the global-search category
 * labels - emoji reads casual on an institutional data platform, and
 * mixing several OS/browser emoji renderers looks inconsistent across
 * devices. Based on Heroicons (MIT license, by the Tailwind CSS team -
 * https://heroicons.com) - simplified/redrawn by hand to this project's
 * existing inline-SVG convention (24x24 viewBox, stroke-based, no new
 * npm dependency), not the npm package. No attribution required (MIT).
 */
const base = "h-full w-full";
const strokeProps = { fill: "none", viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 1.75, strokeLinecap: "round", strokeLinejoin: "round" };

export function GlobeIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18M12 3c2.5 2.5 3.75 5.5 3.75 9S14.5 18.5 12 21c-2.5-2.5-3.75-5.5-3.75-9S9.5 5.5 12 3z" />
    </svg>
  );
}

export function BanknoteIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <rect x="2.5" y="6" width="19" height="12" rx="2" />
      <circle cx="12" cy="12" r="3" />
      <path d="M6 9v.01M18 15v.01" />
    </svg>
  );
}

export function ChartBarIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />
    </svg>
  );
}

export function DocumentIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <path d="M6 3h8l5 5v13a1 1 0 01-1 1H6a1 1 0 01-1-1V4a1 1 0 011-1z" />
      <path d="M14 3v5h5M9 13h6M9 17h6" />
    </svg>
  );
}

export function LinkIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <path d="M9.5 14.5l5-5M8 17H6a4 4 0 010-8h2M16 7h2a4 4 0 010 8h-2" />
    </svg>
  );
}

export function InfoIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 11v5.5M12 8v.01" />
    </svg>
  );
}

export function GearIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <circle cx="12" cy="12" r="3" />
      <path d="M19.4 13.5a1.7 1.7 0 000-3l1.2-1.9-1.9-1.9-1.9 1.2a1.7 1.7 0 00-3 0L12.6 4.1h-2.7L8.6 5.9a1.7 1.7 0 00-3 0L3.7 4.7 1.8 6.6l1.2 1.9a1.7 1.7 0 000 3L1.8 13.4l1.9 1.9 1.9-1.2a1.7 1.7 0 003 0l1.3 2.3h2.7l1.3-2.3a1.7 1.7 0 003 0l1.9 1.2 1.9-1.9-1.2-1.9z" />
    </svg>
  );
}

export function BellIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <path d="M15 17h5l-1.4-1.4a2 2 0 01-.6-1.4V11a6 6 0 00-4-5.7V5a2 2 0 10-4 0v.3A6 6 0 006 11v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
    </svg>
  );
}

export function UserIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <circle cx="12" cy="8" r="3.5" />
      <path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6" />
    </svg>
  );
}

export function UsersIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <circle cx="9" cy="8" r="3" />
      <path d="M3 20c0-3 2.5-5.5 6-5.5s6 2.5 6 5.5" />
      <path d="M16 8.5a2.5 2.5 0 110-5M16.5 14.5c2.5.3 4.5 2.3 4.5 5.5" />
    </svg>
  );
}

export function KeyIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <circle cx="8" cy="15" r="4" />
      <path d="M11 12l8-8M16 7l2 2M13 10l2 2" />
    </svg>
  );
}

export function BoltIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z" />
    </svg>
  );
}

export function BuildingIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <path d="M4 21V7l8-4 8 4v14M4 21h16M9 21v-6h6v6" />
    </svg>
  );
}

export function SparklesIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <path d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5L12 3z" />
      <path d="M19 15l.7 2.1L22 18l-2.3.9L19 21l-.7-2.1L16 18l2.3-.9L19 15z" />
    </svg>
  );
}

export function SearchIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <path d="M21 21l-4.35-4.35M18 11a7 7 0 11-14 0 7 7 0 0114 0z" />
    </svg>
  );
}

export function DatabaseIcon(props) {
  return (
    <svg className={base} {...strokeProps} {...props}>
      <ellipse cx="12" cy="6" rx="8" ry="3" />
      <path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3" />
    </svg>
  );
}
