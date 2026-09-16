import { useEffect, useMemo, useRef } from "react";
import { Chart } from "chart.js";
import { SankeyController, Flow } from "chartjs-chart-sankey";
import { formatUsd } from "../../data/analyticsConstants";

Chart.register(SankeyController, Flow);

// One color per layer (bailleur/pays/secteur), independent of the fixed
// SECTOR_COLORS/INSTRUMENT_COLORS palettes used elsewhere on this page -
// here color encodes *position in the flow* (which hop a node belongs to),
// not what the node itself represents. Every "Autres X" bucket, whichever
// layer it's in, shares one muted gray so it always reads as "aggregated",
// never mistaken for a real distinguishable entity.
const LAYER_COLORS = { source: "#16a34a", country: "#c98a2c", sector: "#2563eb", other: "#9aa5a0" };

function colorForNode(nodeId) {
  if (nodeId.endsWith(":other")) {
    return LAYER_COLORS.other;
  }
  return LAYER_COLORS[nodeId.split(":")[0]] || LAYER_COLORS.other;
}

/**
 * C1.5 : "Opportunity Flow" Sankey - bailleur -> pays -> secteur, à partir
 * de GET /api/analytics/funding-flows ({nodes, links}, voir
 * FundingFlowService::getFlows() côté backend). chartjs-chart-sankey attend
 * une forme différente ({from, to, flow} par lien + une map id->label
 * partagée) - la traduction se fait ici, jamais côté backend qui n'a pas à
 * connaître la forme d'une lib de rendu.
 */
export default function SankeyFlow({ nodes, links }) {
  const canvasRef = useRef(null);
  const chartRef = useRef(null);

  const { data, labels } = useMemo(() => {
    const labelsById = {};
    nodes.forEach((node) => {
      labelsById[node.id] = node.label;
    });
    return {
      data: links.map((link) => ({ from: link.source, to: link.target, flow: link.value })),
      labels: labelsById,
    };
  }, [nodes, links]);

  useEffect(() => {
    if (!canvasRef.current) {
      return undefined;
    }

    chartRef.current = new Chart(canvasRef.current, {
      type: "sankey",
      data: {
        datasets: [
          {
            label: "Flux de financement",
            data,
            labels,
            colorFrom: (ctx) => colorForNode(data[ctx.dataIndex].from),
            colorTo: (ctx) => colorForNode(data[ctx.dataIndex].to),
            colorMode: "gradient",
            borderWidth: 0,
            nodeWidth: 12,
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
              label(tooltipItem) {
                const point = tooltipItem.raw;
                return (labels[point.from] || point.from) + " → " + (labels[point.to] || point.to) + " : " + formatUsd(point.flow);
              },
            },
          },
        },
      },
    });

    return () => {
      chartRef.current?.destroy();
    };
  }, [data, labels]);

  return (
    <div className="relative h-[420px]">
      <canvas ref={canvasRef}></canvas>
    </div>
  );
}
