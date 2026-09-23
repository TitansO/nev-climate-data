import { useEffect, useRef } from "react";
import Chart from "chart.js/auto";
import { formatCompactUsd, PRIMARY_COLOR } from "../../data/analyticsConstants";

/**
 * C1.6 : rendu graphique de `financing-trends.movingAverageTotal` (moyenne
 * mobile 3 ans, total tous groupes confondus) - remplace la liste de texte
 * brute (illisible sur ~60 ans de données) sans la retirer : la liste reste
 * en dessous pour qui veut les valeurs exactes point par point.
 */
export default function MovingAverageChart({ rows }) {
  const canvasRef = useRef(null);
  const chartRef = useRef(null);

  useEffect(() => {
    if (!canvasRef.current) {
      return undefined;
    }

    chartRef.current = new Chart(canvasRef.current, {
      type: "line",
      data: {
        labels: rows.map((row) => String(row.period)),
        datasets: [
          {
            label: "Moyenne mobile 3 ans (USD)",
            data: rows.map((row) => row.value),
            borderColor: PRIMARY_COLOR,
            backgroundColor: "rgba(22,163,74,0.12)",
            fill: true,
            tension: 0.25,
            pointRadius: 0,
            pointHoverRadius: 4,
            borderWidth: 2,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (ctx) => formatCompactUsd(ctx.parsed.y) } },
        },
        scales: {
          y: { beginAtZero: true, grid: { color: "rgba(5,46,28,0.06)" }, ticks: { callback: (value) => formatCompactUsd(value) } },
          x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } },
        },
      },
    });

    return () => {
      chartRef.current?.destroy();
    };
  }, [rows]);

  return (
    <div className="relative h-56">
      <canvas ref={canvasRef}></canvas>
    </div>
  );
}
