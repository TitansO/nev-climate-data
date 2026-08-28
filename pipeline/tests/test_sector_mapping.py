from pipeline.processors.sector_mapping import map_to_nev_sector


def test_maps_solar_energy_to_renewable_energy():
    result = map_to_nev_sector(
        raw_sectors=["Energy Generation - Solar", "Energy Networks and Storage"],
        raw_theme=["Climate Change", "Adaptation"],
    )
    assert result == "Renewable Energy"


def test_energy_project_wins_over_adaptation_theme():
    result = map_to_nev_sector(
        raw_sectors=["Energy Generation - Solar"],
        raw_theme=["Adaptation"],
    )
    assert result == "Renewable Energy"


def test_maps_roads_to_sustainable_transport():
    result = map_to_nev_sector(raw_sectors=["Rural and Inter-Urban Roads"], raw_theme=[])
    assert result == "Sustainable Transport"


def test_maps_agriculture_to_agriculture():
    result = map_to_nev_sector(raw_sectors=["Agricultural Extension"], raw_theme=[])
    assert result == "Agriculture"


def test_maps_forest_to_forestry():
    result = map_to_nev_sector(raw_sectors=["Forestry"], raw_theme=[])
    assert result == "Forestry"


def test_falls_back_to_adaptation_theme_when_no_sector_matches():
    result = map_to_nev_sector(
        raw_sectors=["Public Administration - Health"],
        raw_theme=["Disaster Risk Management", "Adaptation"],
    )
    assert result == "Adaptation"


def test_returns_none_when_nothing_matches():
    result = map_to_nev_sector(raw_sectors=["Health"], raw_theme=["Social Protection"])
    assert result is None


def test_returns_none_when_no_sector_data_at_all():
    result = map_to_nev_sector(raw_sectors=[], raw_theme=[])
    assert result is None
