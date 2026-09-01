import { useEffect, useRef } from "react";
import Chart from "chart.js/auto";
import { SECTOR_LABELS, SECTOR_COLORS, formatUsd } from "../../data/analyticsConstants";

/**
 * "Répartition par secteur" donut + ranked bar list - React port of
 * analytics.js's loadSectorDistribution() rendering half (the fetch
 * itself lives in the page via useAnalyticsFetch). Same real payload
 * powers both: the donut (proportions at a glance) and the ranking list
 * next to it (precise ranking + amount).
 */
export default function SectorDonutChart({ rows }) {
  const canvasRef = useRef(null);
  const chartRef = useRef(null);

  useEffect(() => {
    if (!canvasRef.current) {
      return undefined;
    }

    chartRef.current = new Chart(canvasRef.current, {
      type: "doughnut",
      data: {
        labels: rows.map((row) => SECTOR_LABELS[row.sector] || row.sector),
        datasets: [
          {
            data: rows.map((r) => r.percentage),
            backgroundColor: rows.map((_row, i) => SECTOR_COLORS[i % SECTOR_COLORS.length]),
            borderWidth: 0,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (ctx) => {
                const row = rows[ctx.dataIndex];
                return ctx.label + " : " + row.percentage + "% (" + formatUsd(row.amount) + ")";
              },
            },
          },
        },
      },
    });

    return () => {
      chartRef.current?.destroy();
    };
  }, [rows]);

  return (
    <div className="grid grid-cols-1 items-center gap-4 sm:grid-cols-2">
      <div className="relative h-56">
        <canvas ref={canvasRef}></canvas>
      </div>
      <div className="space-y-3">
        {rows.map((row, i) => {
          const label = SECTOR_LABELS[row.sector] || row.sector;
          const color = SECTOR_COLORS[i % SECTOR_COLORS.length];
          return (
            <div key={row.sector}>
              <div className="mb-1 flex items-center justify-between text-sm">
                <span className="flex items-center gap-2 font-medium text-dark">
                  <span className="h-2 w-2 rounded-full" style={{ backgroundColor: color }}></span>
                  {label}
                </span>
                <span className="font-semibold text-dark-4">{row.percentage}%</span>
              </div>
              <div className="h-1.5 w-full overflow-hidden rounded-full bg-surface-alt">
                <div className="h-full rounded-full" style={{ width: row.percentage + "%", backgroundColor: color }}></div>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
