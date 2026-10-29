# Dependency automation

Mend Renovate proposes stable Composer and GitHub Actions updates. The policy follows
`Codegenie-BE/codegenie`, adapted to a Composer library and this repository's active
branch protection. Configuration and helper scripts live under `.github/`, which
is excluded from the Composer distribution archive.

## Update policy

- Ordinary updates are scheduled on Monday in `Europe/Brussels` time.
- At most two ordinary branches and PRs are active; PR creation is limited to two per hour.
- Patch/minor releases wait seven days. Majors are separate PRs and wait fourteen days.
- Composer production and development non-major updates are grouped separately.
- Composer ranges are widened rather than pinned or replaced, preserving existing consumers.
  Versions already allowed by an open range are tested by the regular scheduled CI;
  without a tracked lockfile, such releases do not necessarily need a manifest PR.
- Actions updates are grouped separately and use immutable commit-SHA pins.
- Vulnerability-alert updates can bypass the schedule and release-age delay, but not CI.
- No `composer.lock` is committed, and lockfile maintenance is unnecessary.
- Renovate's own `automerge` and `platformAutomerge` remain disabled.

## Verified squash merges

`Renovate verified merge` runs after `Tests`, `Distribution`, or a successful Renovate
commit status. It executes only trusted `main` code, never PR code or PR artifacts.
It fetches evidence through GitHub APIs and requires:

- An open, non-draft `renovate[bot]` PR from a same-repository `renovate/` branch to `main`.
- A dependency-only diff: existing Composer registry ranges may be widened, and existing
  Actions references may change their SHA/version comment. Dependency additions/removals,
  PHP/extension constraints, scripts, workflow logic, config and source changes are manual.
- The branch contains current `main`; the latest PR runs of both validation workflows
  succeeded for the exact current head SHA. Every expected matrix job and critical step
  must pass; skipped/missing jobs and newer failed/in-progress attempts block merging.
- Successful Renovate internal statuses, no requested changes or unresolved review threads,
  and no `hold` or `do-not-merge` label. Incomplete API responses block merging.
- The existing active `Protect main` ruleset (ID `20992379`), strict required `CI gate`
  from GitHub Actions and linear history. The API must explicitly report that the
  executing token can never bypass it (`current_user_can_bypass: never`).

The gate calls GitHub's PR merge API with `merge_method: squash` and the verified head
`sha`. It never updates Git refs directly or bypasses protection. If the head or main
changes, GitHub's head-SHA and strict branch rules reject stale evidence. Renovate must
rebase and both workflows must pass again. Default workflow permissions remain read-only;
only the isolated merge job requests contents/pull-requests write and actions/statuses read.

The existing Laravel 12/13, PHP 8.2–8.5, minimum-dependency, E2E, portability, coverage
and archive tests remain in place. A separate `Proposed dependencies` matrix resolves
each newly introduced Composer range alternative independently, without the old matrix's
Laravel/Testbench/Pest overrides. It runs `composer check:all` on PHP 8.5; an incompatible
or unresolvable new version blocks automatic merging instead of falling back to old versions.
Majors needing a newer PHP runtime or application changes stay open until explicitly fixed
and tested. Changes to the CI matrix must update the trusted gate's expected evidence too.

## GitHub and Mend setup

1. Give the existing Renovate GitHub App access to this repository.
2. In the Mend Developer Portal, enable Renovate and Automated PRs, turn Silent mode off,
   require a config file and disable onboarding PRs. Merge the migration PR before the
   first activation scan, so the checked-in policy is available.
3. Remove `.github/dependabot.yml` and the old Dependabot auto-merge workflow through
   this migration. Disable Dependabot security-update PRs and any auto-triage rule that
   creates them. Keep Dependency Graph and Dependabot alerts enabled for detection.
4. In Actions settings, keep GitHub-created Actions, full-SHA enforcement and
   `shivammathur/setup-php@*` allowed; remove `dependabot/fetch-metadata@*`.
5. Preserve `Protect main`, including its empty bypass list and strict required `CI gate`.
   The ordinary workflow token cannot audit the full admin-only bypass list: the runtime
   gate verifies its own non-bypass permission instead and checks the list too if returned.
   No admin token, extra bypass or approval permission is needed. No deployment or package
   release is part of this migration.
6. Trigger a Mend scan after integration. Confirm the Dependency Dashboard and Composer /
   GitHub Actions extraction, check the job log and confirm only Renovate opens new updates.
   Activation is not complete until those observations are confirmed.

## Pause, recovery and local verification

Add `hold` or `do-not-merge` to pause a PR. Disable `Renovate verified merge` to pause all
automatic merges while leaving discovery enabled. Fix failed tests or dependency resolution;
never bypass them. Rebase an out-of-date branch and wait for fresh validation.

Run helper tests with `node --test .github/scripts/*.test.mjs`. Validate the configuration
using `renovate-config-validator --strict --no-global .github/renovate.json` from a Renovate
installation. The migration PR must also pass the complete existing GitHub validation.
