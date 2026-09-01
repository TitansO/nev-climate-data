import { formatUsd } from "../../data/analyticsConstants";

/** Detail table under the financing-by-year bar chart - React port of analytics.js's renderFinancingTable(). */
export default function FinancingTable({ rows, totals }) {
  return (
    <div className="mt-6 overflow-x-auto">
      <table className="w-full min-w-[560px] text-left text-sm">
        <thead className="border-b border-stroke text-xs font-semibold uppercase tracking-wide text-dark-4">
          <tr>
            <th className="py-3 pr-4">Année</th>
            <th className="py-3 pr-4 text-right">Public</th>
            <th className="py-3 pr-4 text-right">Privé</th>
            <th className="py-3 pr-4 text-right">Multilatéral</th>
            <th className="py-3 pl-4 text-right">Total</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-stroke">
          {rows.map((row) => (
            <tr key={row.period} className="hover:bg-surface-alt">
              <td className="py-3 pr-4 font-semibold text-dark">{row.period}</td>
              <td className="py-3 pr-4 text-right tabular-nums">{formatUsd(row.public)}</td>
              <td className="py-3 pr-4 text-right tabular-nums">{formatUsd(row.private)}</td>
              <td className="py-3 pr-4 text-right tabular-nums">{formatUsd(row.multilateral)}</td>
              <td className="py-3 pl-4 text-right font-semibold tabular-nums text-dark">{formatUsd(row.total)}</td>
            </tr>
          ))}
          <tr className="border-t-2 border-stroke bg-surface-alt font-bold text-dark">
            <td className="py-3 pr-4">Total</td>
            <td className="py-3 pr-4 text-right tabular-nums">{formatUsd(totals.public)}</td>
            <td className="py-3 pr-4 text-right tabular-nums">{formatUsd(totals.private)}</td>
            <td className="py-3 pr-4 text-right tabular-nums">{formatUsd(totals.multilateral)}</td>
            <td className="py-3 pl-4 text-right tabular-nums">{formatUsd(totals.grandTotal)}</td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}
