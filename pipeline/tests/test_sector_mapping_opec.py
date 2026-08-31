"""Maps an OPEC Fund Climate Finance Report row's raw sector label (and,
for the ambiguous "Energy" label, its project name) onto one of NEV's
five funding sectors, per the table in
docs/superpowers/specs/2026-08-29-b15-pdf-extractor-design.md (decision 8).
Returns None - triggering quarantine - when no rule matches.
"""
from pipeline.processors.sector_mapping_opec import map_opec_sector


def test_maps_transport_label():
    assert map_opec_sector("Transport", "Dakar - Saint Louis Highway Project") == "Sustainable Transport"


def test_maps_agriculture_and_livelihoods_label():
    assert map_opec_sector("Agriculture and Livelihoods", "Water Valorisation For Value Chains Development Project (Provale-CV)") == "Agriculture"


def test_maps_bare_agriculture_label():
    assert map_opec_sector("Agriculture", "Smallholder Agriculture Cluster Project") == "Agriculture"


def test_maps_fishing_label_to_agriculture():
    # Real precedent: AfDB's DAC code 31320 ("Fishery development") already
    # maps to Agriculture in this project (B1.3 spec decision 8).
    assert map_opec_sector("Fishing", "Sustainable Management Of Fisheries Project") == "Agriculture"


def test_maps_energy_label_with_hydropower_keyword():
    assert map_opec_sector("Energy", "Nachtigal Hydropower Company (NHPC)") == "Renewable Energy"


def test_maps_energy_label_with_wind_keyword():
    assert map_opec_sector("Energy", "240 MW Khizi-Absheron Wind Power Plant") == "Renewable Energy"


def test_maps_energy_label_with_solar_keyword():
    assert map_opec_sector("Energy Generation", "Niger Solar Plant Development And Electricity Access Improvement Project (Ranaa)") == "Renewable Energy"


def test_maps_energy_label_with_hydroelectric_keyword():
    assert map_opec_sector("Energy", "Achwa I 42MW Hydroelectric Power Plant") == "Renewable Energy"


def test_generic_energy_label_without_a_keyword_is_unclassifiable():
    # Real reasoning (spec decision 8): the report's own methodology
    # allows "transitional" fossil-fuel-adjacent activities as partial
    # mitigation finance, so "Energy" alone does not imply renewable.
    assert map_opec_sector("Energy", "Program To Expand Electricity Networks And Reduce Technical Losses In Distribution Systems (Loan 2)") is None


def test_water_label_is_unclassifiable():
    assert map_opec_sector("Water", "Freetown Wash And Aquatic Environment Revamping Project") is None


def test_financial_intermediation_label_is_unclassifiable():
    assert map_opec_sector("Financial Intermediation", "National Bank Of Egypt") is None


def test_generic_multisector_label_is_unclassifiable():
    assert map_opec_sector("Multisector", "Support To Climate Action And Energy Transition Program") is None


def test_compound_forestry_label_is_unclassifiable():
    # Real row: "Multisector (energy-cooking fuel & solutions; forestry)" -
    # too ambiguous/compound to map without guessing.
    assert map_opec_sector("Multisector (energy–cooking fuel & solutions; forestry)", "National Clean Cooking Transition Program") is None
