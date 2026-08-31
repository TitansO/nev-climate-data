"""Maps an OPEC Fund Climate Finance Report row's raw sector label (and,
for the ambiguous "Energy" label, its project name) onto one of NEV's
five funding sectors, per the table in
docs/superpowers/specs/2026-08-29-b15-pdf-extractor-design.md (decision 8).
Returns None - triggering quarantine - when no rule matches. Deliberately
conservative: most of this report's real label strings are left
unmapped rather than guessed - a real, expected quarantine volume, same
"don't guess" discipline as every earlier connector's own gaps.
"""
from __future__ import annotations

_UNAMBIGUOUS_LABELS: dict[str, str] = {
    "Transport": "Sustainable Transport",
    "Agriculture": "Agriculture",
    "Agriculture and Livelihoods": "Agriculture",
    "Agriculture/ Agricultural Development": "Agriculture",
    # Real precedent: AfDB's DAC code 31320 ("Fishery development") already
    # maps to Agriculture in this project (B1.3 spec decision 8).
    "Fishing": "Agriculture",
}

# The report's own methodology explicitly allows "transitional"
# fossil-fuel-adjacent activities as partial mitigation finance (Annex 1),
# so a bare "Energy" sector label never implies renewable on its own - the
# project NAME (real, unaltered source text) is required as a second
# signal. Mirrors B1.1's already-approved keyword-based project-text
# matching (World Bank connector), not a new mechanism.
_ENERGY_LABELS = frozenset({
    "Energy",
    "Energy Generation",
    "Energy Generation; Distribution And Efficiency",
    "Energy generation, Distribution and Efficiency",
})
_RENEWABLE_KEYWORDS = ("Wind", "Solar", "Hydro", "Hydroelectric", "Geothermal")


def map_opec_sector(sector_label: str, project_name: str) -> str | None:
    if sector_label in _UNAMBIGUOUS_LABELS:
        return _UNAMBIGUOUS_LABELS[sector_label]

    if sector_label in _ENERGY_LABELS:
        if any(keyword in project_name for keyword in _RENEWABLE_KEYWORDS):
            return "Renewable Energy"
        return None

    return None
