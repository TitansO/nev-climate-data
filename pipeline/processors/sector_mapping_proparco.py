"""Maps a Proparco project's raw (French-language) sector label onto one
of NEV's five funding sectors. Dedicated module, not a reuse of
sector_mapping.py's English keyword table - Proparco's real
`secteur_s_concerne_s_par_le_projet` values are entirely in French
("Energies renouvelables", "Agriculture et agro-industrie"...), verified
live 2026-10-07 (same per-source pattern as sector_mapping_afdb.py/
_gcf.py/_opec.py).

Deliberately conservative, same discipline as sector_mapping_opec.py: a
bare "Energie" label is never assumed renewable (Proparco also finances
non-renewable energy infrastructure), and there is no adaptation-theme
fallback here (unlike sector_mapping.py's World Bank rules) - Proparco's
own adaptation/mitigation split field was found, during this connector's
live verification, to disagree with itself on ~24 of its ~184
climate-flagged rows (ranges, "ND", mismatched values), too unreliable to
use as a signal at all. A project with no clear sector-keyword match is
quarantined rather than guessed into a theme-based bucket.
"""
from __future__ import annotations

import unicodedata

_SECTOR_RULES: list[tuple[str, list[str]]] = [
    ("Renewable Energy", ["renouvelable", "solaire", "eolien", "hydroelectri", "geothermi"]),
    ("Sustainable Transport", ["transport"]),
    ("Agriculture", ["agricultur", "agro-industrie", "agroindustrie", "agroforesterie"]),
    ("Forestry", ["forestier", "foret", "reboisement"]),
]


def _strip_accents(text: str) -> str:
    normalized = unicodedata.normalize("NFKD", text)
    return "".join(ch for ch in normalized if not unicodedata.combining(ch))


def map_proparco_sector(sector_label: str | None) -> str | None:
    if not sector_label:
        return None

    haystack = _strip_accents(sector_label).casefold()
    for nev_sector, keywords in _SECTOR_RULES:
        if any(keyword in haystack for keyword in keywords):
            return nev_sector

    return None
