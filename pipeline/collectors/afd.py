"""AFD (Agence Francaise de Developpement) collector - financement
climat PUBLIC reel, troisieme source de ce type (apres AfDB, World
Bank), via l'API IATI Datastore (meme infrastructure que afdb.py/gcf.py)
- verifie en direct 2026-10-08, demande explicite utilisateur, suite de
la recherche menee pour trouver des sources alternatives apres OECD CRS.

Important, verifie en direct : il s'agit bien de l'AFD elle-meme
(reporting_org_ref "FR-3"), l'agence bilaterale publique francaise - PAS
Proparco, sa filiale secteur-prive deja integree separement (voir
proparco.py, via opendata.afd.fr - une API distincte, un perimetre
distinct, 0 ligne exploitable). `funding_type` toujours "public" ici -
par mandat institutionnel (agence bilaterale d'aide publique au
developpement), meme raisonnement que Norfund/DFC/BII sont classes
"private" par mandat sans verification ligne a ligne.

Chaque activite reelle porte ses codes secteur sous DEUX vocabulaires
IATI paralleles (`sector_code`/`sector_vocabulary`, memes index) :
vocabulaire "1" (codes CRS CAD/OCDE a 5 chiffres, le meme systeme que
celui deja utilise par afdb.py) et vocabulaire "99" (classification
interne de l'AFD, un simple entier - jamais un code CRS, ne doit jamais
etre transmis a map_afdb_sector()). Seuls les codes en vocabulaire "1"
sont retenus ici - le filtrage/la classification reelle se fait dans
funding_validator.py (reutilise map_afdb_sector() telle quelle, meme
principe que oecd_crs.py).
"""
from __future__ import annotations

import datetime as dt
import os
import time
from typing import Any, Iterator

import pycountry
import requests

IATI_DATASTORE_URL = "https://api.iatistandard.org/datastore/activity/select"
AFD_REPORTING_ORG_REF = "FR-3"
PAGE_SIZE = 1000
REQUEST_TIMEOUT_SECONDS = 30
# Meme limite de debit reelle que afdb.py (offre gratuite IATI, 1
# requete/seconde) - AFD a un portefeuille comparable en taille (~4900
# activites, plusieurs pages).
PAGINATION_DELAY_SECONDS = 1.1

FIELDS = ",".join([
    "iati_identifier", "recipient_country_code", "sector_code", "sector_vocabulary",
    "transaction_transaction_type_code", "transaction_value",
    "transaction_transaction_date_iso_date",
])

COMMITMENT_TRANSACTION_TYPE = "2"
DAC_SECTOR_VOCABULARY = "1"
# Meme plancher et meme raisonnement que afdb.py::MIN_PLAUSIBLE_COMMITMENT_YEAR
# - 1992 (Sommet de la Terre de Rio/CCNUCC), choix produit assume plutot
# qu'une regle IATI, applique ici pour la meme cause reelle (engagements
# historiques anterieurs a la notion meme de "financement climatique").
MIN_PLAUSIBLE_COMMITMENT_YEAR = 1992

EUR_RATE_URL = "https://open.er-api.com/v6/latest/EUR"


def fetch_afd_activities() -> Iterator[dict[str, Any]]:
    """Genere chaque activite brute reelle de l'AFD depuis l'IATI
    Datastore, en paginant (~4869 activites au 2026-10-08, verifie en
    direct) via `start`/`rows` - meme mecanisme que
    afdb.py::fetch_afdb_activities().
    """
    offset = 0
    while True:
        if offset > 0:
            time.sleep(PAGINATION_DELAY_SECONDS)
        response = requests.get(
            IATI_DATASTORE_URL,
            headers={"Ocp-Apim-Subscription-Key": os.environ["IATI_API_KEY"]},
            params={
                "q": f'reporting_org_ref:"{AFD_REPORTING_ORG_REF}"',
                "rows": PAGE_SIZE,
                "start": offset,
                "wt": "json",
                "fl": FIELDS,
            },
            timeout=REQUEST_TIMEOUT_SECONDS,
        )
        response.raise_for_status()
        payload = response.json()
        docs = payload["response"]["docs"]
        if not docs:
            return
        yield from docs
        offset += len(docs)
        if offset >= payload["response"]["numFound"]:
            return


def fetch_eur_to_usd_rate() -> float:
    """Taux EUR->USD reel du jour (API gratuite, sans cle) - meme
    fournisseur et meme principe que proparco.py::fetch_eur_to_usd_rate.
    """
    response = requests.get(EUR_RATE_URL, timeout=REQUEST_TIMEOUT_SECONDS)
    response.raise_for_status()
    payload = response.json()
    return payload["rates"]["USD"]


def _dac_sector_codes(activity: dict[str, Any]) -> list[str]:
    """Filtre `sector_code` aux seules entrees en vocabulaire IATI "1"
    (codes CRS CAD/OCDE a 5 chiffres) - exclut le vocabulaire "99"
    (classification interne de l'AFD, de simples entiers) qui partage le
    meme index de tableau. Verifie en direct : les deux vocabulaires
    coexistent systematiquement, toujours dans cet ordre (1 puis 99).
    """
    codes = activity.get("sector_code", [])
    vocabularies = activity.get("sector_vocabulary", [])
    return [code for code, vocab in zip(codes, vocabularies) if vocab == DAC_SECTOR_VOCABULARY]


def _afd_commitment_summary(activity: dict[str, Any]) -> tuple[float, int, str] | None:
    """Retourne (montant_total_eur, annee, date_engagement_la_plus_ancienne)
    en sommant chaque transaction de type "2" (Outgoing Commitment)
    reportee a cette activite - meme principe que
    afdb.py::_afdb_commitment_summary, sans filtre de fournisseur (toutes
    les transactions "2" d'une activite FR-3 sont par definition de
    l'AFD, contrairement a afdb.py qui doit exclure les fonds tiers
    heberges). Retourne None si aucune transaction ne correspond.
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


def parse_activity(activity: dict[str, Any], eur_to_usd_rate: float) -> dict[str, Any] | None:
    """Convertit une activite brute reelle de l'AFD en paiement
    `nev.funding.raw`, ou None si aucun engagement reel n'est identifiable
    ou si aucun pays destinataire n'est renseigne. `funding_type`
    toujours "public" (mandat institutionnel). La classification
    sectorielle reelle (via map_afdb_sector(), reutilisee telle quelle -
    meme systeme de codes CRS CAD/OCDE) se fait dans
    funding_validator.py, meme principe que World Bank/OECD CRS.
    """
    commitment = _afd_commitment_summary(activity)
    if commitment is None:
        return None
    total_amount_eur, year, commitment_date = commitment

    country_codes = activity.get("recipient_country_code", [])
    if not country_codes:
        return None
    alpha2 = country_codes[0]
    country = pycountry.countries.get(alpha_2=alpha2)
    country_iso = country.alpha_3 if country is not None else alpha2

    amount_usd = total_amount_eur * eur_to_usd_rate

    return {
        "source": "afd",
        "project_id": activity["iati_identifier"],
        "country_iso": country_iso,
        "year": year,
        "amount_usd": int(round(amount_usd)),
        "original_amount": total_amount_eur,
        "original_currency": "EUR",
        "exchange_rate": eur_to_usd_rate,
        "funding_type": "public",
        "raw_sector_codes": _dac_sector_codes(activity),
        "board_approval_date": commitment_date,
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def collect_and_publish(producer) -> int:
    """Telecharge l'integralite du portefeuille publie de l'AFD sur
    l'IATI Datastore, convertit chaque engagement reel en USD via un
    taux EUR->USD recupere une seule fois, et publie vers
    `nev.funding.raw` via `producer`. Retourne le nombre de messages
    reellement publies.
    """
    eur_to_usd_rate = fetch_eur_to_usd_rate()
    published = 0
    for raw_activity in fetch_afd_activities():
        payload = parse_activity(raw_activity, eur_to_usd_rate)
        if payload is None:
            continue
        producer.send("nev.funding.raw", payload, key=f"{payload['source']}:{payload['project_id']}:{payload['country_iso']}")
        published += 1
    producer.flush()
    return published
