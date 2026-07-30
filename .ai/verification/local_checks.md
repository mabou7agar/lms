# Local Checks (Gate Definition)

The gates every wave must pass before it is "done". Run from `corelms/`. Confirm exact
script names against `apps/api/composer.json` and `apps/web/package.json` (config files that
back these are present in the repo, noted per line). Windows note: the repo lives on an NTFS
`D:\` path bind-mounted into containers — running gates inside the dev container is faster.

## Backend — `apps/api`
- Format:        `vendor/bin/pint --test`            (config: `pint.json`)
- Static (Larastan/PHPStan): `vendor/bin/phpstan analyse`  (config: `phpstan.neon` + `phpstan-baseline.neon`, `phpstan-architecture.neon`) — do NOT lower level or grow baseline
- Architecture:  `vendor/bin/deptrac analyse`         (config: `deptrac.yaml` + `deptrac.baseline.yaml`) — no new violation
- Tests (Pest/PHPUnit): `php artisan test` or `vendor/bin/pest`  (config: `phpunit.xml`)
- ADR link check: `scripts/adr-link-check.sh`         (also enforced by `.github/workflows/adr-validation.yml`)
- OpenAPI: regenerate + assert no breaking diff (per canonical PR checklist)

## Frontend — `apps/web`
- Lint:      `npm run lint`     (config: `eslint.config.mjs`; Prettier)
- Types:     `npx tsc --noEmit`
- Unit:      `npm run test` / `vitest run`   (config: `vitest.config.ts`)
- E2E:       `npx playwright test`           (config: `playwright.config.ts`)
- A11y:      axe checks (run within the E2E/story suite)
- Build:     `npm run build`
- Storybook: build must succeed
- Lighthouse: `apps/web/lighthouse.report.json` is the last captured run (mobile-throttled)

## Security / CI
- Trivy container scan on the web image (CVE gate; `.trivyignore` documents accepted exceptions)
- Secret scan (no secret committed)
- GitHub Actions: seven mandatory jobs must be green (last recorded full green: CI run #13, commit 89e57e7)

## Recording results
After a run, append an entry to `gate_history.json` (newest last). Only record runs actually
executed/observed — never fabricate a green.
