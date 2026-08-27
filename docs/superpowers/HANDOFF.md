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

- **Branch:** `developp`, commit `0e2aa3d`, clean, pushed, matches `origin/developp`.
- **Phase A1 (Fondations) is CLOSED** — formally recetted and signed
  (`docs/superpowers/specs/2026-08-25-a18-phase-a1-recette.md`).
- **Phase A2 (Oumar, via Antigravity) is in progress: A2.1–A2.6 done** (funding API with
  filters/pagination/CORS, frontend wired to real data, CSV export, per-user notifications,
  cached analytics endpoints, frontend charts). **A2.7–A2.14 are not started.**
- **Phase A3 (Oumar): not started at all.** Zero commits, including **A3.10 itself** (the
  formal Volet A recette + written client sign-off that the cahier des charges, sections 2
  and 10, requires before Volet B may begin).
- **Volet B (Serge) started anyway, as an explicit, informed decision — not an oversight.**
  On 2026-08-27, told plainly that A3.10 does not yet exist, Serge chose to proceed with B1/B2
  in parallel with Oumar finishing A2/A3, rather than wait. This is documented here so it
  reads as a conscious call, not a missed governance gate. Two things already exist for
  Volet B, done *before* this decision (pure design/coordination, not blocked by anything):
  - `docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md` — shared
    architecture for all of B1+B2 (own Kafka/Airflow/MinIO/Redis stack, `pipeline/` directory,
    topic conventions, upsert+historization approach, ECB FX rates for the pivot currency).
    Reference material used to write it: `pipeline-observatoire-cima.pdf` (Serge's own prior
    work on an unrelated project, reused as a pattern only — never as shared infrastructure).
  - `docs/note-de-cadrage-greenaccess-b2.1.md` — the GreenAccess scoping note (B2.1), ready to
    send once a named recipient is confirmed; nothing downstream in B2 starts before it's
    signed.
  - A second sourcing document (`Cartographie_Sources_Donnees_Climat_Senegal.pdf`, ~12
    additional data sources beyond the plan's 5 B1 tasks, and notably omitting PNUE which
    *is* in the plan as B1.4) was shared by Serge and explicitly set aside on his instruction
    — not in scope, not to be acted on, until he brings it back after further research. Don't
    treat it as a source of truth for B1 scope.
- **Next task:** B1.1 (Banque Mondiale connector) — not yet spec'd. Per the architecture spec,
  each B1/B2 task still gets its own implementation plan on top of the shared architecture
  before any code is written.
- **In progress right now:** nothing. The repo is in a clean, fully-verified state between
  tasks.

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
