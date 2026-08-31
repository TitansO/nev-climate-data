"""Tests for emission_validator's run() loop - specifically the try/except
guard around process_message() that quarantines a message whose processing
raises an unexpected exception, instead of crashing the whole permanent
consumer service. See the 2026-08-31 B1.6 closure spec. process_message()'s
own logic (country resolution, replace-not-sum upsert) is already covered by
pipeline/tests/test_emission_validator.py against a real DB transaction, and
is mocked here.
"""
from unittest.mock import MagicMock, patch

from pipeline.processors.emission_validator import run


def _fake_kafka_message(value):
    return MagicMock(value=value)


def test_run_quarantines_a_message_that_raises_an_unexpected_exception_and_keeps_consuming():
    message_1 = {"source": "pnue", "country_iso": "SEN"}  # deliberately incomplete
    message_2 = {"source": "pnue", "country_iso": "SEN"}
    fake_consumer = [_fake_kafka_message(message_1), _fake_kafka_message(message_2)]
    mock_producer = MagicMock()
    mock_connection = MagicMock()

    with patch("pipeline.processors.emission_validator.make_consumer", return_value=fake_consumer), \
         patch("pipeline.processors.emission_validator.make_producer", return_value=mock_producer), \
         patch("pipeline.processors.emission_validator.get_connection", return_value=mock_connection), \
         patch("pipeline.processors.emission_validator.process_message") as mock_process:
        mock_process.side_effect = [KeyError("value_mt"), (True, None)]
        run()  # must not raise

    assert mock_producer.send.call_count == 2
    first_call = mock_producer.send.call_args_list[0]
    assert first_call[0][0] == "nev.emissions.rejets"
    assert first_call[0][1]["rejection_reason"] == "processing_error:KeyError"
    second_call = mock_producer.send.call_args_list[1]
    assert second_call[0][0] == "nev.emissions.valides"
    mock_producer.flush.assert_called_once()
