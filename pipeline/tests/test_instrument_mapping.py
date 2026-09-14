"""Unit tests for the IATI finance-type -> FundingInstrument mapping (F1)."""
from pipeline.processors.instrument_mapping import map_iati_finance_type


def test_maps_standard_grant_code():
    assert map_iati_finance_type("110") == "grant"


def test_maps_subsidies_to_national_private_investors_to_grant():
    assert map_iati_finance_type("111") == "grant"


def test_maps_interest_subsidy_to_grant():
    assert map_iati_finance_type("210") == "grant"


def test_maps_aid_loan_to_loan_concessional():
    assert map_iati_finance_type("410") == "loan_concessional"


def test_maps_standard_loan_to_loan_concessional():
    assert map_iati_finance_type("421") == "loan_concessional"


def test_maps_reimbursable_grant_to_loan_concessional():
    assert map_iati_finance_type("422") == "loan_concessional"


def test_maps_bank_export_credit_to_guarantee():
    assert map_iati_finance_type("453") == "guarantee"


def test_maps_non_bank_guaranteed_export_credit_to_guarantee():
    assert map_iati_finance_type("451") == "guarantee"


def test_maps_common_equity_to_equity():
    assert map_iati_finance_type("510") == "equity"


def test_maps_preferred_equity_to_equity():
    assert map_iati_finance_type("432") == "equity"


def test_returns_unknown_for_a_missing_code():
    assert map_iati_finance_type(None) == "unknown"


def test_returns_unknown_for_an_unrecognized_code():
    # 600-series (debt relief) - real IATI codes, deliberately unmapped:
    # none of this project's sources report debt relief as new financing.
    assert map_iati_finance_type("615") == "unknown"
