# Problèmes connus — suite PHPUnit backend

> Découverts le 2026-08-29 en vérifiant le baseline de tests avant la tâche B1.4.
> **Indépendants de B1.4** : confirmé par `git stash` (mêmes chiffres avec et sans les
> fichiers B1.4). Documentés ici sur décision de Serge (« continuer B1.4, traiter ces bugs
> plus tard »). La suite `pipeline/` (pytest) n'est **pas** concernée — elle est verte
> (60 passed).

## Bug 1 — `docker compose exec backend php bin/phpunit` tourne en `APP_ENV=dev`

### Symptôme
Lancé tel quel, `php bin/phpunit` s'exécute avec l'environnement `dev` (et la base de
données `dev`) au lieu de `test`, alors que `backend/phpunit.dist.xml` contient pourtant :

```xml
<server name="APP_ENV" value="test" force="true" />
```

### Cause
Le conteneur `backend` a `APP_ENV=dev` dans son environnement (`docker-compose.yml`).
Symfony Dotenv (`bootEnv`, via `tests/bootstrap.php`) voit cette variable déjà présente au
niveau du process ; le `force="true"` sur `<server>` de PHPUnit ne réécrit pas une valeur
déjà positionnée à ce niveau. Résultat : `APP_ENV` reste `dev` au runtime des tests.

### Contournement (utilisé pour B1.4)
Passer l'environnement explicitement sur la commande `exec` :

```bash
docker compose exec -e APP_ENV=test backend php bin/phpunit
```

### Portée
**Invocation locale uniquement.** Le job CI (`.gitlab-ci.yml`, job `phpunit`) définit
`APP_ENV: test` comme variable de job — il n'est pas affecté par ce bug.

### Piste de correction (plus tard)
Ajouter `-e APP_ENV=test` dans une cible Make / un script wrapper, ou retirer `APP_ENV` de
l'environnement du conteneur `backend` au profit d'un `.env` non commité.

---

## Bug 2 — Isolation de tests cassée dans la suite Controller / Integration

### Symptôme
La suite n'est verte dans **aucune** configuration de base de données, et les compteurs
varient selon l'état résiduel de PostgreSQL / Redis / clés JWT :

| Config base de test | Résultat (144 tests) |
|---|---|
| Fixtures démo chargées (`doctrine:fixtures:load --env=test`) | **64 erreurs, 2 échecs** |
| Migrée seulement, sans fixtures (= ce que fait la CI) | **2 erreurs, ~26–36 échecs** (dépend de l'ordre / de l'état Redis) |

### Causes (plusieurs, cumulées)

1. **Collision `country.iso_code` (les 64 erreurs).**
   Les tests fonctionnels s'auto-alimentent dans une transaction, ex.
   `FundingControllerTest::seedDataset()` fait `new Country('Senegal', 'SEN', …)`.
   `Integration/SchemaLayer1Test` crée aussi son propre pays `'SEN'`. Si les fixtures démo
   sont déjà chargées (donc committées) dans la base de test, ces INSERT violent l'index
   unique `uniq_5373c96662b6a45e` (`country(iso_code)`). Le `beginTransaction()` /
   `rollBack()` par test n'isole pas des lignes committées avant le test.

2. **Tests d'auth / notifications / export qui dépendent de données absentes (sans fixtures).**
   La CI ne charge pas de fixtures (`script:` = `migrations:migrate` puis `phpunit`, aucun
   `fixtures:load`). Sans utilisateurs de fixtures, `AuthenticationControllerTest`,
   `NotificationControllerTest`, `FundingExportTest`, `ApiKeyControllerTest` échouent en
   cascade (401 au lieu de 200/201/204).

3. **État partagé Redis entre tests.** Le rate-limiter de login persiste ses compteurs dans
   Redis ; `testLoginWithWrongPasswordFails` et consorts renvoient alors `429` au lieu de
   `401` parce qu'un test précédent a déjà déclenché le throttle. Pas de `FLUSHALL` (ni de
   cache Redis vidé) en `setUp()`.

### Portée / statut CI
Le job CI `phpunit` correspond à la ligne « migrée seulement, sans fixtures » du tableau
(2 erreurs, ~26+ échecs). **Le statut réel du pipeline GitLab doit être vérifié par Serge**
(pas d'accès API — cf. `docs/superpowers/HANDOFF.md`). Hypothèse : le job `phpunit` est
rouge depuis l'ajout de ces tests fonctionnels ; tout le travail B1.x récent a atterri dans
`pipeline/` (pytest, vert), pas dans `backend/`.

### Piste de correction (plus tard — chantier à part entière)
- Introduire une vraie isolation transactionnelle : `dama/doctrine-test-bundle`
  (`StaticDriver::setKeepStaticConnections(true)` + wrapper transactionnel automatique par
  test), ou recréation du schéma par test.
- Vider Redis (`FLUSHALL`) et le pool `cache.analytics` dans un `setUp()` de classe de base
  commune.
- Décider une bonne fois : fixtures chargées dans la base de test **ou** auto-seed par test,
  pas les deux. Aligner la CI en conséquence (`fixtures:load` dans le `script:` si on choisit
  les fixtures).
- Faire échouer explicitement le pipeline sur la suite phpunit rouge (retirer tout
  `allow_failure` éventuel) une fois vert.

---

## Baseline B1.4 retenu

Pour B1.4, le baseline de non-régression est **64 erreurs / 2 échecs** (fixtures chargées,
`-e APP_ENV=test`). `git stash` confirme que l'entité `Emission`, son repository, la ligne
`SourceFixtures` et la migration n'ajoutent aucun test cassé à ce total. La validation
end-to-end réelle de B1.4 passe par la suite `pipeline/` (pytest), verte.
