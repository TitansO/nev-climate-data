/**
 * Thin styled table wrapper - sticky header, numeric-column alignment,
 * hover row state, breathing row height. Deliberately not a generic
 * sort/filter/virtualize data-grid: replaces DataPage's 9-column table
 * (the one real data table in the app today) without over-abstracting
 * ahead of Volet B's actual future needs.
 *
 * columns: [{ key, label, align: "left"|"right", width?: Tailwind width
 * class (e.g. "w-24"), render?(row) }]
 * rows: array of plain objects; rowKey(row) must return a stable id.
 *
 * `width` (audit Prompt Maitre #3, point 4, 2026-10-13) : under the
 * browser's default table-layout:auto, a short-content column (e.g. a
 * "USD" literal) can still end up stretched to a disproportionate width
 * by the layout algorithm, leaving dead whitespace next to it - found
 * live on DataPage's "Devise" column, visually confirmed before this
 * fix. Setting an explicit width on that <th> constrains the whole
 * column under table-layout:auto; left undefined for every other
 * column so this is opt-in, not a behavior change for existing tables.
 */
export default function NevTable({ columns, rows, rowKey, bordered = true, className = "" }) {
  return (
    <div className={"overflow-x-auto " + (bordered ? "rounded-lg border border-stroke " : "") + className}>
      <table className="w-full min-w-[720px] text-left text-sm">
        <thead className="bg-gray-1 text-xs font-semibold uppercase tracking-wide text-dark-5">
          <tr>
            {columns.map((column) => (
              <th key={column.key} scope="col" className={"px-4 py-3 " + (column.width || "") + " " + ("right" === column.align ? "text-right" : "text-left")}>
                {column.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-stroke bg-white">
          {rows.map((row) => (
            <tr key={rowKey(row)} className="transition hover:bg-gray-1/70">
              {columns.map((column) => (
                <td key={column.key} className={"px-4 py-3.5 text-dark-3 " + ("right" === column.align ? "text-right tabular-nums" : "")}>
                  {column.render ? column.render(row) : row[column.key]}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
