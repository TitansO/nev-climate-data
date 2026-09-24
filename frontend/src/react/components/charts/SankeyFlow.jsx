import { useEffect, useMemo, useRef } from "react";
import { Chart } from "chart.js";
import { SankeyController, Flow } from "chartjs-chart-sankey";
import { formatUsd, SANKEY_LAYER_COLORS } from "../../data/analyticsConstants";

Chart.register(SankeyController, Flow);

function colorForNode(nodeId) {
  if (nodeId.endsWith(":other")) {
    return SANKEY_LAYER_COLORS.other;
  }
  return SANKEY_LAYER_COLORS[nodeId.split(":")[0]] || SANKEY_LAYER_COLORS.other;
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
            nodeWidth: 14,
            // Colonne "secteur" (3e colonne) : beaucoup de noeuds a faible
            // valeur, empiles avec un tres petit ecart - leurs etiquettes
            // (ex: "Renewable Energy" / "Forestry") se chevauchent avec
            // l'espacement par defaut (10px). On augmente l'espacement
            // uniquement pour cette colonne (prefixe "sector:") sans
            // toucher aux colonnes bailleurs/pays, qui n'ont pas ce
            // probleme.
            nodePadding: (node) => (node.key.startsWith("sector:") ? 28 : 10),
            // Par defaut, chartjs-chart-sankey dessine les etiquettes de
            // noeud sans fond, directement par-dessus les flux en degrade -
            // illisible des que le texte tombe sur une zone coloree ou
            // sombre. Un fond blanc translucide derriere chaque etiquette
            // (nodeLabels.backgroundColor) resout ca quel que soit ce qu'il
            // y a en-dessous, et un texte plus grand/gras (14px, 600) reste
            // net a la taille d'affichage reelle du graphe.
            color: "#0f172a",
            font: { size: 13, weight: "600", family: "'Inter', sans-serif" },
            nodeLabels: {
              color: "#0f172a",
              font: { size: 14, weight: "600", family: "'Inter', sans-serif" },
              backgroundColor: "rgba(255, 255, 255, 0.92)",
              padding: 6,
              borderRadius: 4,
            },
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
    <div className="relative h-[480px]">
      <canvas ref={canvasRef}></canvas>
    </div>
  );
}
