$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw "artisan not found in $repo" }
Set-Location $repo
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = Split-Path $Path -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $t = $Content.Replace("`r`n", "`n").TrimEnd("`n") + "`n"
    [System.IO.File]::WriteAllText($Path, $t, $utf8)
}
function Tail([string]$text, [int]$n) {
    $lines = $text -split "`r?`n"
    if ($lines.Count -le $n) { return $text }
    return ($lines[($lines.Count - $n)..($lines.Count - 1)] -join "`n")
}

$report = New-Object System.Collections.Generic.List[string]
function Finish {
    Write-Host ($report -join "`n")
    Set-Clipboard -Value ($report -join "`r`n")
    Write-Host 'Report copied'
    Read-Host 'Press Enter to close'
}

# Never overwrite an existing workflow: write a proposal next to it instead.
$ciPath = Join-Path $repo '.github\workflows\ci.yml'
$ciTarget = if (Test-Path $ciPath) { '.github\workflows\ci.proposed.yml' } else { '.github\workflows\ci.yml' }

Write-NoBom $ciTarget @'
name: CI

# 16.3: both sides of the API seam gate merges. Playwright E2E (15.6) and Vitest (15.3) join later.
on:
  push:
    branches: [main, master]
  pull_request:

concurrency:
  group: ci-${{ github.ref }}
  cancel-in-progress: true

jobs:
  backend:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_USER: postgres
          POSTGRES_PASSWORD: postgres
          POSTGRES_DB: crm_erp_test
        ports: ['5432:5432']
        options: >-
          --health-cmd "pg_isready -U postgres"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10
    env:
      APP_ENV: testing
      DB_CONNECTION: pgsql
      DB_HOST: 127.0.0.1
      DB_PORT: 5432
      DB_DATABASE: crm_erp_test
      DB_USERNAME: postgres
      DB_PASSWORD: postgres
      CACHE_STORE: array
      QUEUE_CONNECTION: sync
      SESSION_DRIVER: array
      BROADCAST_CONNECTION: 'null'
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: pdo_pgsql, pgsql, mbstring, intl, gd, zip, bcmath
          coverage: none
      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --no-progress
      - name: Prepare environment
        run: |
          if [ ! -f .env ]; then cp .env.example .env; fi
          php artisan key:generate --no-interaction
      - name: Pint (style)
        run: |
          if [ -f vendor/bin/pint ]; then vendor/bin/pint --test; else echo "pint not installed, skipped"; fi
      - name: Larastan (static analysis)
        run: |
          if [ -f vendor/bin/phpstan ] && { [ -f phpstan.neon ] || [ -f phpstan.neon.dist ]; }; then vendor/bin/phpstan analyse --no-progress --memory-limit=1G; else echo "phpstan not configured, skipped"; fi
      - name: Composer audit (advisory, not yet blocking)
        continue-on-error: true
        run: composer audit --no-interaction
      - name: Pest
        run: php artisan test

  frontend:
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: frontend
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm
          cache-dependency-path: frontend/package-lock.json
      - run: npm ci
      - name: Type check
        run: npx tsc --noEmit
      - name: ESLint
        run: |
          if [ -f eslint.config.js ] || [ -f eslint.config.mjs ] || [ -f .eslintrc.cjs ] || [ -f .eslintrc.json ]; then npx eslint src; else echo "eslint not configured, skipped"; fi
      - name: Build
        run: npm run build
'@

Write-NoBom 'docs\16.5-deployment-checklist.md' @'
# 16.5 Deployment checklist (Sanctum SPA mode, single-artifact deploy)

Sanctum cookie auth only works when the SPA and API origins match the config exactly.
Check every item below on EACH environment (local, staging, production) before release.

## Sanctum / session
- [ ] `SANCTUM_STATEFUL_DOMAINS` lists the exact SPA host, including the port when not 80/443
      (for example `crm.example.com`, or `localhost:5173` in local dev). No scheme, no trailing slash.
- [ ] `SESSION_DOMAIN` matches the origin: `crm.example.com` (or `.example.com` only if sub-domains must share it).
      Empty/`null` for localhost.
- [ ] `SESSION_SECURE_COOKIE=true` in staging and production (HTTPS only).
- [ ] `SESSION_SAME_SITE=lax` (same-origin SPA). Do not use `none`.
- [ ] `APP_URL` is the public origin (used in signed invite and password-reset URLs).
- [ ] CORS stays closed (same-origin). `supports_credentials` only matters if a second origin is ever added.
- [ ] Log in, reload, log out on the deployed host: the `XSRF-TOKEN` and session cookies are set for the right domain.

## Single-artifact build (16.1)
- [ ] `npm ci; npm run build` in `frontend` produces the bundle that Laravel serves from `public/`.
- [ ] The SPA fallback route serves `index.html` for non-API paths; `/api/*` and `/sanctum/*` are not shadowed.
- [ ] `php artisan config:cache; php artisan route:cache; php artisan view:cache` run after env changes.

## Services
- [ ] Queue worker running (`php artisan queue:work`) and restarted on each deploy (`php artisan queue:restart`).
- [ ] Scheduler running (`php artisan schedule:run` every minute): overdue tasks, lead score, campaign jobs.
- [ ] Reverb running as its own service with Redis as the backplane (16.4); `REVERB_*` and `VITE_REVERB_*` match the public host.
- [ ] Mail provider credentials set; the provider webhook adapter (9.5) is configured if engagement tracking is wanted.
- [ ] Turnstile keys set for the public lead form (9.8).

## Data
- [ ] `php artisan migrate --force` run once per release.
- [ ] After adding permissions: re-provision tenants (RoleCatalog) and ask users to log out and in.
- [ ] PostgreSQL has the `pg_trgm` extension (created by migrations).
- [ ] Database backups scheduled and a restore tested.

## Gates (16.3)
- [ ] CI green on both jobs: backend (Pint, Larastan, Pest) and frontend (tsc, ESLint, build).
- [ ] `composer audit` reviewed; advisories either upgraded or recorded with a reason.
'@

$report.Add("Wrote: $ciTarget, docs\16.5-deployment-checklist.md")

# Print the composer advisories so they can be triaged in the next chunk.
$ErrorActionPreference = 'Continue'
$audit = composer audit --no-interaction 2>&1 | Out-String
$ErrorActionPreference = 'Stop'
$report.Add('--- composer audit ---')
$report.Add((Tail $audit 80))
$report.Add('NOT TOUCHED (referenced, not shown): composer.json/composer.lock, phpunit.xml, phpstan config, frontend package.json and ESLint config, .env.example, .github (existing workflows, if any).')
Finish
