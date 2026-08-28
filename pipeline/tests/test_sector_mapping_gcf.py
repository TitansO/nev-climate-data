from pipeline.processors.sector_mapping_gcf import map_gcf_sector


def test_maps_renewable_energy_generation_code():
    assert map_gcf_sector(["23210"], [100.0]) == "Renewable Energy"


def test_maps_energy_efficiency_code_to_renewable_energy():
    assert map_gcf_sector(["23183"], [100.0]) == "Renewable Energy"


def test_maps_transport_policy_code():
    assert map_gcf_sector(["21010"], [100.0]) == "Sustainable Transport"


def test_maps_forestry_policy_code():
    assert map_gcf_sector(["31210"], [100.0]) == "Forestry"


def test_maps_disaster_risk_reduction_to_adaptation():
    assert map_gcf_sector(["43060"], [100.0]) == "Adaptation"


def test_maps_biodiversity_to_adaptation():
    assert map_gcf_sector(["41030"], [100.0]) == "Adaptation"


def test_social_protection_codes_alone_are_unclassifiable():
    # 16010/16050 are GCF's two MOST FREQUENT codes in real data but never
    # map to any NEV sector on their own - see B1.2 spec decision 7.
    assert map_gcf_sector(["16010", "16050"], [60.0, 40.0]) is None


def test_dominant_social_protection_does_not_block_a_real_sector_match():
    # Real-world shape (verified against the full 350-activity GCF
    # portfolio): Social Protection (16010) often has a HIGHER percentage
    # than the mappable code, but first-match-wins (ignoring percentage)
    # still classifies it correctly - a dominant-sector-only rule would
    # wrongly quarantine this (96/350 real activities are like this).
    assert map_gcf_sector(["16010", "23210"], [70.0, 30.0]) == "Renewable Energy"


def test_priority_order_renewable_energy_before_forestry():
    assert map_gcf_sector(["31210", "23210"], [50.0, 50.0]) == "Renewable Energy"


def test_returns_none_for_no_codes_at_all():
    assert map_gcf_sector([], []) is None
