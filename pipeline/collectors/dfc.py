"""U.S. International Development Finance Corporation (DFC) collector -
financement climat du secteur PRIVE (B1.x, 2026-10-06, demande explicite
utilisateur). Telecharge le fichier Excel annuel reel et public de la DFC
("Project Data"), filtre un sous-ensemble conservateur de projets
climat-pertinents authentiquement prives, et publie vers le topic Kafka
`nev.funding.raw`.

Pourquoi cette source et pas une autre : seule source trouvee, apres
verification en direct, avec a la fois un signal public/prive EXPLICITE
(colonne "Sovereign (Yes/No)" - "No" = risque non-souverain, donc
authentiquement prive) ET une description de projet substantielle et
specifique au projet (pas du remplissage de page generique comme
constate en testant IFC, l'autre piste explore et abandonnee le
2026-10-06 : son jeu de donnees n'a aucun marqueur climat fiable, ni au
niveau du jeu de donnees - 11 secteurs trop larges - ni au niveau de
chaque fiche projet individuelle).
"""
from __future__ import annotations

import datetime as dt
import io
from typing import Any, Iterator

import openpyxl
import pycountry
import requests

DFC_PROJECT_DATA_URL = "https://www.dfc.gov/sites/default/files/media/documents/FY24%20DFC%20Annual%20Project%20Data_508.xlsx"
REQUEST_TIMEOUT_SECONDS = 60
SHEET_NAME = "Project Data"
HEADER_ROW = 2

# Verifie en direct le 2026-10-06 sur le fichier reel (1314 projets) :
# secteur NAICS "Utilities" + au moins un de ces mots-cles dans la
# description reelle du projet (pas un libelle de categorie) donne 49
# projets reels, chacun confirme "Sovereign: No" independamment - ex.
# "52 MW expansion of geothermal power plant" (Orpower 4, Kenya),
# "60MW utility-scale solar photovoltaic project" (Boshof Solar, Afrique
# du Sud). Deliberement conservateur : le secteur NAICS seul est trop
# large (englobe des centrales non renouvelables), le mot-cle seul
# attraperait des mentions hors-sujet ailleurs dans le fichier - les deux
# signaux sont exiges ensemble.
CLIMATE_KEYWORDS = ("climate", "renewable", "solar", "wind power", "hydropower", "geothermal", "clean energy")
REQUIRED_NAICS_SECTOR = "Utilities"

# Entrees regionales/multi-pays - rien de reel a quoi les attribuer, meme
# raisonnement "jamais une fausse attribution" que les autres connecteurs
# (ex. OPEC Fund, country_name_to_iso3() renvoie None pour "Africa
# (regional)").
NON_COUNTRY_VALUES = frozenset({"Worldwide", "Africa Regional", "", None})


def fetch_dfc_projects() -> Iterator[dict[str, Any]]:
    """Yields every raw project row du fichier annuel reel "Project Data"
    de la DFC - un seul telechargement, document public, aucune cle API,
    aucune pagination necessaire (verifie en direct 2026-10-06 : 1314
    projets dans le fichier FY24).
    """
    response = requests.get(DFC_PROJECT_DATA_URL, timeout=REQUEST_TIMEOUT_SECONDS, headers={"User-Agent": "Mozilla/5.0"})
    response.raise_for_status()

    workbook = openpyxl.load_workbook(io.BytesIO(response.content), data_only=True)
    sheet = workbook[SHEET_NAME]
    headers = [cell.value for cell in sheet[HEADER_ROW]]

    for row in sheet.iter_rows(min_row=HEADER_ROW + 1, values_only=True):
        yield dict(zip(headers, row))


def parse_project(project: dict[str, Any]) -> dict[str, Any] | None:
    """Convertit une ligne brute DFC en paiement `nev.funding.raw`,
    `funding_type: "private"` toujours - ce connecteur ne publie QUE le
    sous-ensemble conservateur decrit dans le docblock du module.
    Retourne None pour toute ligne hors de ce perimetre volontairement
    etroit, jamais une approximation.
    """
    if (project.get("NAICS Sector") or "") != REQUIRED_NAICS_SECTOR:
        return None

    description = (project.get("Project Description") or "").lower()
    if not any(keyword in description for keyword in CLIMATE_KEYWORDS):
        return None

    if project.get("Sovereign (Yes/No)") != "No":
        return None

    # USD uniquement : ce fichier melange des lignes en USD et en devise
    # locale (ex. KES) sans taux historique fourni - contrairement a
    # AfDB (un seul XDR, un seul taux du jour applique uniformement a
    # toutes les lignes), convertir ici serait une approximation non
    # verifiee. Exclure plutot que deviner - verifie en direct : 1 ligne
    # sur 49 du sous-ensemble climat etait dans ce cas (KES).
    if (project.get("Currency") or "") != "USD":
        return None

    committed = project.get("Committed")
    fiscal_year = project.get("Fiscal Year")
    if not committed or not fiscal_year or float(committed) <= 0:
        return None

    country_name = project.get("Country")
    if country_name in NON_COUNTRY_VALUES:
        return None
    country_iso = _resolve_country_iso(country_name)
    if country_iso is None:
        return None

    project_number = project.get("Project Number")
    if project_number is None:
        return None

    return {
        "source": "dfc",
        "project_id": str(project_number),
        "country_iso": country_iso,
        "year": int(fiscal_year),
        "amount_usd": int(round(float(committed))),
        "funding_type": "private",
        "project_name": project.get("Project Name"),
        "project_description": project.get("Project Description"),
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def _resolve_country_iso(country_name: str) -> str | None:
    """Meme raisonnement que les autres connecteurs (ex. OPEC Fund) :
    correspondance exacte d'abord, floue en repli, None si aucune des
    deux - jamais un mauvais pays assigne silencieusement.
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
    """Telecharge le fichier annuel reel de la DFC et publie chaque ligne
    exploitable (privee, climat-pertinente, voir parse_project()) vers
    `nev.funding.raw` via `producer`. Retourne le nombre de messages
    reellement publies.
    """
    published = 0
    for raw_project in fetch_dfc_projects():
        payload = parse_project(raw_project)
        if payload is None:
            continue
        producer.send("nev.funding.raw", payload, key=f"{payload['source']}:{payload['project_id']}:{payload['country_iso']}")
        published += 1
    producer.flush()
    return published
