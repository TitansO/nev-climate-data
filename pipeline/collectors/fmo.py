"""FMO (Nederlandse Financierings-Maatschappij voor Ontwikkelingslanden,
banque neerlandaise de developpement) collector - financement climat
PRIVE reel, sixieme source de ce type (apres DFC, Norfund, Proparco,
OECD CRS, BII), via l'IATI Datastore - verifie en direct 2026-10-08,
demande explicite utilisateur, suite d'une recherche elargie au reseau
EDFI (institutions europeennes de financement du developpement) : sur
15+ institutions testees (DEG, Finnfund, Swedfund, IFU, BIO, COFIDES,
Triodos, responsAbility, Africa50, GuarantCo...), FMO est la seule a
publier reellement sur IATI sous un nom verifiable (Norfund elle-meme,
utilisee comme temoin de controle, ne publie pas non plus sur IATI -
confirme que sa propre source reelle est bien son fichier Excel, pas
IATI). `funding_type` toujours "private" - FMO est, par mandat
institutionnel, une banque d'investissement dans des entreprises
privees (jamais de pret budgetaire souverain), meme raisonnement que
Norfund/DFC/BII deja integres.

Piege reel rencontre, different de afd.py/bii.py : les activites
reelles de FMO portent leurs codes secteur reels (5 chiffres CRS
CAD/OCDE, confirme en direct - ex. "23210" "Energy generation,
renewable sources - multiple technologies", "23310" "Oil-fired electric
power plants") sous `sector_vocabulary = "2"` au lieu du "1" attendu -
une erreur de publication cote FMO (vocabulaire "2" officiel = codes a
3 chiffres, mais FMO y place en realite ses vrais codes a 5 chiffres).
Contrairement a afd.py/bii.py, ce connecteur ne filtre donc PAS par
vocabulaire - transmet tout `sector_code` brut, la classification reelle
(via map_afdb_sector(), reutilisee telle quelle) se fait dans
funding_validator.py et rejette naturellement tout code qui ne
correspondrait a aucun des 5 secteurs NEV.
"""
from __future__ import annotations

import datetime as dt
import os
import time
from typing import Any, Iterator

import pycountry
import requests

IATI_DATASTORE_URL = "https://api.iatistandard.org/datastore/activity/select"
FMO_REPORTING_ORG_REF = "NL-KVK-27078545"
PAGE_SIZE = 1000
REQUEST_TIMEOUT_SECONDS = 30
# Meme limite de debit reelle que afdb.py/afd.py (offre gratuite IATI, 1
# requete/seconde).
PAGINATION_DELAY_SECONDS = 1.1

FIELDS = ",".join([
    "iati_identifier", "recipient_country_code", "sector_code",
    "transaction_transaction_type_code", "transaction_value",
    "transaction_transaction_date_iso_date",
])

COMMITMENT_TRANSACTION_TYPE = "2"
# Meme plancher et meme raisonnement que afdb.py/afd.py/bii.py (Sommet
# de la Terre de Rio/CCNUCC, 1992).
MIN_PLAUSIBLE_COMMITMENT_YEAR = 1992

EUR_RATE_URL = "https://open.er-api.com/v6/latest/EUR"


def fetch_fmo_activities() -> Iterator[dict[str, Any]]:
    """Genere chaque activite brute reelle de FMO depuis l'IATI
    Datastore, en paginant (~1314 activites au 2026-10-08, verifie en
    direct) - meme mecanisme que afdb.py/afd.py.
    """
    offset = 0
    while True:
        if offset > 0:
            time.sleep(PAGINATION_DELAY_SECONDS)
        response = requests.get(
            IATI_DATASTORE_URL,
            headers={"Ocp-Apim-Subscription-Key": os.environ["IATI_API_KEY"]},
            params={
                "q": f'reporting_org_ref:"{FMO_REPORTING_ORG_REF}"',
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
    fournisseur et meme principe que afd.py/proparco.py.
    """
    response = requests.get(EUR_RATE_URL, timeout=REQUEST_TIMEOUT_SECONDS)
    response.raise_for_status()
    payload = response.json()
    return payload["rates"]["USD"]


def _fmo_commitment_summary(activity: dict[str, Any]) -> tuple[float, int, str] | None:
    """Retourne (montant_total_eur, annee, date_engagement_la_plus_ancienne)
    en sommant chaque transaction de type "2" (Outgoing Commitment) -
    meme principe que afd.py::_afd_commitment_summary. Retourne None si
    aucune transaction ne correspond.
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
    """Convertit une activite brute reelle de FMO en paiement
    `nev.funding.raw`, ou None si aucun engagement reel n'est
    identifiable ou si aucun pays destinataire n'est renseigne.
    `funding_type` toujours "private". `raw_sector_codes` transmis sans
    filtre de vocabulaire (voir le docblock du module) - la
    classification reelle (via map_afdb_sector(), reutilisee telle
    quelle) se fait dans funding_validator.py, meme principe que AFD/
    BII/OECD CRS.
    """
    commitment = _fmo_commitment_summary(activity)
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
        "source": "fmo",
        "project_id": activity["iati_identifier"],
        "country_iso": country_iso,
        "year": year,
        "amount_usd": int(round(amount_usd)),
        "original_amount": total_amount_eur,
        "original_currency": "EUR",
        "exchange_rate": eur_to_usd_rate,
        "funding_type": "private",
        "raw_sector_codes": activity.get("sector_code", []),
        "board_approval_date": commitment_date,
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def collect_and_publish(producer) -> int:
    """Telecharge l'integralite du portefeuille publie de FMO sur
    l'IATI Datastore, convertit chaque engagement reel en USD via un
    taux EUR->USD recupere une seule fois, et publie vers
    `nev.funding.raw` via `producer`. Retourne le nombre de messages
    reellement publies.
    """
    eur_to_usd_rate = fetch_eur_to_usd_rate()
    published = 0
    for raw_activity in fetch_fmo_activities():
        payload = parse_activity(raw_activity, eur_to_usd_rate)
        if payload is None:
            continue
        producer.send("nev.funding.raw", payload, key=f"{payload['source']}:{payload['project_id']}:{payload['country_iso']}")
        published += 1
    producer.flush()
    return published
