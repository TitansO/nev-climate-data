"""Unit tests for the Proparco collector's parsing/normalization logic -
uses realistic sample rows (field shapes captured from the real, public
opendata.afd.fr dataset during this connector's design, 2026-10-07)
rather than hitting the network, so this file runs offline and fast.
"""
from unittest.mock import MagicMock, patch

from pipeline.collectors.proparco import (
    collect_and_publish,
    fetch_proparco_projects,
    normalize_climate_share,
    parse_project,
    resolve_country_iso,
)


def test_normalize_climate_share_parses_a_plain_fraction():
    assert normalize_climate_share("0.3") == 0.3


def test_normalize_climate_share_parses_a_bare_percentage_number():
    assert normalize_climate_share("30") == 0.3


def test_normalize_climate_share_parses_a_percent_sign_string():
    assert normalize_climate_share("30%") == 0.3


def test_normalize_climate_share_parses_a_french_decimal_comma_percent():
    assert normalize_climate_share("8,5%") == 0.085


def test_normalize_climate_share_parses_an_explicit_zero():
    assert normalize_climate_share("0") == 0.0


def test_normalize_climate_share_uses_the_leading_numeric_prefix():
    # Reel : "14% atténuation" - un commentaire libre apres un pourcentage
    # reste exploitable via son prefixe numerique.
    assert normalize_climate_share("14% attenuation") == 0.14


def test_normalize_climate_share_returns_none_for_non_numeric_text():
    assert normalize_climate_share("N/A") is None
    assert normalize_climate_share("NON") is None


def test_normalize_climate_share_returns_none_for_none():
    assert normalize_climate_share(None) is None


def test_resolve_country_iso_handles_a_real_french_name_with_accents():
    assert resolve_country_iso("Pérou") == "PER"


def test_resolve_country_iso_handles_a_plain_name():
    assert resolve_country_iso("Kenya") == "KEN"


def test_resolve_country_iso_is_case_insensitive():
    assert resolve_country_iso("TURQUIE") == "TUR"


def test_resolve_country_iso_applies_a_verified_alias():
    assert resolve_country_iso("Viet-Nam") == "VNM"
    assert resolve_country_iso("RDC") == "COD"


def test_resolve_country_iso_returns_none_for_a_multi_country_string():
    assert resolve_country_iso("Egypte, Maroc") is None
    assert resolve_country_iso("MULTI-PAYS") is None


def test_resolve_country_iso_returns_none_for_an_unresolvable_name():
    assert resolve_country_iso("Pays Totalement Invente") is None


def test_resolve_country_iso_returns_none_for_none():
    assert resolve_country_iso(None) is None


_SAMPLE_RAW_ROW = {
    "id_projet": "PTR1081",
    "pays_de_realisation": "Turquie",
    "montant_qui_presente_un_co_benefice_climat_incluant_adaptation_et_attenuation": "1",
    "montant_du_financement_en_euro": 23148148.1481,
    "secteur_s_concerne_s_par_le_projet": "Energies renouvelables",
    "date_de_signature": "2024-03-25",
}


def test_parse_project_extracts_a_real_private_climate_project():
    payload = parse_project(_SAMPLE_RAW_ROW, eur_to_usd_rate=1.08)

    assert payload["source"] == "proparco"
    assert payload["project_id"] == "PTR1081"
    assert payload["country_iso"] == "TUR"
    assert payload["year"] == 2024
    assert payload["amount_usd"] == round(23148148.1481 * 1.08)
    assert payload["original_amount"] == 23148148.1481
    assert payload["original_currency"] == "EUR"
    assert payload["exchange_rate"] == 1.08
    assert payload["funding_type"] == "private"
    assert payload["sector_label_raw"] == "Energies renouvelables"


def test_parse_project_passes_through_an_unmappable_sector_label_unfiltered():
    # La classification reelle se fait dans funding_validator.py (comme
    # World Bank/Norfund) - parse_project() ne doit jamais rejeter une
    # ligne pour cette seule raison.
    row = {**_SAMPLE_RAW_ROW, "secteur_s_concerne_s_par_le_projet": "Banque & Institution financière"}
    payload = parse_project(row, eur_to_usd_rate=1.08)
    assert payload is not None
    assert payload["sector_label_raw"] == "Banque & Institution financière"


def test_parse_project_returns_none_for_a_zero_climate_share():
    row = {**_SAMPLE_RAW_ROW, "montant_qui_presente_un_co_benefice_climat_incluant_adaptation_et_attenuation": "0"}
    assert parse_project(row, eur_to_usd_rate=1.08) is None


def test_parse_project_returns_none_for_an_unparseable_climate_share():
    row = {**_SAMPLE_RAW_ROW, "montant_qui_presente_un_co_benefice_climat_incluant_adaptation_et_attenuation": "N/A"}
    assert parse_project(row, eur_to_usd_rate=1.08) is None


def test_parse_project_returns_none_without_a_total_amount():
    row = {**_SAMPLE_RAW_ROW, "montant_du_financement_en_euro": None}
    assert parse_project(row, eur_to_usd_rate=1.08) is None


def test_parse_project_returns_none_for_an_unresolvable_country():
    row = {**_SAMPLE_RAW_ROW, "pays_de_realisation": "Egypte, Maroc"}
    assert parse_project(row, eur_to_usd_rate=1.08) is None


def test_parse_project_returns_none_without_a_signature_date():
    row = {**_SAMPLE_RAW_ROW, "date_de_signature": None}
    assert parse_project(row, eur_to_usd_rate=1.08) is None


def test_fetch_proparco_projects_paginates_through_every_page():
    page_1 = MagicMock()
    page_1.json.return_value = {"total_count": 3, "results": [{"id_projet": "A"}, {"id_projet": "B"}]}
    page_2 = MagicMock()
    page_2.json.return_value = {"total_count": 3, "results": [{"id_projet": "C"}]}

    with patch("pipeline.collectors.proparco.requests.get", side_effect=[page_1, page_2]):
        results = list(fetch_proparco_projects())

    assert results == [{"id_projet": "A"}, {"id_projet": "B"}, {"id_projet": "C"}]


def test_collect_and_publish_sends_only_parseable_projects_and_returns_count():
    mock_producer = MagicMock()
    unparseable_row = {**_SAMPLE_RAW_ROW, "montant_du_financement_en_euro": None}

    with patch("pipeline.collectors.proparco.fetch_eur_to_usd_rate", return_value=1.08), \
         patch(
             "pipeline.collectors.proparco.fetch_proparco_projects",
             return_value=iter([_SAMPLE_RAW_ROW, unparseable_row]),
         ):
        published = collect_and_publish(mock_producer)

    assert published == 1
    mock_producer.send.assert_called_once()
    assert mock_producer.send.call_args[0][0] == "nev.funding.raw"
    assert mock_producer.send.call_args[1]["key"] == "proparco:PTR1081:TUR"
    mock_producer.flush.assert_called_once()
