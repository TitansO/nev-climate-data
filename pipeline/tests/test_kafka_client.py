"""Unit tests for pipeline/common/kafka_client.py - mocks kafka-python's
KafkaProducer/KafkaConsumer entirely (same offline-by-default convention as
pipeline/tests/test_*_collector.py), so this covers only the wiring this
module owns (serializers, commit mode), not the Kafka client library
itself. Added 2026-10-01 (post-C3 audit) alongside the message-key/manual-
commit fixes - this module previously had zero test coverage.
"""
import json
from unittest.mock import patch

from pipeline.common import kafka_client


def test_make_producer_value_serializer_encodes_json_utf8():
    with patch("pipeline.common.kafka_client.KafkaProducer") as mock_producer_cls:
        kafka_client.make_producer()

    kwargs = mock_producer_cls.call_args.kwargs
    assert kwargs["value_serializer"]({"a": 1}) == json.dumps({"a": 1}).encode("utf-8")


def test_make_producer_key_serializer_encodes_a_string_key_to_bytes():
    # Audit post-C3 2026-10-01 : sans ce serializer, producer.send(...,
    # key="...") leve une erreur kafka-python des qu'un appelant passe une
    # cle non-bytes (voir chaque collecteur/DAG, desormais partitionnes par
    # cle de dedup).
    with patch("pipeline.common.kafka_client.KafkaProducer") as mock_producer_cls:
        kafka_client.make_producer()

    kwargs = mock_producer_cls.call_args.kwargs
    assert kwargs["key_serializer"]("world_bank:P123:SEN") == b"world_bank:P123:SEN"
    assert kwargs["key_serializer"](None) is None


def test_make_consumer_disables_auto_commit():
    # Audit post-C3 2026-10-01 : regression test - enable_auto_commit=True
    # committait l'offset independamment de la reelle persistance du
    # message en base (risque de perte silencieuse sur un crash). Le
    # commit est desormais a la charge explicite de l'appelant (voir
    # funding_validator.py/emission_validator.py::run()).
    with patch("pipeline.common.kafka_client.KafkaConsumer") as mock_consumer_cls:
        kafka_client.make_consumer("nev.funding.raw", group_id="funding-validator")

    kwargs = mock_consumer_cls.call_args.kwargs
    assert kwargs["enable_auto_commit"] is False
    assert kwargs["group_id"] == "funding-validator"


def test_make_consumer_value_deserializer_decodes_json_utf8():
    with patch("pipeline.common.kafka_client.KafkaConsumer") as mock_consumer_cls:
        kafka_client.make_consumer("nev.funding.raw", group_id="funding-validator")

    kwargs = mock_consumer_cls.call_args.kwargs
    assert kwargs["value_deserializer"](json.dumps({"a": 1}).encode("utf-8")) == {"a": 1}
