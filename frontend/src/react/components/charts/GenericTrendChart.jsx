import { useEffect, useMemo, useRef } from "react";
import Chart from "chart.js/auto";
import { SECTOR_LABELS, SECTOR_COLORS, TYPE_COLORS, TYPE_LABELS, INSTRUMENT_LABELS, INSTRUMENT_COLORS } from "../../data/analyticsConstants";

// A rotating palette for dimensions with no fixed color mapping of their
// own (chiefly "country" - up to 54 series, far more than any of this
// platform's other fixed palettes cover) - distinct, evenly-spaced hues
// rather than a short list repeating and becoming ambiguous past a
// handful of series.
function fallbackColor(index) {
  return "hsl(" + ((index * 47) % 360) + ", 65%, 45%)";
}

function labelFor(groupBy, key) {
  if ("sector" === groupBy) {
    return SECTOR_LABELS[key] || key;
  }
  if ("instrument" === groupBy) {
    return INSTRUMENT_LABELS[key] || key;
  }
  if ("fundingType" === groupBy) {
    return TYPE_LABELS[key] || key;
  }
  // "country": the API reports the ISO-3 code directly (no per-row country
  // name in this aggregate) - shown as-is, same as FilterableCountryMap's
  // own map legend.
  return key;
}

function colorFor(groupBy, key, index) {
  if ("sector" === groupBy) {
    return SECTOR_COLORS[index % SECTOR_COLORS.length];
  }
  if ("instrument" === groupBy) {
    return INSTRUMENT_COLORS[key] || fallbackColor(index);
  }
  if ("fundingType" === groupBy) {
    return TYPE_COLORS[key] || fallbackColor(index);
  }
  return fallbackColor(index);
}

/**
 * C1.6 : "Financement par année et type (USD)" généralisé - contrepartie
 * générique de FinancingBarChart (qui reste dédié à la forme par défaut,
 * non-groupée, avec ses 3 séries fixes public/private/multilateral) pour
 * les 4 ventilations de GET /api/analytics/financing-trends?groupBy=.
 * `rows` : forme `{period, groups: {<clé>: montant}, total}` - une série
 * Chart.js par clé réellement présente dans au moins une ligne (pas une
 * série par valeur théorique de l'énumération : un instrument jamais vu
 * dans les données ne doit pas apparaître dans la légende).
 */
export default function GenericTrendChart({ rows, groupBy }) {
  const canvasRef = useRef(null);
  const chartRef = useRef(null);

  const seriesKeys = useMemo(() => {
    const keys = new Set();
    rows.forEach((row) => Object.keys(row.groups).forEach((key) => keys.add(key)));
    return Array.from(keys).sort();
  }, [rows]);

  useEffect(() => {
    if (!canvasRef.current) {
      return undefined;
    }

    chartRef.current = new Chart(canvasRef.current, {
      type: "bar",
      data: {
        labels: rows.map((row) => String(row.period)),
        datasets: seriesKeys.map((key, index) => ({
          label: labelFor(groupBy, key),
          data: rows.map((row) => row.groups[key] || 0),
          backgroundColor: colorFor(groupBy, key, index),
          borderRadius: 4,
        })),
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: "bottom" } },
        scales: {
          y: { beginAtZero: true, grid: { color: "rgba(5,46,28,0.06)" }, title: { display: true, text: "USD" } },
          x: { grid: { display: false } },
        },
      },
    });

    return () => {
      chartRef.current?.destroy();
    };
  }, [rows, seriesKeys, groupBy]);

  return (
    <div className="relative h-64">
      <canvas ref={canvasRef}></canvas>
    </div>
  );
}
