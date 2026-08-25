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

- **Branch:** `developp`, commit `c275f8d`, clean, pushed, matches `origin/developp`.
- **Done:** A1.1 (Oumar), A1.2 (Oumar), A1.3 (schéma TimescaleDB, 8 entités + enums, 3
  migrations), A1.4 (auth JWT), A1.5 (Oumar — gestion des clés API), A1.6 (Oumar — fixtures
  de démonstration), A1.7 (pipeline CI/CD GitLab — build/tests, publication d'image sur le
  Container Registry, **pas** de déploiement).
- **Next task: A1.8 — Recette bout-en-bout Auth → API → Base de données.** Dependencies
  (A1.4, A1.5, A1.6, A1.7) are all done — nothing blocks starting it. Not yet started: no
  spec, no plan written for it yet.
- **In progress right now:** nothing. The repo is in a clean, fully-verified state between
  tasks — whoever picks this up next is starting A1.8 from scratch, not resuming
  something half-done.

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
