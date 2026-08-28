from pipeline.processors.sector_mapping_afdb import map_afdb_sector


def test_maps_solar_energy_code():
    assert map_afdb_sector(["23230"]) == "Renewable Energy"


def test_maps_hydro_code():
    assert map_afdb_sector(["23220"]) == "Renewable Energy"


def test_maps_wind_code():
    assert map_afdb_sector(["23240"]) == "Renewable Energy"


def test_generic_energy_policy_alone_is_unclassifiable():
    # 23111 is AfDB's 4th most frequent code overall (315 occurrences) but
    # does not specify renewable vs non-renewable - must not default to
    # Renewable Energy. See B1.3 spec decision 8.
    assert map_afdb_sector(["23111"]) is None


def test_coal_power_is_never_renewable_energy():
    # Confirmed present in AfDB's real portfolio - the whole reason this
    # connector needs its own careful table instead of reusing a loose
    # "energy" keyword match.
    assert map_afdb_sector(["23320"]) is None
    assert map_afdb_sector(["23310"]) is None


def test_maps_national_road_construction():
    assert map_afdb_sector(["21023"]) == "Sustainable Transport"


def test_maps_public_transport_services():
    assert map_afdb_sector(["21012"]) == "Sustainable Transport"


def test_maps_agricultural_policy():
    assert map_afdb_sector(["31110"]) == "Agriculture"


def test_maps_livestock():
    assert map_afdb_sector(["31163"]) == "Agriculture"


def test_maps_forestry_development():
    assert map_afdb_sector(["31220"]) == "Forestry"


def test_generic_environmental_policy_is_unclassifiable():
    # 41010 is the only "environmental" code present in AfDB's real
    # portfolio - too broad to imply climate adaptation. Neither of
    # B1.2's Adaptation codes (43060, 41030) appear anywhere in AfDB's
    # data (verified live) - Adaptation is never populated by this
    # connector, a real gap, not a bug.
    assert map_afdb_sector(["41010"]) is None


def test_priority_order_renewable_energy_before_agriculture():
    assert map_afdb_sector(["31110", "23230"]) == "Renewable Energy"


def test_returns_none_for_no_codes_at_all():
    assert map_afdb_sector([]) is None


def test_returns_none_for_general_budget_support():
    # 51010 - AfDB's 2nd most frequent code (615 occurrences) - not a NEV sector.
    assert map_afdb_sector(["51010"]) is None
