"""Maps a World Bank project's raw sector/theme strings onto one of NEV's
five funding sectors, per the ordered rule table in
docs/superpowers/specs/2026-08-26-b11-world-bank-connector-design.md
(decision 4). Returns None - triggering quarantine, per decision 5 - when
nothing matches or no sector data was supplied at all.
"""
from __future__ import annotations

# Ordered: first match wins. Adaptation is checked last and only against
# `raw_theme` (not sector names) - it's a cross-cutting World Bank theme,
# not one of its major sectors, and a project that is e.g. both "Energy
# Generation - Solar" and thematically "Adaptation" should land in
# Renewable Energy, its more specific classification.
_SECTOR_RULES: list[tuple[str, list[str]]] = [
    ("Renewable Energy", ["energy generation", "renewable", "solar", "wind", "hydropower"]),
    ("Sustainable Transport", ["transport", "roads", "urban mobility"]),
    # "agricultur" (stem, not "agriculture") - matches both "Agriculture" and
    # "Agricultural Extension"/"Agricultural Research" etc.; "agriculture" as a
    # literal substring does not match "agricultural" (diverges after
    # "agricultur": "-e" vs "-al"), confirmed by a real failing test.
    ("Agriculture", ["agricultur", "rural development", "irrigation"]),
    ("Forestry", ["forest"]),
]

_ADAPTATION_KEYWORDS = ["adaptation"]


def map_to_nev_sector(raw_sectors: list[str], raw_theme: list[str]) -> str | None:
    """Returns one of NEV's five sector names, or None if unclassifiable.

    `raw_sectors` and `raw_theme` are the flattened sector-name list and
    theme list from a `nev.funding.raw` Kafka message - see the B1.1
    spec's payload shape.
    """
    haystack_sectors = " | ".join(raw_sectors).lower()
    for nev_sector, keywords in _SECTOR_RULES:
        if any(keyword in haystack_sectors for keyword in keywords):
            return nev_sector

    haystack_theme = " | ".join(raw_theme).lower()
    for keyword in _ADAPTATION_KEYWORDS:
        if keyword in haystack_theme:
            return "Adaptation"

    return None
