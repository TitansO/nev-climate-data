# B1.9 — Alerting réel sur échec des DAGs Volet B

Status: Approved
Author: Serge (with Claude)
Date: 2026-09-01
Plan reference: B1.9 (Phase B1 — Connecteurs sources officielles), `Plan_Implementation_NEV_Climate_Data.xlsx`
Architecture reference: 5 DAGs déjà livrés (B1.1-B1.5), tous avec `retries: 3` /
`retry_delay: timedelta(minutes=5)` dans leur `default_args` depuis leur création.

## État vérifié avant conception

- **Retries** : déjà réels sur les 5 DAGs (`collecte_worldbank`, `collecte_gcf`, `collecte_afdb`,
  `collecte_pnue`, `extraction_pdf`) depuis leur livraison respective.
- **Alerting** : vérifié en direct - aucune configuration SMTP, aucun webhook, aucun
  `on_failure_callback` nulle part dans `docker-compose.yml` ni dans `pipeline/dags/`. C'est
  exactement le manque déjà identifié dans le roadmap.

## Décision 1 — Canal : email, via Gmail SMTP

Confirmé par Serge. Nécessite un vrai mot de passe d'application Gmail (fourni, stocké dans
`.env` local uniquement - `AIRFLOW_ALERT_EMAIL`, `AIRFLOW_SMTP_PASSWORD` - jamais commité, même
traitement que `GEMINI_API_KEY`/`IATI_API_KEY`).

Configuration SMTP native d'Airflow (`AIRFLOW__SMTP__*`) dans le service `airflow` de
`docker-compose.yml` :

```yaml
AIRFLOW__SMTP__SMTP_HOST: smtp.gmail.com
AIRFLOW__SMTP__SMTP_STARTTLS: "True"
AIRFLOW__SMTP__SMTP_SSL: "False"
AIRFLOW__SMTP__SMTP_PORT: "587"
AIRFLOW__SMTP__SMTP_USER: ${AIRFLOW_ALERT_EMAIL}
AIRFLOW__SMTP__SMTP_PASSWORD: ${AIRFLOW_SMTP_PASSWORD}
AIRFLOW__SMTP__SMTP_MAIL_FROM: ${AIRFLOW_ALERT_EMAIL}
```

## Décision 2 — Alerte seulement après épuisement des retries, pas à chaque tentative

Airflow distingue nativement `email_on_retry` (une alerte à *chaque* tentative avant succès -
bruyant, une source lente/rate-limitée comme AfDB en génèrerait à chaque run un peu chargé) de
`email_on_failure` (une alerte seulement quand la tâche a épuisé ses 3 tentatives et échoue pour
de bon). On veut la seconde, pas la première - "détecter rapidement les échecs", pas être notifié
d'une lenteur transitoire déjà gérée par le retry existant.

## Décision 3 — Un seul module partagé, pas 5 `default_args` dupliqués

Les 5 DAGs ont aujourd'hui le même bloc `default_args` dupliqué mot pour mot. Puisque cette tâche
le modifie déjà dans les 5 fichiers, en profiter pour le centraliser dans un nouveau
`pipeline/common/alerting.py` plutôt que de dupliquer une 3e valeur (`email_on_failure`,
`email_on_retry`, `email`) cinq fois de plus :

```python
ALERT_EMAIL = os.environ.get("AIRFLOW_ALERT_EMAIL", "")

default_args = {
    "owner": "nev-climate-data",
    "retries": 3,
    "retry_delay": timedelta(minutes=5),
    "email_on_failure": bool(ALERT_EMAIL),
    "email_on_retry": False,
    "email": [ALERT_EMAIL] if ALERT_EMAIL else [],
}
```

`email_on_failure` dérivé de la présence réelle de `ALERT_EMAIL` (pas codé en dur à `True`) : dans
un environnement sans cette variable définie (ex. CI), Airflow ne tente pas d'envoyer un mail à
une adresse vide - dégradation silencieuse plutôt qu'une erreur SMTP au démarrage.

Chaque DAG importe `from pipeline.common.alerting import default_args` au lieu de définir son
propre dictionnaire.

## Décision 4 — Pas de détecteur dédié de "changement de structure"

Un vrai changement de structure de source (un champ renommé/retiré côté API) se manifeste déjà
comme une exception Python réelle pendant le parsing (`KeyError`, erreur de validation...) - la
tâche échoue, les 3 retries n'y changent rien (rejouer une réponse toujours mal formée ne résout
rien), et `email_on_failure` alerte après le 3e échec, exactement le comportement souhaité. Pas de
mécanisme de détection dédié à construire - ce serait de la machinerie spéculative pour un
problème déjà couvert par le mécanisme générique.

## Testing approach

- Vérification directe du SMTP configuré : `airflow.utils.email.send_email` invoqué une fois en
  direct depuis le conteneur `airflow`, confirmation qu'un vrai email arrive sur
  `nevserviceinformatique@gmail.com`.
- Vérification bout-en-bout réelle du déclenchement : un DAG de test jetable (non commité, un seul
  usage), avec le même `default_args` partagé mais `retries` réduit à 0 pour aller vite, dont
  l'unique tâche échoue systématiquement - déclenché réellement, confirmation qu'un email d'échec
  arrive et correspond au sujet/contenu attendus.
- Confirmation que les 5 DAGs de production importent bien le nouveau `default_args` partagé
  (`grep` sur les 5 fichiers) et que leur comportement de retry ne change pas (toujours 3 tentatives,
  5 minutes).

## Documentation

`README.md` gagne une sous-section documentant le canal d'alerte choisi et son fonctionnement
(alerte uniquement après épuisement des retries), dans "Pipeline (Volet B)". `.env.example` gagne
les 2 nouvelles variables (`AIRFLOW_ALERT_EMAIL`, `AIRFLOW_SMTP_PASSWORD`) avec une note expliquant
comment générer un mot de passe d'application Gmail.
