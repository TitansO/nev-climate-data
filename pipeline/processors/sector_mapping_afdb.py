"""Maps an AfDB activity's DAC 5-digit sector codes onto one of NEV's
five funding sectors, per the ordered table in
docs/superpowers/specs/2026-08-28-b13-afdb-connector-design.md (decision
8). Returns None - triggering quarantine - when no code matches.
"""
from __future__ import annotations

# Ordered: the first NEV sector (in this list's order) that has ANY
# matching DAC code in the activity wins - same mechanism as B1.2's
# map_gcf_sector, continuing that established policy, but a larger and
# more conservative table: AfDB funds general development (health,
# education, roads, budget support, mining...), not exclusively climate
# projects, so this table must actively exclude codes that sound
# energy/environment-adjacent but aren't specific enough to be safe.
#
# Deliberately excludes 23111 ("Energy sector policy, planning and
# administration" - generic, does not specify renewable vs non-renewable)
# and 23310/23320 (non-renewable/coal power generation - confirmed present
# in AfDB's real portfolio) from Renewable Energy: only technology-specific
# renewable generation codes count. Excludes 41010 ("Environmental policy
# and administrative management", the only "environmental" code present in
# AfDB's real data) from Adaptation - too generic to imply climate
# adaptation specifically. Neither 43060 (Disaster Risk Reduction) nor
# 41030 (Biodiversity) - the two codes B1.2's Adaptation bucket relies on
# for GCF - appear anywhere in AfDB's real portfolio (verified live, 0
# occurrences each): Adaptation is never populated by this connector, a
# real, verified gap, not a bug.
_DAC_SECTOR_RULES: list[tuple[str, frozenset[str]]] = [
    ("Renewable Energy", frozenset({"23220", "23230", "23240", "23250", "23260", "23270", "23280"})),
    ("Sustainable Transport", frozenset({"21012", "21023", "21030", "21040", "21050"})),
    ("Agriculture", frozenset({"31110", "31140", "31163", "31320", "32161"})),
    ("Forestry", frozenset({"31220"})),
]


def map_afdb_sector(sector_codes: list[str]) -> str | None:
    """Returns one of NEV's five sector names, or None if unclassifiable.

    `sector_codes` are OECD DAC CRS 5-digit purpose codes from a
    `nev.funding.raw` AfDB message's `raw_sector_codes` field.
    """
    codes = set(sector_codes)
    for nev_sector, dac_codes in _DAC_SECTOR_RULES:
        if codes & dac_codes:
            return nev_sector
    return None
