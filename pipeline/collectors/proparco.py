"""Proparco (filiale secteur-prive de l'AFD) collector - financement
climat du secteur PRIVE, deuxieme source de ce type (apres Norfund), via
l'API JSON reelle et publique d'opendata.afd.fr (plateforme OpenDataSoft,
dataset "donnees-de-laide-au-developpement-de-proparco", 899 lignes au
2026-10-07, demande explicite utilisateur).

Verifie en direct : contrairement a DFC/Norfund, deux champs de ce
dataset se sont reveles nettement plus bruyants que prevu a l'inspection
complete des 899 lignes reelles :

- Le signal climat (`montant_qui_presente_un_co_benefice_climat_
  incluant_adaptation_et_attenuation`) n'est PAS un montant - c'est une
  PART (0-100%) du financement total, mais saisie sous des formats
  incoherents : fraction (0.3), pourcentage nu (30), chaine avec signe
  "%"/virgule decimale ("30%", "8,5%"), texte libre ("14% attenuation",
  "N/A", "NON") - un prefixe numerique reste exploite ("14%"), un texte
  purement non numerique ("N/A", "NON") est exclu. normalize_climate_share() ci-dessous normalise les
  formats numeriques reconnaissables (fraction OU pourcentage) et exclut
  le reste plutot que de deviner - 31 des 215 lignes a priori
  climat-pertinentes sur ce critere sont ainsi explicitement exclues.
- Le champ d'atenuation seule (`..._uniquement_d_attenuation`) differe de
  celui-ci sur ~24 lignes reelles (fourchettes, "ND", valeurs
  incoherentes) - trop peu fiable pour servir de base a une separation
  adaptation/attenuation comme pour OPEC Fund ; ce connecteur ne tente
  donc PAS cette separation, voir sector_mapping_proparco.py.
- Les pays (`pays_de_realisation`) et secteurs
  (`secteur_s_concerne_s_par_le_projet`) sont en francais, parfois en
  MAJUSCULES, parfois des chaines multi-pays ("Egypte, Maroc") - jamais
  attribuables a un seul pays, donc exclues plutot que devinees.
  resolve_country_iso() ci-dessous utilise les noms de territoires
  officiels CLDR (via `babel`, donnees de reference, pas une table
  inventee a la main) plutot qu'un essai de correspondance anglaise
  (pycountry) qui echouerait sur la quasi-totalite des noms francais.

`funding_type` toujours "private" - Proparco est, par mandat
institutionnel, le bras secteur-prive du groupe AFD (meme raisonnement
que Norfund/FMO/BII) ; les types de clients reels observes (Entreprise,
Institution financiere, Fonds d'investissement, Microfinance...) le
confirment, aucun client souverain/public dans ce dataset.
"""
from __future__ import annotations

import datetime as dt
import re
import unicodedata
from typing import Any, Iterator

import pycountry
import requests
from babel import Locale

PROPARCO_RECORDS_URL = "https://opendata.afd.fr/api/explore/v2.1/catalog/datasets/donnees-de-laide-au-developpement-de-proparco/records"
PAGE_SIZE = 100
REQUEST_TIMEOUT_SECONDS = 30

# Meme API gratuite et sans cle, meme principe qu'afdb.py::XDR_RATE_URL
# (interroger directement dans la devise native plutot que d'inverser le
# taux USD-pivot de fx_rates.py, qui est reserve aux sources sans montant
# natif - voir SOURCES_WITHOUT_NATIVE_ORIGINAL_AMOUNT dans
# funding_validator.py).
EUR_RATE_URL = "https://open.er-api.com/v6/latest/EUR"

FIELD_CLIMATE_SHARE = "montant_qui_presente_un_co_benefice_climat_incluant_adaptation_et_attenuation"
FIELD_TOTAL_EUR = "montant_du_financement_en_euro"
FIELD_COUNTRY = "pays_de_realisation"
FIELD_SECTOR = "secteur_s_concerne_s_par_le_projet"
FIELD_SIGNATURE_DATE = "date_de_signature"
FIELD_PROJECT_ID = "id_projet"

# Chaines reelles designant plusieurs pays ou une zone, jamais un seul
# pays attribuable - verifie en direct (ex. "Egypte, Maroc", "MULTI-PAYS
# AFR", "Multi-Pays Etranger").
_NON_SINGLE_COUNTRY_MARKERS = (",", "/", "multi", "etranger")

# Alias verifies en direct entre le texte libre reel de Proparco et le
# nom CLDR officiel que `babel` retourne pour ce code - jamais une
# correspondance devinee, uniquement des cas ou l'orthographe reelle
# diverge du nom canonique (ex. "Viet-Nam" vs "Viet Nam", "RDC" comme
# sigle courant pour la Republique democratique du Congo).
_COUNTRY_NAME_ALIASES = {
    "viet-nam": "VN",
    "rdc": "CD",
    "terr.auto.pales": "PS",
}


def _strip_accents(text: str) -> str:
    normalized = unicodedata.normalize("NFKD", text)
    return "".join(ch for ch in normalized if not unicodedata.combining(ch))


def _build_country_name_index() -> dict[str, str]:
    """{nom_francais_sans_accents_casefold: code_alpha2} a partir des noms
    de territoires officiels CLDR (donnees de reference `babel`, pas une
    table inventee).
    """
    index: dict[str, str] = {}
    for code, name in Locale("fr").territories.items():
        if len(code) == 2 and code.isalpha():
            index[_strip_accents(name).casefold()] = code
    return index


_COUNTRY_NAME_INDEX = _build_country_name_index()


def resolve_country_iso(raw_name: str | None) -> str | None:
    """Resout un nom de pays reel (francais) en code ISO alpha-3, via les
    noms CLDR officiels (`babel`) - jamais `pycountry` seul, dont les noms
    sont en anglais et ne correspondent quasiment jamais au texte reel de
    ce dataset (verifie en direct). Retourne None pour une chaine
    multi-pays/zone ou un nom non reconnu - jamais une attribution
    devinee.
    """
    if not raw_name:
        return None
    text = raw_name.strip()
    lowered = text.lower()
    if any(marker in lowered for marker in _NON_SINGLE_COUNTRY_MARKERS):
        return None

    key = _strip_accents(text).casefold()
    alpha2 = _COUNTRY_NAME_INDEX.get(key) or _COUNTRY_NAME_ALIASES.get(key)
    if alpha2 is None:
        return None

    country = pycountry.countries.get(alpha_2=alpha2)
    return country.alpha_3 if country is not None else None


def normalize_climate_share(raw: Any) -> float | None:
    """Normalise le champ climat reel de Proparco - une PART (jamais un
    montant) ecrite sous des formats incoherents (fraction 0-1, pourcentage
    nu 0-100, chaine avec "%"/virgule decimale) - en une fraction 0-1, ou
    None si le texte ne commence pas par un nombre reconnaissable (ex.
    "N/A", "NON", commentaire libre) - jamais une part devinee a partir
    d'un texte non numerique.
    """
    if raw is None:
        return None
    text = str(raw).strip().replace(",", ".")
    match = re.match(r"^-?\d+(\.\d+)?", text)
    if not match:
        return None
    value = float(match.group(0))
    if value > 1:
        value = value / 100.0
    if value < 0 or value > 1:
        return None
    return value


def fetch_proparco_projects() -> Iterator[dict[str, Any]]:
    """Genere chaque ligne brute reelle du dataset Proparco
    d'opendata.afd.fr, en paginant via `limit`/`offset` (API OpenDataSoft
    Explore v2.1, verifiee en direct 2026-10-07 : 899 lignes, aucune cle
    requise).
    """
    offset = 0
    while True:
        response = requests.get(
            PROPARCO_RECORDS_URL,
            params={"limit": PAGE_SIZE, "offset": offset},
            timeout=REQUEST_TIMEOUT_SECONDS,
        )
        response.raise_for_status()
        payload = response.json()
        rows = payload.get("results", [])
        if not rows:
            return
        yield from rows
        offset += len(rows)
        if offset >= payload.get("total_count", 0):
            return


def fetch_eur_to_usd_rate() -> float:
    """Taux EUR->USD reel du jour (API gratuite, sans cle) - meme
    fournisseur qu'afdb.py::fetch_xdr_to_usd_rate, interroge directement
    dans la devise native.
    """
    response = requests.get(EUR_RATE_URL, timeout=REQUEST_TIMEOUT_SECONDS)
    response.raise_for_status()
    payload = response.json()
    return payload["rates"]["USD"]


def parse_project(raw: dict[str, Any], eur_to_usd_rate: float) -> dict[str, Any] | None:
    """Convertit une ligne brute reelle de Proparco en paiement
    `nev.funding.raw`, ou None si l'un des signaux necessaires (part
    climat, montant total, pays, annee) n'est pas fiable - jamais une
    valeur devinee pour completer un signal manquant/ambigu. Le secteur
    brut (`sector_label_raw`) est transmis sans etre valide ici - sa
    classification reelle (via map_proparco_sector()) se fait dans
    funding_validator.py, meme principe que World Bank/Norfund.
    """
    share = normalize_climate_share(raw.get(FIELD_CLIMATE_SHARE))
    if not share:
        return None

    total_eur = raw.get(FIELD_TOTAL_EUR)
    if not total_eur:
        return None

    country_iso = resolve_country_iso(raw.get(FIELD_COUNTRY))
    if country_iso is None:
        return None

    signature_date = raw.get(FIELD_SIGNATURE_DATE)
    if not signature_date:
        return None
    year = int(signature_date[:4])

    amount_eur = total_eur * share
    amount_usd = amount_eur * eur_to_usd_rate

    return {
        "source": "proparco",
        "project_id": raw[FIELD_PROJECT_ID],
        "country_iso": country_iso,
        "year": year,
        "amount_usd": int(round(amount_usd)),
        "original_amount": amount_eur,
        "original_currency": "EUR",
        "exchange_rate": eur_to_usd_rate,
        "funding_type": "private",
        "sector_label_raw": raw.get(FIELD_SECTOR),
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def collect_and_publish(producer) -> int:
    """Telecharge le dataset reel de Proparco et publie chaque projet
    exploitable vers `nev.funding.raw` via `producer`. Retourne le nombre
    de messages reellement publies.
    """
    eur_to_usd_rate = fetch_eur_to_usd_rate()
    published = 0
    for raw_project in fetch_proparco_projects():
        payload = parse_project(raw_project, eur_to_usd_rate)
        if payload is None:
            continue
        producer.send("nev.funding.raw", payload, key=f"{payload['source']}:{payload['project_id']}:{payload['country_iso']}")
        published += 1
    producer.flush()
    return published
