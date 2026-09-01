import { useEffect, useRef } from "react";
import Chart from "chart.js/auto";
import { TYPE_COLORS, TYPE_LABELS } from "../../data/analyticsConstants";

/**
 * "Financement par année et type" bar chart - React port of analytics.js's
 * renderFinancingBarChart(). Same options object verbatim; the Chart
 * instance is created/destroyed on every `rows` change via useEffect's
 * cleanup, since Chart.js (unlike React) owns the canvas imperatively and
 * a stale instance left behind would keep listening/redrawing after a
 * re-render or unmount.
 */
export default function FinancingBarChart({ rows }) {
  const canvasRef = useRef(null);
  const chartRef = useRef(null);

  useEffect(() => {
    if (!canvasRef.current) {
      return undefined;
    }

    chartRef.current = new Chart(canvasRef.current, {
      type: "bar",
      data: {
        labels: rows.map((row) => String(row.period)),
        datasets: [
          { label: TYPE_LABELS.public, data: rows.map((r) => r.public), backgroundColor: TYPE_COLORS.public, borderRadius: 4 },
          { label: TYPE_LABELS.private, data: rows.map((r) => r.private), backgroundColor: TYPE_COLORS.private, borderRadius: 4 },
          { label: TYPE_LABELS.multilateral, data: rows.map((r) => r.multilateral), backgroundColor: TYPE_COLORS.multilateral, borderRadius: 4 },
        ],
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
  }, [rows]);

  return (
    <div className="relative h-64">
      <canvas ref={canvasRef}></canvas>
    </div>
  );
}
