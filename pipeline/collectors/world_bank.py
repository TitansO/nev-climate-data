"""World Bank Projects & Operations API collector for climate-themed
financing (B1.1). Fetches, paginates, and publishes raw payloads to
Kafka topic `nev.funding.raw` - see the B1.1 spec's payload shape.
"""
from __future__ import annotations

import datetime as dt
import re
from typing import Any, Iterator

import pycountry
import requests

PROJECTS_API_URL = "https://search.worldbank.org/api/v3/projects"
PAGE_SIZE = 100
REQUEST_TIMEOUT_SECONDS = 30

FIELDS = ",".join([
    "id", "countryname", "countrycode", "totalamt", "boardapprovaldate",
    "status", "major_sectors", "theme",
    # borrower/lendprojectcost : ajoutes 2026-10-06 (demande explicite
    # utilisateur, donnees Public/Privee) pour
    # parse_government_cofinancing() ci-dessous - absents avant ca,
    # jamais utilises par parse_project().
    "borrower", "lendprojectcost",
])

# 2026-10-06 : reconnait un emprunteur explicitement gouvernemental -
# verifie en direct sur de vrais projets (ex. "Ministère de l'Économie,
# des Finances et du Plan" pour un projet senegalais ou lendprojectcost
# (50 M USD) depassait largement totalamt (7,42 M USD), l'ecart
# correspondant a la contrepartie du gouvernement emprunteur nomme dans
# `borrower`). Volontairement etroit : un emprunteur qui ne correspond a
# aucun de ces motifs ne produit aucune ligne "public" plutot qu'une
# supposition.
GOVERNMENT_BORROWER_PATTERN = re.compile(
    r"minist|government|gouvernement|republic|r[ée]publique|treasury|tr[ée]sor",
    re.IGNORECASE,
)


def fetch_projects_for_country(country_iso: str) -> Iterator[dict[str, Any]]:
    """Yields every raw project record for `country_iso` matching the
    Climate change theme, paginating through the full result set (a
    single country can have hundreds of matching projects - verified 264
    for Senegal alone during the B1.1 design work, well above one page).
    """
    offset = 0
    while True:
        response = requests.get(
            PROJECTS_API_URL,
            params={
                "format": "json",
                "countrycode_exact": country_iso,
                "mjtheme": "Climate change",
                "fl": FIELDS,
                "rows": PAGE_SIZE,
                "os": offset,
            },
            timeout=REQUEST_TIMEOUT_SECONDS,
        )
        response.raise_for_status()
        payload = response.json()

        projects = payload.get("projects", {})
        if not projects:
            return

        yield from projects.values()

        offset += PAGE_SIZE
        if offset >= int(payload.get("total", 0)):
            return


def parse_project(project: dict[str, Any]) -> dict[str, Any] | None:
    """Converts one raw World Bank project record into the `nev.funding.raw`
    payload shape. Returns None for a project with no usable financing
    amount or approval date - such a project (e.g. very early "Pipeline"
    status, verified live to lack these fields) has nothing to publish.
    """
    total_amount = project.get("totalamt")
    approval_date = project.get("boardapprovaldate")
    # `totalamt` is not always an integer string - confirmed live during Task 9's end-to-end
    # run, which crashed on a real record with totalamt="1439873.8" (int() rejects the decimal
    # point; float() handles both integer and decimal strings). Truncates to whole USD, which
    # is what amount_usd represents throughout this connector.
    if not total_amount or not approval_date or float(total_amount) <= 0:
        return None
    total_amount_usd = int(float(total_amount))

    raw_sectors = [
        entry["major_sector"]["major_sector_name"]
        for entry in project.get("major_sectors", [])
        if "major_sector" in entry
    ]
    raw_theme = [theme.strip() for theme in project.get("theme", "").split(",") if theme.strip()]

    country_codes = project.get("countrycode") or [""]
    alpha2 = country_codes[0]
    country = pycountry.countries.get(alpha_2=alpha2)
    # Falls back to the raw alpha-2 code if pycountry doesn't recognize it -
    # that value will simply never match a `Country.isoCode` (all 3 letters)
    # downstream, so the record is quarantined as unknown_country rather
    # than silently mis-mapped. Not expected in practice: every World Bank
    # member country has a valid ISO 3166-1 alpha-2 code.
    country_iso = country.alpha_3 if country is not None else alpha2

    return {
        "source": "world_bank",
        "project_id": project["id"],
        "country_iso": country_iso,
        "year": int(approval_date[:4]),
        "amount_usd": total_amount_usd,
        "funding_type": "multilateral",
        "raw_sectors": raw_sectors,
        "raw_theme": raw_theme,
        "board_approval_date": approval_date[:10],
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def parse_government_cofinancing(project: dict[str, Any]) -> dict[str, Any] | None:
    """Convertit la contrepartie gouvernementale d'un projet Banque
    Mondiale (si identifiable) en un paiement `nev.funding.raw` distinct,
    `funding_type: "public"` - en plus, jamais a la place, du paiement
    "multilateral" que parse_project() produit deja pour l'engagement
    propre de la Banque Mondiale (IDA/IBRD/don). Le signal : `lendprojectcost`
    (cout total du projet) depasse `totalamt` (engagement propre de la
    Banque Mondiale) - l'ecart est la part financee par d'autres sources,
    presumee gouvernementale UNIQUEMENT quand `borrower` nomme clairement
    un gouvernement (GOVERNMENT_BORROWER_PATTERN) - les prets IBRD/IDA
    sont par construction accordes a l'Etat emprunteur, jamais a un tiers
    prive, donc cet ecart est sa contrepartie budgetaire propre, pas un
    co-financement d'un autre bailleur. Retourne None si l'ecart est nul/
    negatif ou si `borrower` ne correspond a aucun motif gouvernemental -
    jamais une repartition devinee.
    """
    total_amount = project.get("totalamt")
    lend_project_cost = project.get("lendprojectcost")
    approval_date = project.get("boardapprovaldate")
    borrower = project.get("borrower") or ""

    if not total_amount or not lend_project_cost or not approval_date:
        return None
    if not GOVERNMENT_BORROWER_PATTERN.search(borrower):
        return None

    gap_usd = float(lend_project_cost) - float(total_amount)
    if gap_usd <= 0:
        return None

    country_codes = project.get("countrycode") or [""]
    alpha2 = country_codes[0]
    country = pycountry.countries.get(alpha_2=alpha2)
    country_iso = country.alpha_3 if country is not None else alpha2

    return {
        "source": "world_bank",
        # Suffixe distinct du project_id "multilateral" du meme projet -
        # deux lignes `funding` reelles et distinctes pour un meme projet
        # cofinance, jamais une cle Kafka en collision avec celle de
        # parse_project() pour le meme id.
        "project_id": project["id"] + ":government-cofinancing",
        "country_iso": country_iso,
        "year": int(approval_date[:4]),
        "amount_usd": int(round(gap_usd)),
        "funding_type": "public",
        "borrower": borrower,
        "board_approval_date": approval_date[:10],
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def collect_and_publish(country_isos: list[str], producer) -> int:
    """Fetches climate-themed World Bank projects for every country in
    `country_isos` and publishes each parseable one to `nev.funding.raw`
    via `producer` (a `kafka.KafkaProducer`, e.g. from
    `pipeline.common.kafka_client.make_producer()`). Returns the number of
    messages actually published.

    Audit 2026-10-01 : jamais appele par le DAG de production
    (collecte_worldbank.py, qui a ete refactore en taches extraire/
    transformer/publier separees via un staging MinIO bronze/silver) - garde
    volontairement comme point d'entree unique fetch->parse->publier pour
    les tests (voir test_world_bank_collector.py) et un usage manuel
    ponctuel depuis un shell Python, pas du code mort oublie.
    """
    published = 0
    for country_iso in country_isos:
        for raw_project in fetch_projects_for_country(country_iso):
            payloads = [
                parse_project(raw_project),
                # 2026-10-06 : contrepartie gouvernementale, en plus de
                # l'engagement Banque Mondiale lui-meme - voir
                # parse_government_cofinancing().
                parse_government_cofinancing(raw_project),
            ]
            for payload in payloads:
                if payload is None:
                    continue
                producer.send("nev.funding.raw", payload, key=f"{payload['source']}:{payload['project_id']}:{payload['country_iso']}")
                published += 1
    producer.flush()
    return published
