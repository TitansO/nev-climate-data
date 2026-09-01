import { useEffect, useRef } from "react";
import Chart from "chart.js/auto";
import { TYPE_COLORS, TYPE_LABELS, formatUsd } from "../../data/analyticsConstants";

/**
 * "Répartition par type de financement" gauge-style donut - React port of
 * analytics.js's renderTypeDonut(). The center label surfaces the
 * majority financing type and its real share of the grand total (there is
 * no "target" figure anywhere in this schema, so the honest equivalent is
 * "what actually dominates today") - same computation, same wording.
 */
export default function TypeDonutChart({ totals }) {
  const canvasRef = useRef(null);
  const chartRef = useRef(null);

  const majorityKey = ["public", "private", "multilateral"].reduce((best, key) => (totals[key] > totals[best] ? key : best), "public");
  const majorityShare = totals.grandTotal > 0 ? Math.round((totals[majorityKey] / totals.grandTotal) * 100) : 0;

  useEffect(() => {
    if (!canvasRef.current) {
      return undefined;
    }

    chartRef.current = new Chart(canvasRef.current, {
      type: "doughnut",
      data: {
        labels: [TYPE_LABELS.public, TYPE_LABELS.private, TYPE_LABELS.multilateral],
        datasets: [
          {
            data: [totals.public, totals.private, totals.multilateral],
            backgroundColor: [TYPE_COLORS.public, TYPE_COLORS.private, TYPE_COLORS.multilateral],
            borderWidth: 0,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: "72%",
        plugins: {
          legend: { position: "bottom" },
          tooltip: {
            callbacks: {
              label: (ctx) => {
                const share = totals.grandTotal > 0 ? Math.round((ctx.parsed / totals.grandTotal) * 1000) / 10 : 0;
                return ctx.label + " : " + share + "% (" + formatUsd(ctx.parsed) + ")";
              },
            },
          },
        },
      },
    });

    return () => {
      chartRef.current?.destroy();
    };
  }, [totals]);

  return (
    <div className="relative h-56">
      <canvas ref={canvasRef}></canvas>
      <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
        <span className="text-3xl font-extrabold text-dark">{majorityShare}%</span>
        <span className="text-xs font-medium uppercase tracking-wide text-dark-5">{TYPE_LABELS[majorityKey]}</span>
      </div>
    </div>
  );
}
