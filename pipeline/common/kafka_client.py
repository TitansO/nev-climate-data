"""Shared Kafka producer/consumer factory helpers for the Volet B pipeline."""
import json
import os

from kafka import KafkaConsumer, KafkaProducer

BOOTSTRAP_SERVERS = os.environ.get("KAFKA_BOOTSTRAP_SERVERS", "kafka:9092")


def make_producer() -> KafkaProducer:
    return KafkaProducer(
        bootstrap_servers=BOOTSTRAP_SERVERS,
        value_serializer=lambda value: json.dumps(value).encode("utf-8"),
        # Audit post-C3 2026-10-01 : sans key_serializer, un appelant ne
        # peut pas passer `key=` a producer.send() (kafka-python exige un
        # serializer des qu'une cle non-bytes est fournie). Necessaire pour
        # que chaque callsite publie desormais avec une cle partitionnante
        # (voir chaque collecteur/DAG) - meme (source, project_id,
        # country_iso)/(source, country_iso, year) tombe toujours sur la
        # meme partition, donc jamais traite concurremment par deux
        # replicas d'un meme groupe de consommateurs, meme si
        # funding-validator/emission-validator sont un jour scales au-dela
        # d'un seul replica (leur logique d'upsert read-then-write n'est
        # pas elle-meme protegee contre une vraie course concurrente).
        key_serializer=lambda key: key.encode("utf-8") if key is not None else None,
    )


def make_consumer(topic: str, group_id: str) -> KafkaConsumer:
    return KafkaConsumer(
        topic,
        bootstrap_servers=BOOTSTRAP_SERVERS,
        value_deserializer=lambda value: json.loads(value.decode("utf-8")),
        group_id=group_id,
        auto_offset_reset="earliest",
        # Audit post-C3 2026-10-01 : enable_auto_commit=True committait
        # l'offset sur une simple base de temps, independamment de la reelle
        # persistance du message en base - un crash entre l'auto-commit et
        # l'ecriture DB perdait silencieusement ce message (semantique
        # au-plus-une-fois). Desormais a la charge de l'appelant (voir
        # funding_validator.py/emission_validator.py::run()) : commit()
        # seulement apres l'ecriture DB reellement actee, jamais avant -
        # semantique au-moins-une-fois (un message peut etre retraite apres
        # un crash, mais process_message()/apply_project_contribution()
        # sont deja idempotents par construction, voir leur docstring).
        enable_auto_commit=False,
    )
