# B1.6 — Clôture du processor de validation - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Faire en sorte qu'un message malformé/inattendu sur `nev.funding.raw` ou
`nev.emissions.raw` soit mis en quarantaine sur le topic `.rejets` correspondant au lieu de faire
planter le service de validation permanent, et documenter formellement que B1.6 est déjà
substantiellement livré par l'infrastructure construite avec B1.1-B1.5.

**Architecture:** Un `try`/`except Exception` autour de l'appel à `process_message()` dans la
boucle `run()` de chacun des deux processors - identique dans sa forme aux autres chemins de
rejet déjà existants (secteur/pays inconnu), mais couvrant aussi les erreurs non anticipées.

**Tech Stack:** Python 3.12, pytest + `unittest.mock` (aucune nouvelle dépendance).

## Global Constraints

- Aucune nouvelle dépendance Python, aucun changement à `docker-compose.yml` ou
  `pipeline/requirements.txt`.
- La raison de rejet pour une erreur inattendue est le format exact `f"processing_error:{type(exc).__name__}"`
  (ex. `"processing_error:KeyError"`) - distinct des raisons de gouvernance existantes
  (`"unknown_source"`, `"unclassifiable_sector"`, `"unknown_country"`, `"unknown_sector"`).
- Un message d'erreur est imprimé via `print(...)` pour chaque exception inattendue capturée
  (aucune convention de logging n'existe encore ailleurs dans `pipeline/` - ne pas en introduire
  une ici, hors périmètre de cette tâche).
- Le rollback de transaction existant (`with connection:` de psycopg2) ne change pas de
  comportement - le nouveau `try`/`except` capture l'exception seulement après qu'elle en soit
  ressortie.

---

### Tâche 1 : `funding_validator.py` - quarantaine sur erreur inattendue

**Files:**
- Modify: `pipeline/processors/funding_validator.py`
- Test: `pipeline/tests/test_funding_validator_run.py`

**Interfaces:**
- Consumes: `process_message(cursor, message) -> tuple[bool, str | None]` (inchangé, définie dans
  ce même fichier), `make_consumer`/`make_producer` de `pipeline.common.kafka_client`,
  `get_connection` de `pipeline.common.db`.
- Produces: `run()` ne lève plus jamais d'exception pour un message unique en erreur - elle
  publie `{**message, "rejection_reason": "processing_error:<NomException>"}` sur
  `nev.funding.rejets` et continue de consommer les messages suivants.

- [ ] **Step 1: Write the failing test**

Create `pipeline/tests/test_funding_validator_run.py`:

```python
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
    message_1 = {"source": "world_bank", "country_iso": "SEN"}  # deliberately incomplete
    message_2 = {"source": "world_bank", "country_iso": "SEN"}
    fake_consumer = [_fake_kafka_message(message_1), _fake_kafka_message(message_2)]
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/test_funding_validator_run.py -v`
Expected: FAIL - `run()` raises `KeyError: 'amount_usd'` instead of returning normally (no
`try`/`except` around `process_message()` yet).

- [ ] **Step 3: Add the try/except guard**

In `pipeline/processors/funding_validator.py`, replace the `run()` function:

```python
def run() -> None:
    consumer = make_consumer("nev.funding.raw", group_id="funding-validator")
    producer = make_producer()

    for kafka_message in consumer:
        message = kafka_message.value
        connection = get_connection()
        try:
            with connection:
                with connection.cursor() as cursor:
                    accepted, reason = process_message(cursor, message)
        except Exception as exc:
            # A malformed/unexpected message must never crash this permanent
            # service - see the 2026-08-31 B1.6 closure spec. psycopg2's
            # `with connection:` above already rolled back any partial
            # transaction before this exception reached here.
            accepted, reason = False, f"processing_error:{type(exc).__name__}"
            print(f"[funding-validator] unexpected error processing message: {exc!r}")
        finally:
            connection.close()

        if accepted:
            producer.send("nev.funding.valides", message)
        else:
            producer.send("nev.funding.rejets", {**message, "rejection_reason": reason})

    producer.flush()
```

- [ ] **Step 4: Rebuild and run the test again**

Run: `docker compose build funding-validator && docker compose run --rm funding-validator pytest pipeline/tests/test_funding_validator_run.py -v`
Expected: PASS.

- [ ] **Step 5: Run the existing funding_validator suite to confirm no regression**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/test_funding_validator.py -v`
Expected: PASS (all existing tests unchanged - they exercise `process_message()` directly, not
`run()`, so this guard doesn't affect them).

- [ ] **Step 6: Commit**

```bash
git add pipeline/processors/funding_validator.py pipeline/tests/test_funding_validator_run.py
git commit -m "fix: quarantine unexpected processing errors instead of crashing funding-validator"
git pull --rebase
git push
```

---

### Tâche 2 : `emission_validator.py` - quarantaine sur erreur inattendue

**Files:**
- Modify: `pipeline/processors/emission_validator.py`
- Test: `pipeline/tests/test_emission_validator_run.py`

**Interfaces:**
- Consumes: `process_message(cursor, message) -> tuple[bool, str | None]` (inchangé, définie dans
  ce même fichier), `make_consumer`/`make_producer` de `pipeline.common.kafka_client`,
  `get_connection` de `pipeline.common.db`.
- Produces: `run()` ne lève plus jamais d'exception pour un message unique en erreur - elle
  publie `{**message, "rejection_reason": "processing_error:<NomException>"}` sur
  `nev.emissions.rejets` et continue de consommer les messages suivants. Même comportement que la
  Tâche 1, appliqué au domaine émissions.

- [ ] **Step 1: Write the failing test**

Create `pipeline/tests/test_emission_validator_run.py`:

```python
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/test_emission_validator_run.py -v`
Expected: FAIL - `run()` raises `KeyError: 'value_mt'` instead of returning normally.

- [ ] **Step 3: Add the try/except guard**

In `pipeline/processors/emission_validator.py`, replace the `run()` function:

```python
def run() -> None:
    consumer = make_consumer("nev.emissions.raw", group_id="emission-validator")
    producer = make_producer()

    for kafka_message in consumer:
        message = kafka_message.value
        connection = get_connection()
        try:
            with connection:
                with connection.cursor() as cursor:
                    accepted, reason = process_message(cursor, message)
        except Exception as exc:
            # A malformed/unexpected message must never crash this permanent
            # service - see the 2026-08-31 B1.6 closure spec. psycopg2's
            # `with connection:` above already rolled back any partial
            # transaction before this exception reached here.
            accepted, reason = False, f"processing_error:{type(exc).__name__}"
            print(f"[emission-validator] unexpected error processing message: {exc!r}")
        finally:
            connection.close()

        if accepted:
            producer.send("nev.emissions.valides", message)
        else:
            producer.send("nev.emissions.rejets", {**message, "rejection_reason": reason})

    producer.flush()
```

- [ ] **Step 4: Rebuild and run the test again**

Run: `docker compose build funding-validator && docker compose run --rm funding-validator pytest pipeline/tests/test_emission_validator_run.py -v`
Expected: PASS.

- [ ] **Step 5: Run the existing emission_validator suite to confirm no regression**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/test_emission_validator.py -v`
Expected: PASS (all existing tests unchanged - they exercise `process_message()` directly, not
`run()`).

- [ ] **Step 6: Commit**

```bash
git add pipeline/processors/emission_validator.py pipeline/tests/test_emission_validator_run.py
git commit -m "fix: quarantine unexpected processing errors instead of crashing emission-validator"
git pull --rebase
git push
```

---

### Tâche 3 : Documentation README + vérification finale

**Files:**
- Modify: `README.md`

**Interfaces:** n/a - documentation et vérification, aucun nouveau code.

- [ ] **Step 1: Run the full offline pipeline suite**

Run:
```bash
docker compose run --rm funding-validator pytest pipeline/tests/ -m "not live" \
  --ignore=pipeline/tests/test_dag_worldbank_tasks.py \
  --ignore=pipeline/tests/test_dag_gcf_tasks.py \
  --ignore=pipeline/tests/test_dag_afdb_tasks.py \
  --ignore=pipeline/tests/test_dag_pnue_tasks.py \
  --ignore=pipeline/tests/test_dag_extraction_pdf_tasks.py -v
```
Expected: every test green, including the 2 new `test_*_validator_run.py` files (the 5
`test_dag_*_tasks.py` files are excluded here for the same reason documented at README point 34 -
they import `airflow`, only runnable inside the `airflow` container).

- [ ] **Step 2: Add the README subsection**

In `README.md`, inside "## Pipeline (Volet B)", after the "### Refactoring multi-tâches des 5
DAGs (2026-08-31)" subsection, add:

```markdown
### Processor de validation et normalisation (B1.6)

B1.6 demande un "processor de validation et normalisation (devise pivot, upsert, valeurs
manquantes)" appliquant les règles de gouvernance de la section 6.4 du cahier des charges.
Vérification du code réel avant tout nouveau développement : l'essentiel de ce livrable
existe déjà, construit progressivement avec B1.1-B1.5 plutôt que comme tâche isolée -
`funding-validator`/`emission-validator` sont deux services Kafka permanents qui écrivent
directement dans TimescaleDB depuis B1.1, avec déduplication, upsert et historisation SCD2
réels (voir `upsert_funding()`/`upsert_emission()`). La conversion en devise pivot est
satisfaite en résultat (tout est en USD) mais par un mécanisme différent de celui prévu à
l'origine (taux BCE centralisés dans le processor) : aucun connecteur n'a eu besoin de cette
conversion centralisée en pratique - AfDB, seul cas réel de devise étrangère (XDR), convertit
dans son propre collecteur via `open.er-api.com`, la BCE ne cotant pas le XDR (déviation déjà
documentée en B1.3). "Absence ≠ zéro" est garanti au niveau des collecteurs, qui ne publient
jamais de message pour une donnée manquante. Décisions complètes et preuves détaillées :
[`docs/superpowers/specs/2026-08-31-b16-validation-processor-closure-design.md`](docs/superpowers/specs/2026-08-31-b16-validation-processor-closure-design.md).

Un vrai manque de robustesse a été trouvé et corrigé : `run()` n'entourait l'appel à
`process_message()` d'aucun `try`/`except` dans les deux processors - un message malformé
aurait fait planter tout le service permanent au lieu d'être mis en quarantaine comme tout
autre rejet. Corrigé : une exception inattendue publie désormais le message sur le topic
`.rejets` correspondant avec `rejection_reason: "processing_error:<NomException>"`, et le
service continue de consommer les messages suivants.
```

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: close out B1.6 - validation processor already built with B1.1-B1.5, robustness gap fixed"
git pull --rebase
git push
```
