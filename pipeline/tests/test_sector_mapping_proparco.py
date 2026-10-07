"""Tests for sector_mapping_proparco.py's French-language keyword rules -
real `secteur_s_concerne_s_par_le_projet` strings observed live
(2026-10-07) on Proparco's public opendata.afd.fr dataset.
"""
from pipeline.processors.sector_mapping_proparco import map_proparco_sector


def test_maps_energies_renouvelables_label():
    assert map_proparco_sector("Energies renouvelables") == "Renewable Energy"


def test_maps_energie_renouvelable_singular_label():
    assert map_proparco_sector("Energie renouvelable") == "Renewable Energy"


def test_bare_energie_label_is_unclassifiable():
    # Verifie en direct : Proparco finance aussi de l'energie non
    # renouvelable - "Energie" seul ne doit jamais etre presume renouvelable
    # (meme discipline que sector_mapping_opec.py pour le label "Energy").
    assert map_proparco_sector("Energie") is None


def test_maps_agriculture_et_agro_industrie_label():
    assert map_proparco_sector("Agriculture et agro-industrie") == "Agriculture"


def test_maps_agro_industrie_et_agriculture_label_regardless_of_word_order():
    assert map_proparco_sector("Agro-industrie et agriculture") == "Agriculture"


def test_maps_infrastructure_transport_mine_label():
    assert map_proparco_sector("Infrastructure (transport, mine)") == "Sustainable Transport"


def test_banque_et_institution_financiere_label_is_unclassifiable():
    assert map_proparco_sector("Banque & Institution financière") is None


def test_sante_label_is_unclassifiable():
    assert map_proparco_sector("Santé") is None


def test_inconnu_label_is_unclassifiable():
    assert map_proparco_sector("Inconnu") is None


def test_infrastructure_ville_durable_label_is_unclassifiable():
    # Pas l'un des 5 secteurs NEV - exclu plutot que force dans une case
    # approximative.
    assert map_proparco_sector("Infrastructure - Ville durable") is None


def test_none_label_is_unclassifiable():
    assert map_proparco_sector(None) is None
