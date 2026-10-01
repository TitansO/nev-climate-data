<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Audit post-C3 2026-10-01 : symfony/rate-limiter cree automatiquement son
// propre pool de cache dedie, "cache.rate_limiter" (backe par le filesystem,
// distinct de "cache.app" - verifie via `cache:pool:list`), pour tous les
// limiters de config/packages/rate_limiter.yaml (register_anonymous,
// login_throttling, api_anonymous, export_*, ai_ask_*...). Ce pool persiste
// donc sur disque au-dela de la fin de ce process PHP. Plusieurs tests en
// dependent VOLONTAIREMENT au sein d'une meme execution
// (AiControllerTest::testAskQuotaIsEnforcedByRole,
// AuthenticationControllerTest::testSixthFailedLoginAttemptIsThrottled,
// FundingExportTest::testExportQuotaIsEnforcedByRole...) : chaque requete y
// reboote le kernel pour simuler une vraie requete HTTP separee, et seule
// cette persistance sur disque permet au quota de s'accumuler correctement
// entre ces reboots. Mais relancer la suite complete deux fois dans la
// meme fenetre glissante (ex: 5/heure pour register_anonymous, 1 jour pour
// export_*/ai_ask_*) fait herite la deuxieme execution du quota deja
// consomme par la premiere, ce qui transforme des tests par ailleurs
// inchanges en echecs 429 fantomes - constate en direct lors de l'audit
// (voir aussi le commentaire de testSixthFailedLoginAttemptIsThrottled, qui
// documente le meme probleme pour login_throttling specifiquement). Vider
// ce pool UNE SEULE FOIS ici, avant que la suite ne demarre, donne un etat
// propre a chaque lancement de bin/phpunit sans rien changer au
// comportement a l'interieur d'une meme execution.
passthru(sprintf(
    'php %s cache:pool:clear cache.rate_limiter --env=test --no-interaction > /dev/null 2>&1',
    escapeshellarg(dirname(__DIR__).'/bin/console'),
));
