# Documentation index

Reviewed against Proofgen source at `2aae180`, September 13, 2026.

## Maintained guides

| Guide | Purpose |
|---|---|
| [Project README](../README.md) | Setup, runtime, everyday workflow, current status |
| [Contributor notes](../CLAUDE_NOTES.md) | Short handoff and local environment paths |
| [Testing](TESTING.md) | Disposable checkout, guarded database, optional native tests |
| [Photo pipeline](photo-pipeline.md) | Import identity, file handling, generation and upload contracts |
| [Archive backups](archive-backups.md) | Archive layout, audit side effects and repair |
| [Core Image enhancement](core-image-enhancement.md) | Supported adjustments, fallback, encoding and native operation |
| [Image enhancement entry point](image-enhancement.md) | Settings usage; redirects to the maintained technical guide |
| [Ferraraphoto integration](FERRARAPHOTO_INTEGRATION.md) | Verified local delivery versus existing API/storage code |
| [Problem reports from installs](FEEDBACK.md) | `php artisan proofgen:report`: what an LLM session or person on an install does instead of fixing code there; redaction; the private inbox; triage |
| [Upgrading a v1.x install](UPGRADE_FROM_V1.md) | Runbook for a Claude Code session on the old machine: survey, gates, backup, update, connect to the website, verify, rollback |
| [New-server rehearsal](NEW_SERVER_REHEARSAL.md) | Parked local setup, eight staged photos, target settings and mock-show acceptance checks |
| [Local datasets](datasets/README.md) | NAS roots and reproducible sample manifests |
| [Show-prep checklist](SHOW_PREP_CHECKLIST.md) | Worker/install checks and Dad-Mac rehearsal |
| [Show-prep follow-ups](SHOW_PREP_TODO.md) | Completed work and remaining actionable items |

## Historical validation

The files under [reviews](reviews/) and [SHOW_PREP_REVIEW.md](SHOW_PREP_REVIEW.md)
record findings at their dates. Completed fixes may still appear as open findings
in those snapshots. Use the follow-up list and current source for current status.
The [final local report](reviews/2026-09-13-show-prep-finish.md) records 486 passing
tests and the 48-photo/192-output rehearsal; it does not certify Dad's MacBook or
Ferraraphoto's deployed customer workflow.

## Earlier designs

[Storage profiles](STORAGE_PROFILES.md), [API client](PROOFGEN_API_CLIENT.md),
[Ferraraphoto API](FERRARAPHOTO_API_SPEC.md), and
[customer deliverables](CUSTOMER_DELIVERABLES.md) contain prior design material.
Parts now exist in source; future tense and code examples may be stale.
[The migration plan](PROOF_MIGRATION_PLAN.md) has a superseded rollout scope.
Read the current integration guide before using any of these as an implementation
or operational checklist.

## Findings requiring follow-up

- `proofgen:audit --persist-issues=false` is declared but not honored by the
  command/service. Audits can still write issue rows. The active guides now state
  that behavior; wiring up the flag is tracked in SHOW_PREP_TODO.
- `external-docs/fluxui/index.md` is absent from this checkout. Agent instructions
  now explain this instead of claiming the reference bundle is present.
- The updater exists, but its tag selection means a commit on main need not be the
  version installed by the UI. [Flower #3682](https://flower.legitphp.com/briefs/3682)
  remains the place for its review.

This pass changes documentation and preserves a sample manifest. It does not run
an audit, migrate data, update services, or change application behavior.
