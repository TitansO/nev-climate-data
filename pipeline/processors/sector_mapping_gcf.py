"""Maps a GCF activity's DAC 5-digit sector codes onto one of NEV's five
funding sectors, per the ordered table in
docs/superpowers/specs/2026-08-28-b12-gcf-connector-design.md (decision 7).
Returns None - triggering quarantine - when no code matches.
"""
from __future__ import annotations

# Ordered: the first NEV sector (in this list's order) that has ANY
# matching DAC code in the activity wins - `sector_percentage` is
# deliberately ignored. Verified against the complete real GCF portfolio
# (350 activities): a dominant-sector-only rule (picking whichever DAC code
# has the highest percentage) would wrongly quarantine 96/350 activities
# where a non-mappable code like Social Protection (16010/16050 - GCF's two
# most frequent codes, never mapped here) happens to have a higher
# percentage than a real climate-sector code also present on the same
# activity. First-match-wins classifies 299/350 (85%) vs 203/350 (58%) for
# dominant-only.
#
# Agriculture never appears: no DAC code in GCF's real portfolio maps to
# it - not a bug, this source simply never contributes Agriculture rows.
# 41030 (Biodiversity) -> Adaptation is a judgement call (ecosystem-based
# adaptation), confirmed with Serge rather than assumed.
_DAC_SECTOR_RULES: list[tuple[str, frozenset[str]]] = [
    ("Renewable Energy", frozenset({"23210", "23183"})),
    ("Sustainable Transport", frozenset({"21010"})),
    ("Forestry", frozenset({"31210"})),
    ("Adaptation", frozenset({"43060", "41030"})),
]


def map_gcf_sector(sector_codes: list[str], sector_percentages: list[float]) -> str | None:
    """Returns one of NEV's five sector names, or None if unclassifiable.

    `sector_codes` are OECD DAC CRS 5-digit purpose codes from a
    `nev.funding.raw` GCF message's `raw_sector_codes` field.
    `sector_percentages` is accepted for interface symmetry with the raw
    IATI payload (`raw_sector_percentages`) but is not used in the matching
    logic itself - see the module docstring above.
    """
    codes = set(sector_codes)
    for nev_sector, dac_codes in _DAC_SECTOR_RULES:
        if codes & dac_codes:
            return nev_sector
    return None
