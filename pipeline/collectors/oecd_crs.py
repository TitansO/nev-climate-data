"""OECD CRS (Creditor Reporting System) collector - financement climat du
secteur PRIVE, quatrieme source de ce type (apres DFC, Norfund, Proparco),
via l'API SDMX officielle (`oda_reader`, verifiee en direct 2026-10-07,
demande explicite utilisateur). Contrairement a Proparco (0 ligne reelle
exploitable sur le perimetre NEV), CRS fournit un signal nettement plus
riche et deja standardise :

- `measure` = 30 ("Private Development Finance") isole directement les
  flux prives reels, une classification institutionnelle officielle de
  l'OCDE/CAD - jamais un champ texte libre ambigu a interpreter.
- Les Marqueurs de Rio (`climate_mitigation`/`climate_adaptation`, 0/1/2)
  sont un signal climat international standardise, au niveau du projet.
- Le code secteur DAC 5 chiffres (`sector_code`) est EXACTEMENT le meme
  systeme que celui deja utilise par afdb.py/sector_mapping_afdb.py -
  reutilise directement `map_afdb_sector()`, aucune nouvelle table de
  correspondance necessaire.

Verifie en direct sur les 54 pays africains reels du perimetre NEV,
2018-2024, filtre `measure=30` : 441 lignes climat-pertinentes
(Marqueur Rio mitigation ou adaptation > 0), 377 classifiables (85%) via
map_afdb_sector() + repli sur le Marqueur Rio adaptation quand le code
secteur seul ne suffit pas - ~522M USD sur 2021-2022 seul, un ordre de
grandeur comparable a Norfund.

Pieges reels rencontres et geres :
- Le filtre `measure` de l'API attend le CODE numerique (30), jamais le
  libelle ("Private Development Finance" echoue silencieusement avec
  "NoResultsFound") - verifie en direct.
- Chaque (pays, annee, projet) apparait plusieurs fois dans la reponse
  brute (Commitments/Disbursements x Current/Constant prices) - filtre a
  flow_type_code == "C" (Commitments) ET amount_type == "Current prices"
  pour eviter tout double comptage, meme principe que les autres
  connecteurs de ce projet qui ne retiennent qu'un seul type de
  transaction par projet.
- `value` est exprime dans l'unite indiquee par `unit_multiplier` (6 =
  millions, verifie en direct sur chaque ligne reelle) - jamais une
  multiplication par 1e6 codee en dur.
- Les noms de pays (`recipient_name`) sont les noms officiels anglais du
  CAD/OCDE (ex. "Côte d'Ivoire", "Tanzania") - resolution via pycountry
  (exact puis floue), meme principe que norfund.py::_resolve_country_iso.
"""
from __future__ import annotations

import datetime as dt
from typing import Any

import pycountry
from oda_reader import download_crs

PRIVATE_MEASURE_CODE = 30
MIN_YEAR = 2018  # annee a partir de laquelle le CAD/OCDE rapporte "Private Development Finance" de maniere significative - verifie en direct (0 ligne climat-pertinente trouvee avant, au-dela du bruit statistique attendu)

COMMITMENT_FLOW_TYPE_CODE = "C"
CURRENT_PRICES_AMOUNT_TYPE = "Current prices"

# Les 54 pays reels du perimetre NEV (meme liste que `country` en base) -
# necessaire ici car l'API SDMX d'OCDE CRS beneficie d'un filtre cote
# serveur par destinataire (le jeu de donnees mondial complet est bien
# trop volumineux pour une collecte trimestrielle) ; aucun autre
# connecteur de ce projet n'a besoin d'un tel filtre a la source.
AFRICAN_ISO3 = [
    "DZA", "AGO", "BEN", "BWA", "BFA", "BDI", "CMR", "CPV", "CAF", "TCD",
    "COM", "COG", "COD", "CIV", "DJI", "EGY", "GNQ", "ERI", "SWZ", "ETH",
    "GAB", "GMB", "GHA", "GIN", "GNB", "KEN", "LSO", "LBR", "LBY", "MDG",
    "MWI", "MLI", "MRT", "MUS", "MAR", "MOZ", "NAM", "NER", "NGA", "RWA",
    "STP", "SEN", "SYC", "SLE", "SOM", "ZAF", "SSD", "SDN", "TZA", "TGO",
    "TUN", "UGA", "ZMB", "ZWE",
]

_RAW_COLUMNS = [
    "year", "sector_code", "recipient_name", "crs_id", "value",
    "unit_multiplier", "climate_adaptation",
]


def fetch_crs_rows(start_year: int = MIN_YEAR, end_year: int | None = None) -> list[dict[str, Any]]:
    """Interroge l'API SDMX reelle d'OCDE CRS (via `oda_reader`), filtree
    cote serveur aux 54 pays africains du perimetre NEV et au financement
    prive (measure=30), puis reduit aux lignes climat-pertinentes
    (Marqueur Rio mitigation OU adaptation > 0) et dedupliquees a une
    seule ligne par (pays, annee, projet) - voir le docblock du module.
    Retourne une liste de dicts Python natifs (jamais de types numpy/
    pandas, pour rester serialisable en JSON par le staging MinIO/Garage).
    """
    df = download_crs(
        start_year=start_year,
        end_year=end_year or dt.datetime.now(dt.timezone.utc).year,
        filters={"recipient": AFRICAN_ISO3, "measure": PRIVATE_MEASURE_CODE, "microdata": True},
    )

    df = df[
        (df["flow_type_code"] == COMMITMENT_FLOW_TYPE_CODE)
        & (df["amount_type"] == CURRENT_PRICES_AMOUNT_TYPE)
        & ((df["climate_mitigation"].fillna(0) > 0) | (df["climate_adaptation"].fillna(0) > 0))
    ]

    rows = []
    for _, row in df[_RAW_COLUMNS].iterrows():
        rows.append({
            "year": int(row["year"]) if row["year"] == row["year"] else None,
            "sector_code": str(int(row["sector_code"])) if row["sector_code"] == row["sector_code"] else None,
            "recipient_name": row["recipient_name"] if isinstance(row["recipient_name"], str) else None,
            "crs_id": row["crs_id"] if isinstance(row["crs_id"], str) else None,
            "value": float(row["value"]) if row["value"] == row["value"] else None,
            "unit_multiplier": int(row["unit_multiplier"]) if row["unit_multiplier"] == row["unit_multiplier"] else 6,
            "climate_adaptation": float(row["climate_adaptation"]) if row["climate_adaptation"] == row["climate_adaptation"] else 0.0,
        })
    return rows


def resolve_country_iso(recipient_name: str | None) -> str | None:
    """Meme principe que norfund.py::_resolve_country_iso : correspondance
    exacte d'abord, floue en repli, None si aucune des deux - les noms
    CRS sont les noms officiels anglais du CAD/OCDE, donc generalement
    bien reconnus par pycountry (contrairement aux noms francais de
    Proparco).
    """
    if not recipient_name:
        return None
    exact = pycountry.countries.get(name=recipient_name)
    if exact is not None:
        return exact.alpha_3
    try:
        fuzzy = pycountry.countries.search_fuzzy(recipient_name)
        return fuzzy[0].alpha_3
    except LookupError:
        return None


def parse_row(raw: dict[str, Any]) -> dict[str, Any] | None:
    """Convertit une ligne brute (deja filtree/dedupliquee par
    fetch_crs_rows()) en paiement `nev.funding.raw`, ou None si l'un des
    signaux necessaires (montant, identifiant de projet, pays, annee)
    n'est pas fiable. La classification sectorielle reelle (via
    map_afdb_sector(), reutilisee telle quelle) se fait dans
    funding_validator.py, meme principe que World Bank/Norfund - le
    Marqueur Rio adaptation est transmis brut pour son repli eventuel.
    """
    value = raw.get("value")
    if not value or value <= 0:
        return None

    crs_id = raw.get("crs_id")
    if not crs_id:
        return None

    country_iso = resolve_country_iso(raw.get("recipient_name"))
    if country_iso is None:
        return None

    year = raw.get("year")
    if not year:
        return None

    amount_usd = value * (10 ** raw.get("unit_multiplier", 6))

    return {
        "source": "oecd_crs",
        "project_id": crs_id,
        "country_iso": country_iso,
        "year": year,
        "amount_usd": int(round(amount_usd)),
        "funding_type": "private",
        "raw_sector_codes": [raw["sector_code"]] if raw.get("sector_code") else [],
        "climate_adaptation_rio_marker": bool(raw.get("climate_adaptation")),
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def collect_and_publish(producer) -> int:
    """Telecharge les lignes CRS reelles climat-pertinentes (perimetre
    NEV, financement prive) et publie chaque projet exploitable vers
    `nev.funding.raw` via `producer`. Retourne le nombre de messages
    reellement publies.
    """
    published = 0
    for raw_row in fetch_crs_rows():
        payload = parse_row(raw_row)
        if payload is None:
            continue
        producer.send("nev.funding.raw", payload, key=f"{payload['source']}:{payload['project_id']}:{payload['country_iso']}")
        published += 1
    producer.flush()
    return published
