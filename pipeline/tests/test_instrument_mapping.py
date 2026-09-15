"""Unit tests for the IATI finance-type -> FundingInstrument mapping (F1)."""
from pipeline.processors.instrument_mapping import first_finance_type_code, map_iati_finance_type


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


class TestFirstFinanceTypeCode:
    """The real bug (found live 2026-09-15): `default_finance_type_code`
    comes back from the IATI Datastore as a plain string, not the
    single-element list both collectors originally assumed - indexing a
    string like a list (`"421"[0]`) silently truncated every real code to
    its first character, which matches nothing in map_iati_finance_type()'s
    tables and mapped every AfDB/GCF row to "unknown"."""

    def test_returns_the_string_itself_when_given_a_plain_string(self):
        assert first_finance_type_code("421") == "421"

    def test_returns_the_first_element_when_given_a_list(self):
        assert first_finance_type_code(["421"]) == "421"

    def test_returns_none_for_an_empty_list(self):
        assert first_finance_type_code([]) is None

    def test_returns_none_for_none(self):
        assert first_finance_type_code(None) is None
