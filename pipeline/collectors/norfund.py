"""Norfund (fonds souverain norvegien d'investissement pour les pays en
developpement) collector - financement climat du secteur PRIVE (B1.x,
2026-10-07, demande explicite utilisateur, suite de la recherche menee
pour dfc.py). Telecharge le fichier Excel mensuel reel et public de
Norfund ("Investment Overview"), croise ses 3 feuilles (financement
climat par investissement, pays d'operation avec part ponderee, date
d'engagement par instrument), et publie vers `nev.funding.raw`.

Pourquoi cette source : verifiee en direct le 2026-10-07, elle a un
signal climat encore plus riche que dfc.py - un MONTANT climat chiffre
par investissement (pas seulement un mot-cle), categorise
"Mitigation"/"Adaptation & Resilience"/"Mitigation and A&R" (style Rio
Marker), sur 85 investissements reels couvrant 28 pays africains. Norfund
finance exclusivement le secteur prive par mandat (comme Proparco/FMO/
BII) - aucun flag "Sovereign" ligne par ligne comme DFC, mais la
question ne se pose pas : il n'existe aucune ligne souveraine a exclure.

Important, verifie en direct : la categorie "Mitigation" de Norfund
couvre aussi des banques, fonds et industries qui ne sont elles-memes
aucun des 5 secteurs NEV (ex. "Banco Cuscatlan" - Financial Inclusion/
Banking, "B5 Plus Limited" - fabrication de produits metalliques) - la
classification sectorielle reelle (via map_to_nev_sector(), meme fonction
que World Bank) se fait donc dans funding_validator.py, PAS ici : ce
connecteur transmet les champs Secteur/Sous-secteur/Categorie climat
bruts, exactement comme World Bank transmet raw_sectors/raw_theme -
jamais un "toujours tel secteur" code en dur (contrairement a dfc.py, ou
le filtre du connecteur lui-meme garantissait deja une seule categorie).
"""
from __future__ import annotations

import datetime as dt
import io
from typing import Any, Iterator

import openpyxl
import pycountry
import requests

NORFUND_URL = "https://www.norfund.no/wp-content/uploads/2026/10/Norfunds-Investment-Overview-30.09.26.xlsx"
REQUEST_TIMEOUT_SECONDS = 60

SHEET_INSTRUMENT = "Investments by instrument"
SHEET_COUNTRY = "Investments by country"
SHEET_CLIMATE = "Climate finance share"

# "No Country Distribution" : verifie en direct, une vraie valeur du
# fichier pour un investissement sans repartition geographique - rien de
# reel a quoi l'attribuer, meme principe "jamais une fausse attribution"
# que les autres connecteurs (ex. "Africa Regional" chez DFC).
NON_COUNTRY_VALUES = frozenset({None, "", "No Country Distribution"})


def fetch_norfund_workbook() -> openpyxl.Workbook:
    """Telecharge le fichier Excel mensuel reel et public de Norfund -
    aucune cle API necessaire (verifie en direct 2026-10-07).
    """
    response = requests.get(NORFUND_URL, timeout=REQUEST_TIMEOUT_SECONDS, headers={"User-Agent": "Mozilla/5.0"})
    response.raise_for_status()
    return openpyxl.load_workbook(io.BytesIO(response.content), data_only=True)


def _rows_as_dicts(workbook: openpyxl.Workbook, sheet_name: str) -> Iterator[dict[str, Any]]:
    sheet = workbook[sheet_name]
    headers = [cell.value for cell in sheet[1]]
    for row in sheet.iter_rows(min_row=2, values_only=True):
        yield dict(zip(headers, row))


def extract_climate_finance_by_investee(workbook: openpyxl.Workbook) -> dict[str, tuple[float, str]]:
    """Feuille "Climate finance share" : {investee: (montant_usd_total, categorie)}
    pour les lignes a montant climat reellement non nul. "Climate Finance
    Category" vaut l'entier 0 (jamais une chaine) quand ce n'est pas
    pertinent pour cet investissement - filtre naturellement les non-
    climat sans condition separee.
    """
    result: dict[str, tuple[float, str]] = {}
    for row in _rows_as_dicts(workbook, SHEET_CLIMATE):
        amount = row.get("Climate Finance (USD)")
        category = row.get("Climate Finance Category")
        if amount and isinstance(category, str):
            result[row["Investee"]] = (float(amount), category)
    return result


def extract_earliest_commitment_year_by_investee(workbook: openpyxl.Workbook) -> dict[str, int]:
    """Feuille "Investments by instrument" : annee du plus ancien
    engagement reellement chiffre (montant non nul) par investissement -
    un meme investissement peut avoir plusieurs tranches/instruments sur
    plusieurs annees (verifie en direct : jusqu'a 9 lignes sur 13 ans pour
    un seul investissement reel). Meme principe que
    afdb.py::_afdb_commitment_summary() : la date la plus ancienne d'un
    engagement reel, jamais une date inventee ou la plus recente (qui
    sous-representerait l'anciennete reelle de la relation).
    """
    dates_by_investee: dict[str, list[dt.datetime]] = {}
    for row in _rows_as_dicts(workbook, SHEET_INSTRUMENT):
        amount = row.get("Commitment (USD)")
        date = row.get("Committed Date")
        if amount and date:
            dates_by_investee.setdefault(row["Investee"], []).append(date)
    return {investee: min(dates).year for investee, dates in dates_by_investee.items()}


def fetch_norfund_projects(workbook: openpyxl.Workbook) -> Iterator[dict[str, Any]]:
    """Croise les 3 feuilles et produit une ligne brute par (investissement,
    pays) ayant a la fois un financement climat reel et une part pays
    reelle - le travail d'extraction/jointure, separe de parse_project()
    qui reste une fonction pure testable sur de simples dicts.
    """
    climate_by_investee = extract_climate_finance_by_investee(workbook)
    years_by_investee = extract_earliest_commitment_year_by_investee(workbook)

    for row in _rows_as_dicts(workbook, SHEET_COUNTRY):
        investee = row.get("Investee")
        if investee not in climate_by_investee:
            continue
        climate_usd_total, category = climate_by_investee[investee]
        year = years_by_investee.get(investee)
        if year is None:
            continue
        yield {
            "investee": investee,
            "country": row.get("Country of operations"),
            "country_share": row.get("Country share"),
            "sector": row.get("Sector"),
            "sub_sector": row.get("Sub-Sector"),
            "climate_finance_usd_total": climate_usd_total,
            "climate_finance_category": category,
            "year": year,
        }


def parse_project(raw: dict[str, Any]) -> dict[str, Any] | None:
    """Convertit une ligne brute (deja jointe par fetch_norfund_projects())
    en paiement `nev.funding.raw`, `funding_type: "private"` toujours -
    Norfund finance exclusivement le secteur prive par mandat, aucune
    ligne souveraine a exclure. Le montant climat total de l'investissement
    est pondere par la part reelle de ce pays (`country_share`, verifie
    en direct : une somme de 1.0 sur tous les pays d'un meme
    investissement multi-pays). `raw_sectors`/`raw_theme` transmis bruts
    pour classification par funding_validator.py (meme mecanisme que
    World Bank) - jamais de secteur devine ici.
    """
    country_name = raw.get("country")
    if country_name in NON_COUNTRY_VALUES:
        return None

    country_share = raw.get("country_share")
    if not country_share or float(country_share) <= 0:
        return None

    country_iso = _resolve_country_iso(country_name)
    if country_iso is None:
        return None

    amount_usd = raw["climate_finance_usd_total"] * float(country_share)
    if amount_usd <= 0:
        return None

    sector_text = [value for value in (raw.get("sector"), raw.get("sub_sector")) if value]
    category = raw.get("climate_finance_category")

    return {
        "source": "norfund",
        "project_id": raw["investee"],
        "country_iso": country_iso,
        "year": raw["year"],
        "amount_usd": int(round(amount_usd)),
        "funding_type": "private",
        "raw_sectors": sector_text,
        "raw_theme": [category] if category else [],
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def _resolve_country_iso(country_name: str) -> str | None:
    """Meme raisonnement que les autres connecteurs : correspondance
    exacte d'abord, floue en repli, None si aucune des deux - jamais un
    mauvais pays assigne silencieusement.
    """
    exact = pycountry.countries.get(name=country_name)
    if exact is not None:
        return exact.alpha_3
    try:
        fuzzy = pycountry.countries.search_fuzzy(country_name)
        return fuzzy[0].alpha_3
    except LookupError:
        return None


def collect_and_publish(producer) -> int:
    """Telecharge le fichier mensuel reel de Norfund et publie chaque
    (investissement, pays) exploitable vers `nev.funding.raw` via
    `producer`. Retourne le nombre de messages reellement publies.
    """
    workbook = fetch_norfund_workbook()
    published = 0
    for raw_project in fetch_norfund_projects(workbook):
        payload = parse_project(raw_project)
        if payload is None:
            continue
        producer.send("nev.funding.raw", payload, key=f"{payload['source']}:{payload['project_id']}:{payload['country_iso']}")
        published += 1
    producer.flush()
    return published
