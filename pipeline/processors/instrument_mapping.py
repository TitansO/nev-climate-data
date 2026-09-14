"""Maps an IATI `default-finance-type` code onto one of NEV's
`FundingInstrument` values (F1, `backend/src/Entity/Enum/
FundingInstrument.php`). Used by the GCF and AfDB connectors - the only
two sources whose IATI Datastore payload carries this field; World Bank
and OPEC Fund PDF have no equivalent signal and always resolve to
"unknown" (see funding_validator.py's default).

The mapping below is based on the published IATI FinanceType codelist
(https://iatistandard.org/en/iati-standard/203/codelists/financetype/),
not verified against a live GCF/AfDB portfolio the way this project's
sector-mapping modules are (see their own docblocks) - to be revisited
once real `default_finance_type_code` values from both connectors have
actually been observed flowing through the pipeline.
"""
from __future__ import annotations

# 110 Standard grant, 111 Subsidies to national private investors,
# 210 Interest subsidy.
_GRANT_CODES = frozenset({"110", "111", "210"})
# 410 Aid loan (excluding debt reorganisation), 421 Standard loan,
# 422 Reimbursable grant. IATI's codelist does not itself distinguish
# concessional from non-concessional terms - GCF and AfDB Group only ever
# lend on concessional terms under their own mandates, so any loan code
# from these two sources is treated as concessional here. A genuinely
# non-concessional loan would need a signal this codelist doesn't carry -
# not attempted.
_LOAN_CONCESSIONAL_CODES = frozenset({"410", "421", "422"})
# 451/452/453: non-bank and bank export credit guarantees.
_GUARANTEE_CODES = frozenset({"451", "452", "453"})
# 432 Preferred equity, 510 Common equity, 511/512 acquisition of equity,
# 520 shares in collective investment vehicles, 530 reinvested earnings.
_EQUITY_CODES = frozenset({"432", "510", "511", "512", "520", "530"})


def map_iati_finance_type(code: str | None) -> str:
    """Returns one of "grant", "loan_concessional", "guarantee", "equity"
    or "unknown". Never raises and never quarantines a message on an
    unrecognized instrument - unlike sector (used for aggregation and
    must resolve), a financing record with an unknown instrument is still
    real financing data worth keeping.
    """
    if code is None:
        return "unknown"
    if code in _GRANT_CODES:
        return "grant"
    if code in _LOAN_CONCESSIONAL_CODES:
        return "loan_concessional"
    if code in _GUARANTEE_CODES:
        return "guarantee"
    if code in _EQUITY_CODES:
        return "equity"
    return "unknown"
