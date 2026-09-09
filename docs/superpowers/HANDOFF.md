# Handoff — NEV Climate Data

**Read this file first, before starting any work.** This is the single source of truth for
"where are we, and what's next" — shared between whichever assistant is currently working
(Claude Code, Antigravity, or any other). Update it before stopping, whether you finished a
task or hit a usage limit mid-task.

Everything else that matters is already committed to git and readable by any assistant:
- `docs/superpowers/specs/*.md` — design decisions, with rationale, for each completed task
- `docs/superpowers/plans/*.md` — the step-by-step implementation plan for each completed task
- `README.md` — setup instructions, architecture, "Points d'attention" (gotchas already hit
  and fixed — read this before touching auth, migrations, or the CI pipeline), and the
  "État d'avancement" table
- Git commit messages and history on `developp`

**Not shared, not reliable across machines/tools:** anything under `.superpowers/sdd/` is
local scratch (execution ledgers for one task's implementation), gitignored on purpose, and
gets deleted once a plan finishes. If you're not on the same machine/checkout as whoever ran
that task, it won't be there — don't wait on it or search for it as a record of past work.

## Current state (as of this entry)

- **Branch:** `developp`, commit `4ca8c66` + the T.1/T.2/T.3 docs commit on top, clean,
  pushed to **both** remotes (GitLab canonical `nev-consulting-group/nev-climate-data`,
  GitHub mirror `Odjiguimde/nev-climate-data` — the mirror exists only to feed GitHub
  Codespaces).
- **Volet A (Phases A1 + A2 + A3) is functionally complete.**
  - A1 (Fondations) **CLOSED** — formally recetted and signed
    (`docs/superpowers/specs/2026-08-25-a18-phase-a1-recette.md`).
  - A2 (A2.1–A2.14) complete — recette `docs/superpowers/specs/2026-08-29-a214-phase-a2-recette.md`,
    awaiting the Product Owner's written signature.
  - A3 (A3.1–A3.10) complete — Mercure realtime, full React migration, Symfony secrets vault,
    general rate limiting, coverage audit, automated DB backups + tested restore, k6 load
    tests, **real production go-live (Render API + own PostgreSQL / Netlify frontend, both
    HTTPS, auto-deploy on `developp`)**, Swagger (28 operations). Recette
    `docs/superpowers/specs/2026-09-02-a310-phase-a3-recette.md` — **proposée, awaiting the
    Product Owner's written signature.**
  - Off-plan: A3.11 institutional UI/UX overhaul (design system `--nev-*`, 12 shared
    components, 13 pages restyled).
- **Volet B — B1 (connectors) + B3 (real-data cutover) done; B2 (GreenAccess) still blocked.**
  - Shared architecture: `docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`.
    Reference-only pattern material: `pipeline-observatoire-cima.pdf` (Serge's own prior work
    on an unrelated project — never shared infrastructure).
  - **B1.1–B1.10 complete** — 5 real connectors (World Bank, GCF via IATI, AfDB via IATI with
    real XDR→USD conversion, PNUE via the UN SDG API, OPEC Fund PDF via Gemini with SHA-256
    cache), validation/normalisation processor, source-conflict handling + SCD2 historisation,
    Kafka/MinIO isolation vs CIMA, robust Airflow DAGs (`extraire >> transformer >> publier`,
    quarterly `0 3 1 1,4,7,10 *`), email alerting. Recette
    `docs/superpowers/specs/2026-09-01-b110-phase-b1-recette.md` — awaiting PO signature.
  - **B3.1–B3.6 complete** (run *before* B2, at the user's explicit request, to prove the
    stack works on real data): real aggregates on the existing endpoints (3 real bugs fixed —
    points #52–#54); `AnalyticsService::getCo2Reduction()` rewired onto the real `Emission`
    table + `co2ReductionMt` in the Mercure payload (B3.2); systematic
    `validation_status = 'validated'` read filter across `FundingRepository`,
    `EmissionRepository`, `getHeroStats()` (B3.3); 5 aggregates cross-checked against direct
    SQL, exact match, + NaN emission bug found & fixed in `pipeline/collectors/pnue.py` (B3.4);
    DAG idempotence verified, 0 dup / 0 loss (B3.5). Recette
    `docs/superpowers/specs/2026-09-08-b36-recette-finale-volet-b.md` — **proposée, awaiting PO
    signature.** Off-plan in B3: real local-currency conversion (`pipeline/common/fx_rates.py`),
    date-range filter fix, `funding-validator`/`emission-validator` image rebuild.
  - B2.1 scoping note `docs/note-de-cadrage-greenaccess-b2.1.md` — ready to send once a named
    recipient is confirmed; nothing downstream in B2 starts before it is signed.
  - `Cartographie_Sources_Donnees_Climat_Senegal.pdf` (~12 extra sources) is explicitly set
    aside on Serge's instruction — not a source of truth for B1 scope.
- **Transverse tasks (T):**
  - **T.1 done** — `docs/exploitation/runbooks.md` (operational procedures) authored; this
    HANDOFF refreshed; README « État d'avancement » gains a « Tâches transverses (T) » block.
  - **T.2 done** — `docs/exploitation/suivi-couts.md` (monthly cost-tracking table + risks +
    optimisation levers). Current config runs at ~0 USD/month; the real exposure is quota /
    free-tier-expiry risk, not billing.
  - **T.3 done** — `docs/exploitation/formation-equipe.md` (the training support itself:
    objectives, per-audience tracks, modules, hands-on exercises, glossary, session log).
    The deliverable is « atteint » once at least one session per audience is run and logged
    in that file's §10 — **sessions not yet held.**
- **Codespace:** `turbo-journey-g4gvr455pp9hvrpw` on `Odjiguimde/nev-climate-data`, 2-core /
  8 GB (undersized vs requested), auto-sleeps after ~30 min. `gh` not installed on it;
  system `python3` broken. Full 15-service stack has been run there successfully this cycle.
- **Next task:** per the plan, **Phase B2 (GreenAccess connector)** — blocked until the B2.1
  scoping note has a confirmed recipient and is signed. Each B1/B2 task still gets its own
  spec then plan then code.
- **In progress right now:** nothing. Repo clean between tasks.

### Standing manual action items (not code — the user must do these)

1. `DELETE FROM emission WHERE value_mt::text = 'NaN';` on **dev and production** (12
   South-Sudan rows, 2000–2011). Root cause fixed in code; the stored rows are not.
2. Rotate the production DB password (found exposed in a compose override; rotation deferred
   by the user).
3. Obtain the Product Owner's written signature on recettes **A3.10** and **B3.6** (B1.10
   also pending).
4. Re-run `pipeline/tests/test_dag_*_tasks.py` on a properly sized environment (skipped in
   the 2026-09-08 session — Codespace too small).
5. Hold and log the T.3 training sessions (`docs/exploitation/formation-equipe.md` §10).

## Conventions this project has settled on (don't relitigate these without asking Serge)

- **Naming: English** for all code (classes, properties, DB columns), even though the
  cahier des charges is in French. See `docs/superpowers/specs/2026-08-22-a13-*.md` decision 1.
- **Direct commits to `developp`, no feature branches for finished work.** This requires
  Maintainer role on GitLab (Serge has it now). Feature/throwaway branches are only used for
  something that genuinely can't be verified any other way before touching `developp` — e.g.
  A1.7 used `ci/a17-pipeline-verification` to test the CI pipeline itself without risking a
  broken push to the shared branch, then deleted it once the real `developp` version was
  verified.
- **Every non-trivial task gets a spec (design doc) before a plan, and a plan before code.**
  Spec: architectural decisions with rationale, written after clarifying questions, approved
  by Serge. Plan: bite-sized TDD tasks with exact code. Both saved under
  `docs/superpowers/` and committed. Don't skip straight to code on anything that involves a
  real design choice (new entity, new endpoint, new infra) — small config tweaks and typo
  fixes don't need this ceremony.
- **GitLab CI pipeline status cannot be checked by either assistant programmatically** — no
  API token is configured, and the project is private. Verifying a pipeline went green (or
  that an image landed in the Container Registry) requires asking Serge to check the GitLab
  UI and report back. Don't claim a pipeline passed without that confirmation, and don't try
  to curl the GitLab API for it — it won't work and will waste a turn.
- **TDD discipline**: write the failing test, watch it fail, implement, watch it pass, then
  commit. Applies to entities, controllers, and functional/integration tests alike.
- **Push once a task (or a whole plan) is verified working**, not after every single local
  commit — but don't stockpile unpushed work indefinitely either. If you're stopping (task
  done, or hitting a usage limit), push what's verified before you stop, so the next
  assistant starts from a pushed, known-good `developp`, not from someone else's local-only
  commits they can't see.

## Handoff protocol

**Starting work:**
1. `git fetch origin && git status` — confirm your local `developp` matches `origin/developp`
   with no surprise divergence. If it doesn't, stop and figure out why before writing code.
2. Read this file's "Current state" section.
3. If starting a new task: check `Plan_Implementation_NEV_Climate_Data.xlsx` for the task's
   exact scope/dependencies, and follow the spec-then-plan-then-code sequence above.
4. If resuming a task someone else left mid-way: read the relevant
   `docs/superpowers/specs/` and `docs/superpowers/plans/` files for that task, then check
   `git log` on `developp` to see which plan steps already have commits — don't redo work
   that's already there and already pushed.

**Stopping work (task done, or hitting a usage limit):**
1. Make sure everything verified is committed AND pushed to `origin/developp` — not left
   local-only, since the next assistant may be in a different environment.
2. Update this file's "Current state" section: what's done, what's next, and — if you're
   stopping mid-task — exactly which step you were on and what the immediate next action is
   (not "continue the plan," but the specific command or decision that comes next).
3. Commit and push this file along with (or right after) whatever code you finished.
