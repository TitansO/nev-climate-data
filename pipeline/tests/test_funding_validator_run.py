"""Tests for funding_validator's run() loop - specifically the try/except
guard around process_message() that quarantines a message whose processing
raises an unexpected exception, instead of crashing the whole permanent
consumer service. See the 2026-08-31 B1.6 closure spec. process_message()'s
own logic (sector mapping, dedup upsert) is already covered by
pipeline/tests/test_funding_validator.py against a real DB transaction, and
is mocked here.
"""
from unittest.mock import MagicMock, patch

from pipeline.processors.funding_validator import run


def _fake_kafka_message(value):
    return MagicMock(value=value)


def test_run_quarantines_a_message_that_raises_an_unexpected_exception_and_keeps_consuming():
    # "project_id" is required by run()'s own _message_key() (partitioning
    # key, audit post-C3 2026-10-01) even for a message that process_message()
    # will reject - every real message carries it regardless of source (see
    # _message_key's docstring), so these otherwise-deliberately-incomplete
    # test messages keep it too.
    message_1 = {"source": "world_bank", "country_iso": "SEN", "project_id": "P-TEST"}  # deliberately incomplete
    message_2 = {"source": "world_bank", "country_iso": "SEN", "project_id": "P-TEST"}
    # MagicMock, not a plain list: run() calls consumer.commit() after each
    # message (audit post-C3 2026-10-01, manual-offset-commit fix) - a plain
    # list has no .commit() and would raise AttributeError.
    fake_consumer = MagicMock()
    fake_consumer.__iter__.return_value = iter([_fake_kafka_message(message_1), _fake_kafka_message(message_2)])
    mock_producer = MagicMock()
    mock_connection = MagicMock()

    with patch("pipeline.processors.funding_validator.make_consumer", return_value=fake_consumer), \
         patch("pipeline.processors.funding_validator.make_producer", return_value=mock_producer), \
         patch("pipeline.processors.funding_validator.get_connection", return_value=mock_connection), \
         patch("pipeline.processors.funding_validator.process_message") as mock_process:
        mock_process.side_effect = [KeyError("amount_usd"), (True, None)]
        run()  # must not raise

    assert mock_producer.send.call_count == 2
    first_call = mock_producer.send.call_args_list[0]
    assert first_call[0][0] == "nev.funding.rejets"
    assert first_call[0][1]["rejection_reason"] == "processing_error:KeyError"
    second_call = mock_producer.send.call_args_list[1]
    assert second_call[0][0] == "nev.funding.valides"
    mock_producer.flush.assert_called_once()
    assert fake_consumer.commit.call_count == 2
