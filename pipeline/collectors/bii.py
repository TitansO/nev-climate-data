"""BII (British International Investment, ex-CDC Group) collector -
financement climat PRIVE reel, cinquieme source de ce type (apres DFC,
Norfund, Proparco, OECD CRS), via l'IATI Datastore - verifie en direct
2026-10-08, demande explicite utilisateur, suite de la recherche menee
pour trouver des sources alternatives apres AFD.

BII est le bras d'investissement du Royaume-Uni (ex-CDC Group,
rebaptise - l'ancien nom "CDC Group" ne retourne plus aucune activite
reelle sur l'IATI Datastore, confirme en direct) - par mandat
institutionnel, 100% secteur prive (investisseur dans des entreprises
privees, jamais de pret souverain), meme raisonnement que Norfund/DFC
deja integres. `funding_type` toujours "private".

Portefeuille reel plus simple que AfDB/AFD : une seule page (616
activites au total, 203 avec un destinataire africain reel), toutes en
USD natif (pas de conversion de devise necessaire), et un seul type de
transaction reellement present (Commitment "2" - aucune ligne de
Disbursement observee, donc aucun risque de double comptage a filtrer).
Codes secteur CRS CAD/OCDE a 5 chiffres (vocabulaire IATI "1", meme
systeme que AfDB/AFD/OECD CRS) - reutilise map_afdb_sector() telle
quelle dans funding_validator.py, aucune nouvelle table de
correspondance.
"""
from __future__ import annotations

import datetime as dt
import os
from typing import Any, Iterator

import pycountry
import requests

IATI_DATASTORE_URL = "https://api.iatistandard.org/datastore/activity/select"
BII_REPORTING_ORG_REF = "GB-COH-03877777"
REQUEST_TIMEOUT_SECONDS = 30

FIELDS = ",".join([
    "iati_identifier", "recipient_country_code", "sector_code", "sector_vocabulary",
    "transaction_transaction_type_code", "transaction_value",
    "transaction_transaction_date_iso_date",
])

COMMITMENT_TRANSACTION_TYPE = "2"
DAC_SECTOR_VOCABULARY = "1"
# Meme plancher et meme raisonnement que afdb.py/afd.py (Sommet de la
# Terre de Rio/CCNUCC, 1992) - un choix produit assume, applique ici
# pour la meme cause reelle.
MIN_PLAUSIBLE_COMMITMENT_YEAR = 1992


def fetch_bii_activities() -> Iterator[dict[str, Any]]:
    """Genere chaque activite brute reelle de BII depuis l'IATI
    Datastore (616 activites au 2026-10-08, verifie en direct - une
    seule page, pas de pagination necessaire contrairement a afdb.py/
    afd.py).
    """
    response = requests.get(
        IATI_DATASTORE_URL,
        headers={"Ocp-Apim-Subscription-Key": os.environ["IATI_API_KEY"]},
        params={
            "q": f'reporting_org_ref:"{BII_REPORTING_ORG_REF}"',
            "rows": 1000,
            "wt": "json",
            "fl": FIELDS,
        },
        timeout=REQUEST_TIMEOUT_SECONDS,
    )
    response.raise_for_status()
    payload = response.json()
    yield from payload["response"]["docs"]


def _dac_sector_codes(activity: dict[str, Any]) -> list[str]:
    """Meme principe que afd.py::_dac_sector_codes - filtre aux seules
    entrees en vocabulaire IATI "1" (codes CRS CAD/OCDE a 5 chiffres).
    """
    codes = activity.get("sector_code", [])
    vocabularies = activity.get("sector_vocabulary", [])
    return [code for code, vocab in zip(codes, vocabularies) if vocab == DAC_SECTOR_VOCABULARY]


def _bii_commitment_summary(activity: dict[str, Any]) -> tuple[float, int, str] | None:
    """Retourne (montant_total_usd, annee, date_engagement_la_plus_ancienne)
    en sommant chaque transaction de type "2" (Outgoing Commitment) -
    meme principe que afd.py::_afd_commitment_summary, sans conversion
    de devise (BII rapporte nativement en USD, verifie en direct sur les
    203 activites africaines reelles). Retourne None si aucune
    transaction ne correspond.
    """
    types = activity.get("transaction_transaction_type_code", []) or []
    values = activity.get("transaction_value", []) or []
    dates = activity.get("transaction_transaction_date_iso_date", []) or []
    n = min(len(types), len(values), len(dates))

    total = 0.0
    matched_dates = []
    for i in range(n):
        if types[i] == COMMITMENT_TRANSACTION_TYPE and int(dates[i][:4]) >= MIN_PLAUSIBLE_COMMITMENT_YEAR:
            total += values[i]
            matched_dates.append(dates[i])

    if not matched_dates:
        return None

    matched_dates.sort()
    earliest_date = matched_dates[0][:10]
    return total, int(earliest_date[:4]), earliest_date


def parse_activity(activity: dict[str, Any]) -> dict[str, Any] | None:
    """Convertit une activite brute reelle de BII en paiement
    `nev.funding.raw`, ou None si aucun engagement reel n'est
    identifiable ou si aucun pays destinataire n'est renseigne.
    `funding_type` toujours "private" (mandat institutionnel). La
    classification sectorielle reelle (via map_afdb_sector(), reutilisee
    telle quelle) se fait dans funding_validator.py, meme principe que
    AFD/OECD CRS.
    """
    commitment = _bii_commitment_summary(activity)
    if commitment is None:
        return None
    total_amount_usd, year, commitment_date = commitment

    country_codes = activity.get("recipient_country_code", [])
    if not country_codes:
        return None
    alpha2 = country_codes[0]
    country = pycountry.countries.get(alpha_2=alpha2)
    country_iso = country.alpha_3 if country is not None else alpha2

    return {
        "source": "bii",
        "project_id": activity["iati_identifier"],
        "country_iso": country_iso,
        "year": year,
        "amount_usd": int(round(total_amount_usd)),
        "funding_type": "private",
        "raw_sector_codes": _dac_sector_codes(activity),
        "board_approval_date": commitment_date,
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def collect_and_publish(producer) -> int:
    """Telecharge l'integralite du portefeuille publie de BII sur
    l'IATI Datastore et publie chaque activite exploitable vers
    `nev.funding.raw` via `producer`. Retourne le nombre de messages
    reellement publies.
    """
    published = 0
    for raw_activity in fetch_bii_activities():
        payload = parse_activity(raw_activity)
        if payload is None:
            continue
        producer.send("nev.funding.raw", payload, key=f"{payload['source']}:{payload['project_id']}:{payload['country_iso']}")
        published += 1
    producer.flush()
    return published
