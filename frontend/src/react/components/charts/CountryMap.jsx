import { useEffect, useRef } from "react";
import "jsvectormap/dist/jsvectormap.min.css";
import { AFRICA_ISO_ALPHA3_TO_ALPHA2, AFRICA_FOCUS_ALPHA2, MAP_COLOR_RAMP_LIGHT_TO_DARK, formatUsd, formatCompactUsd } from "../../data/analyticsConstants";

/**
 * "Financement par pays (Afrique)" map - React port of analytics.js's
 * loadCountryMap() rendering half (the fetch itself lives in the page via
 * useAnalyticsFetch). Renders with jsVectorMap on its bundled "world" map
 * dataset. jsVectorMap only supports a discrete/ordinal color scale, so
 * countries are bucketed into up to 5 real quantile tiers by funding
 * amount (darkest = highest); the legend shows each tier's real amount
 * range, computed from the same data.
 *
 * jsVectorMap ships as a UMD bundle expecting a `window.jsVectorMap`
 * global for its `dist/maps/world.js` side-effect file to attach the
 * bundled map data to - loaded here via sequential dynamic import()s
 * (module evaluation order for *static* imports is not guaranteed to
 * match source order, so the global must be set between two awaited
 * dynamic imports, not two static ones) rather than the CDN <script> tags
 * visualizations.html used to carry.
 */
export default function CountryMap({ rows }) {
  const containerRef = useRef(null);

  useEffect(() => {
    let cancelled = false;
    let mapInstance = null;

    (async () => {
      const { default: JsVectorMap } = await import("jsvectormap");
      if ("undefined" !== typeof window && !window.jsVectorMap) {
        window.jsVectorMap = JsVectorMap;
      }
      await import("jsvectormap/dist/maps/world.js");
      if (cancelled || !containerRef.current) {
        return;
      }

      const tierCount = Math.min(MAP_COLOR_RAMP_LIGHT_TO_DARK.length, rows.length);
      const bucketSize = Math.ceil(rows.length / tierCount);
      // rows is already sorted highest amount first - the darkest color
      // always goes to the top bucket. Slicing from the *end* of the
      // light->dark ramp keeps that true even with fewer than 5 tiers.
      const tiers = MAP_COLOR_RAMP_LIGHT_TO_DARK.slice(MAP_COLOR_RAMP_LIGHT_TO_DARK.length - tierCount)
        .reverse()
        .map((color, i) => ({ key: "tier" + i, color, rows: [] }));
      rows.forEach((row, i) => {
        tiers[Math.min(tierCount - 1, Math.floor(i / bucketSize))].rows.push(row);
      });

      const scale = {};
      const values = {};
      const infoByAlpha2 = {};
      let skippedUnmapped = 0;

      tiers.forEach((tier) => {
        if (0 === tier.rows.length) {
          return;
        }
        scale[tier.key] = tier.color;
        tier.rows.forEach((row) => {
          const alpha2 = AFRICA_ISO_ALPHA3_TO_ALPHA2[row.isoCode];
          if (!alpha2) {
            skippedUnmapped += 1;
            return;
          }
          values[alpha2] = tier.key;
          infoByAlpha2[alpha2] = row;
        });
      });

      containerRef.current.innerHTML = "";
      mapInstance = new JsVectorMap({
        selector: containerRef.current,
        map: "world",
        backgroundColor: "transparent",
        zoomButtons: false,
        zoomOnScroll: false,
        regionsSelectable: false,
        regionStyle: {
          initial: { fill: "#e5e7eb", stroke: "#ffffff", strokeWidth: 0.5 },
          hover: { fill: "#9ca3af", cursor: "default" },
        },
        series: {
          regions: [{ attribute: "fill", scale, values }],
        },
        onRegionTooltipShow: (event, tooltip, code) => {
          const info = infoByAlpha2[code];
          if (info) {
            tooltip.text(info.country + " : " + formatUsd(info.amount) + " (" + info.percentage + "%)", false);
          }
        },
        onLoaded: (instance) => {
          instance.setFocus({ regions: AFRICA_FOCUS_ALPHA2, animate: false });
          // setFocus() fits the continent to the container, but the world
          // map is one continuous SVG - other continents stay visible at
          // the edges. Zooming in further, centered on the point
          // setFocus() just computed, pushes the rest of the world out of
          // view without re-deriving the fit math ourselves ("_setScale" -
          // leading underscore is the library's own internal export name
          // for this one, unlike the public "setFocus").
          instance._setScale(instance.scale * 1.6, instance.transX, instance.transY, true, false);
        },
      });

      if (skippedUnmapped > 0) {
        console.warn(skippedUnmapped + " country row(s) from /api/analytics/country-distribution had no alpha-2 mapping and were omitted from the map.");
      }
    })();

    return () => {
      cancelled = true;
      if (mapInstance && containerRef.current) {
        containerRef.current.innerHTML = "";
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rows]);

  return (
    <div>
      <div ref={containerRef} className="mx-auto h-[420px] w-full lg:h-[640px] lg:max-w-xl"></div>
      <MapLegend rows={rows} />
    </div>
  );
}

function MapLegend({ rows }) {
  const tierCount = Math.min(MAP_COLOR_RAMP_LIGHT_TO_DARK.length, rows.length);
  const bucketSize = Math.ceil(rows.length / tierCount);
  const tiers = MAP_COLOR_RAMP_LIGHT_TO_DARK.slice(MAP_COLOR_RAMP_LIGHT_TO_DARK.length - tierCount)
    .reverse()
    .map((color, i) => ({ key: "tier" + i, color, rows: [] }));
  rows.forEach((row, i) => {
    tiers[Math.min(tierCount - 1, Math.floor(i / bucketSize))].rows.push(row);
  });

  return (
    <div className="mt-4 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-xs text-dark-4">
      {tiers
        .filter((tier) => tier.rows.length > 0)
        .map((tier) => {
          const lowest = tier.rows[tier.rows.length - 1].amount;
          const highest = tier.rows[0].amount;
          const range = lowest === highest ? formatCompactUsd(lowest) : formatCompactUsd(lowest) + " – " + formatCompactUsd(highest);
          return (
            <span key={tier.key} className="inline-flex items-center gap-1.5">
              <span className="h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: tier.color }}></span>
              {range}
            </span>
          );
        })}
    </div>
  );
}
