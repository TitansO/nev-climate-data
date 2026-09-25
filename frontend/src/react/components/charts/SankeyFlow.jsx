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

  const sectorLabelPositionsRef = useRef(null);

  const { data, labels, tightestSectorPair } = useMemo(() => {
    const labelsById = {};
    nodes.forEach((node) => {
      labelsById[node.id] = node.label;
    });
    // Les 2 noeuds "secteur" (derniere colonne) au plus faible volume
    // total sont, en pratique, les plus etroits et les plus rapproches
    // verticalement - c'est systematiquement eux qui se chevauchent
    // ("Renewable Energy" / "Forestry"), jamais les gros secteurs
    // (Agriculture, Adaptation, Sustainable Transport...) qui ont deja
    // assez d'ecart. On ne touche donc qu'a cette paire precise, tous les
    // autres secteurs restent inchanges (centrage par defaut).
    const sectorTotals = new Map();
    links.forEach((link) => {
      if (link.target.startsWith("sector:")) {
        sectorTotals.set(link.target, (sectorTotals.get(link.target) ?? 0) + link.value);
      }
    });
    const smallestFirst = [...sectorTotals.entries()].sort((a, b) => a[1] - b[1]);
    const tightestSectorPair = smallestFirst.length >= 2 ? [smallestFirst[0][0], smallestFirst[1][0]] : null;
    return {
      data: links.map((link) => ({ from: link.source, to: link.target, flow: link.value })),
      labels: labelsById,
      tightestSectorPair,
    };
  }, [nodes, links]);

  useEffect(() => {
    if (!canvasRef.current) {
      return undefined;
    }

    sectorLabelPositionsRef.current = null;

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
              // Seule la paire de secteurs au plus faible volume
              // (tightestSectorPair, calculee ci-dessus a partir des
              // vraies valeurs de flux) est concernee - tout le reste
              // (Agriculture, Adaptation, Sustainable Transport...) garde
              // le centrage par defaut ("auto"), inchange. Pour cette
              // seule paire, on ecarte les 2 etiquettes (haut/bas) au lieu
              // de les laisser toutes deux centrees sur des noeuds trop
              // rapproches - en determinant lequel des 2 est reellement
              // au-dessus via node.y (position verticale finale, deja
              // calculee par la mise en page de la lib au moment du
              // dessin), pas un ordre suppose cote React. Lit
              // volontairement une propriete interne non documentee
              // (controller._nodes) car l'API publique n'expose pas
              // autrement les positions finales des noeuds ; en cas
              // d'indisponibilite (ex. apres une mise a jour de la lib),
              // se degrade simplement vers le centrage par defaut,
              // sans casser le rendu.
              position: (node) => {
                if (!tightestSectorPair || !tightestSectorPair.includes(node.key)) {
                  return "auto";
                }
                if (!sectorLabelPositionsRef.current) {
                  const controller = chartRef.current?.getDatasetMeta(0)?.controller;
                  const sankeyNodes = controller?._nodes ?? null;
                  const [keyA, keyB] = tightestSectorPair;
                  const nodeA = sankeyNodes?.get(keyA);
                  const nodeB = sankeyNodes?.get(keyB);
                  if (!nodeA || !nodeB) {
                    return "auto";
                  }
                  const [topKey, bottomKey] = (nodeA.y ?? 0) <= (nodeB.y ?? 0) ? [keyA, keyB] : [keyB, keyA];
                  sectorLabelPositionsRef.current = { [topKey]: "top", [bottomKey]: "bottom" };
                }
                return sectorLabelPositionsRef.current[node.key] ?? "auto";
              },
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

    // Force un second passage de dessin, synchrone (pas d'animation),
    // maintenant que chartRef.current est bien assigne : si le tout
    // premier dessin (declenche en interne par "new Chart(...)"
    // ci-dessus) s'est produit avant la fin de cette assignation, le
    // callback position() ci-dessus est retombe sur "auto" faute de
    // pouvoir lire chartRef.current. Ce second passage est synchrone et
    // s'execute avant que le navigateur ne peigne quoi que ce soit,
    // donc invisible pour l'utilisateur - seul le resultat final compte.
    chartRef.current.update("none");

    return () => {
      chartRef.current?.destroy();
    };
  }, [data, labels, tightestSectorPair]);

  return (
    <div className="relative h-[480px]">
      <canvas ref={canvasRef}></canvas>
    </div>
  );
}
