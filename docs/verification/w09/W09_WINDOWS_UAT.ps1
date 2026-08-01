#Requires -Version 5.1
<#
    W09_WINDOWS_UAT.ps1 — HELBARON LMS 1.0.0-rc.1 local release validation.

    Orchestrates every gate that cannot run in the cloud sandbox (Docker build, stack
    health, Playwright, axe, Trivy, backup/restore) plus the host unit gates, and writes
    ONE timestamped evidence file. Copy-paste runnable. Non-destructive outside its own
    disposable Docker volumes and a disposable local test database.

    NO REAL SECRETS ARE EMBEDDED. It writes a DISPOSABLE apps/api/.env.production and
    apps/web/.env.production containing local-only placeholder values so the stack can boot
    for smoke testing. DO NOT use these for anything but local validation, and DO NOT commit
    them (both are already .gitignored via .env.*).

    Prerequisites (fail-fast if missing): Docker Desktop running, Node.js + npm.
    Optional (steps auto-skip with a warning if absent): PHP 8.4 + Composer (backend gates),
    Trivy (image scans; a Docker fallback is used if the CLI is absent).

    USAGE (from anywhere):
        powershell -ExecutionPolicy Bypass -File `
          "D:\Claude_Files\Projects\LMS\CoreLMS Implementation\corelms\docs\verification\w09\W09_WINDOWS_UAT.ps1"

    Optional parameters let you point at a different checkout or skip the slow browser suite.
#>

param(
    [string]$RepoRoot = "D:\Claude_Files\Projects\LMS\CoreLMS Implementation\corelms",
    [switch]$SkipBrowser,        # skip Playwright + axe (fastest smoke)
    [switch]$KeepStackUp         # leave the stack running for manual inspection
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# ---------------------------------------------------------------------------
# Evidence collection
# ---------------------------------------------------------------------------
$Stamp    = Get-Date -Format 'yyyyMMdd-HHmmss'
$RunDir   = Join-Path $RepoRoot "docs\verification\w09\runs\$Stamp"
$Evidence = Join-Path $RunDir  "W09_EVIDENCE_$Stamp.md"
$LogDir   = Join-Path $RunDir  "logs"
$Compose  = @('compose','--env-file','apps\api\.env.production','-f','docker-compose.prod.yml')
$results  = [System.Collections.Generic.List[object]]::new()

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$m){ Write-Host "[W09] $m" -ForegroundColor Cyan }
function Record([string]$name,[string]$status,[string]$detail=''){
    $results.Add([pscustomobject]@{ Gate=$name; Status=$status; Detail=$detail })
    $color = if($status -eq 'PASS'){'Green'} elseif($status -eq 'SKIP'){'Yellow'} else {'Red'}
    Write-Host ("  {0,-34} {1} {2}" -f $name,$status,$detail) -ForegroundColor $color
}
# Run a gate; capture stdout+stderr to a log; record PASS/FAIL by exit code (never aborts).
function Gate([string]$name,[string]$logName,[scriptblock]$body){
    $log = Join-Path $LogDir $logName
    Log "→ $name"
    try {
        & $body *>&1 | Tee-Object -FilePath $log | Out-Null
        if ($LASTEXITCODE -and $LASTEXITCODE -ne 0) { Record $name 'FAIL' "exit $LASTEXITCODE (see $logName)" }
        else { Record $name 'PASS' $logName }
    } catch {
        ($_ | Out-String) | Add-Content $log
        Record $name 'FAIL' "$($_.Exception.Message) (see $logName)"
    }
}

# ---------------------------------------------------------------------------
# 0. Prerequisites + repo
# ---------------------------------------------------------------------------
Log "Evidence dir: $RunDir"
if (-not (Test-Path (Join-Path $RepoRoot 'docker-compose.prod.yml'))) {
    throw "Repo not found or wrong path: $RepoRoot (expected docker-compose.prod.yml)"
}
Set-Location $RepoRoot

function Has($cmd){ [bool](Get-Command $cmd -ErrorAction SilentlyContinue) }

if (-not (Has 'docker')) { throw 'Docker CLI not found. Install/start Docker Desktop.' }
& docker version --format '{{.Server.Version}}' *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker daemon not reachable. Start Docker Desktop (Engine running), then retry.' }
if (-not (Has 'node')) { throw 'Node.js not found. Install Node 22+.' }
$havePhp   = (Has 'php') -and (Has 'composer')
$haveTrivy = Has 'trivy'
Record 'prereq: docker'   'PASS' (& docker version --format '{{.Server.Version}}')
Record 'prereq: node'     'PASS' (& node -v)
Record 'prereq: php+composer' ($(if($havePhp){'PASS'}else{'SKIP'})) $(if($havePhp){'backend gates enabled'}else{'absent — backend gates skipped'})
Record 'prereq: trivy'    ($(if($haveTrivy){'PASS'}else{'SKIP'})) $(if($haveTrivy){'native'}else{'CLI absent — docker-run fallback'})

# ---------------------------------------------------------------------------
# 1. Environment values (validated, never printed). If you already keep a REAL
#    apps\api\.env.production, this script does NOT overwrite it — it validates and
#    uses it. Otherwise it writes a DISPOSABLE local-only file (placeholders + a
#    generated APP_KEY + a random local DB password). REPLACE the disposable values
#    with real secrets for a production-representative run. Nothing here is printed.
# ---------------------------------------------------------------------------
$DispoMarker = '# HELBARON-W09-DISPOSABLE (safe to delete; not real secrets)'
$ApiEnv = 'apps\api\.env.production'
$realEnv = (Test-Path $ApiEnv) -and -not (Select-String -Path $ApiEnv -SimpleMatch $DispoMarker -Quiet)
if ($realEnv) {
    Record 'env: using existing .env.production' 'PASS' 'real file detected — left untouched'
    $DispoDbPw = (Select-String -Path $ApiEnv -Pattern '^DB_PASSWORD=(.*)$').Matches.Groups[1].Value
    if (-not $DispoDbPw) { $DispoDbPw = 'helbaron' }
} else {
    Log 'Writing DISPOSABLE apps\api\.env.production + apps\web\.env.production (local placeholders; REPLACE for a real run)'
    $DispoDbPw    = 'local_dispo_' + (Get-Random)
    $AppKeyRaw    = [byte[]]::new(32); (New-Object System.Security.Cryptography.RNGCryptoServiceProvider).GetBytes($AppKeyRaw)
    $AppKey       = 'base64:' + [Convert]::ToBase64String($AppKeyRaw)
@"
$DispoMarker
APP_NAME=HELBARON
APP_ENV=production
APP_KEY=$AppKey
APP_DEBUG=false
APP_URL=https://localhost
APP_VERSION=1.0.0-rc.1
APP_TRUSTED_HOSTS=localhost
TRUSTED_PROXIES=*
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=helbaron
DB_USERNAME=helbaron
DB_PASSWORD=$DispoDbPw
REDIS_HOST=redis
REDIS_PORT=6379
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=redis
LOG_CHANNEL=stack
LOG_LEVEL=info
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS=no-reply@localhost
MAIL_FROM_NAME=HELBARON
MEDIA_PROVIDER=s3
COMMERCE_PAYMENT_PROVIDER=stripe
COMMERCE_WEBHOOK_SECRET=whsec_local_dispo_$(Get-Random)
COMMERCE_ALLOW_FAKE_GATEWAY=false
SECURITY_HSTS_ENABLED=true
"@ | Set-Content -Encoding ASCII 'apps\api\.env.production'
@"
NEXT_PUBLIC_API_BASE_URL=http://localhost:8080/api/v1
NEXT_PUBLIC_SITE_URL=http://localhost:8080
API_INTERNAL_URL=http://nginx:80/api/v1
NODE_ENV=production
"@ | Set-Content -Encoding ASCII 'apps\web\.env.production'
    Record 'disposable env written' 'PASS' 'apps\api\.env.production, apps\web\.env.production (gitignored)'
}

# ---------------------------------------------------------------------------
# 2. Frontend host gates (node)
# ---------------------------------------------------------------------------
Push-Location 'apps\web'
Gate 'web: npm ci'        'web-npm-ci.log'     { & npm ci }
Gate 'web: typecheck'     'web-typecheck.log'  { & npm run typecheck }
Gate 'web: lint'          'web-lint.log'       { & npm run lint }
Gate 'web: vitest'        'web-vitest.log'     { & npx vitest run }
Gate 'web: build'         'web-build.log'      { & npm run build }
Pop-Location

# ---------------------------------------------------------------------------
# 3. Backend host gates (php + composer + the compose Postgres on :55432)
# ---------------------------------------------------------------------------
if ($havePhp) {
    Push-Location 'apps\api'
    Gate 'api: composer install' 'api-composer.log' { & composer install --no-interaction --prefer-dist }
    # Point Pest/artisan at the compose Postgres (published on host :55432) as a DISPOSABLE test DB.
    $env:DB_HOST='127.0.0.1'; $env:DB_PORT='55432'; $env:DB_DATABASE='helbaron'
    $env:DB_USERNAME='helbaron'; $env:DB_PASSWORD=$DispoDbPw; $env:CACHE_STORE='array'
    Gate 'api: migrate:fresh --seed' 'api-migrate.log' { & php artisan migrate:fresh --seed --force }
    Gate 'api: pest (sequential)'    'api-pest.log'     { & vendor\bin\pest }
    Gate 'api: phpstan'              'api-phpstan.log'  { & vendor\bin\phpstan analyse --no-progress }
    Gate 'api: pint'                 'api-pint.log'     { & vendor\bin\pint --test }
    Gate 'api: deptrac'              'api-deptrac.log'  { & vendor\bin\deptrac analyse --no-progress }
    Gate 'api: config:validate'      'api-configval.log'{ & php artisan config:validate --strict }
    Pop-Location
} else {
    Record 'backend host gates' 'SKIP' 'php/composer absent — run in CI or the sandbox'
}

# ---------------------------------------------------------------------------
# 4. Build images + Trivy
# ---------------------------------------------------------------------------
Gate 'docker: build images' 'docker-build.log' { & docker @Compose build }
$imgApi='helbaron-api:1.0.0-rc.1'; $imgWeb='helbaron-web:1.0.0-rc.1'
$trivyArgs=@('image','--severity','HIGH,CRITICAL','--ignore-unfixed','--exit-code','1')
if ($haveTrivy) {
    Gate 'trivy: api image' 'trivy-api.log' { & trivy @trivyArgs $imgApi }
    Gate 'trivy: web image' 'trivy-web.log' { & trivy @trivyArgs $imgWeb }
} else {
    Gate 'trivy: api image (docker)' 'trivy-api.log' { & docker run --rm -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy:latest @trivyArgs $imgApi }
    Gate 'trivy: web image (docker)' 'trivy-web.log' { & docker run --rm -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy:latest @trivyArgs $imgWeb }
}

# ---------------------------------------------------------------------------
# 5. Start stack + health
# ---------------------------------------------------------------------------
$stackUp = $false
try {
    Gate 'docker: stack up' 'docker-up.log' { & docker @Compose up -d }
    $stackUp = $true
    Log 'Waiting for containers to report healthy (up to 180s)...'
    $deadline=(Get-Date).AddSeconds(180); $healthy=$false
    while((Get-Date) -lt $deadline){
        $ps = (& docker @Compose ps --format '{{.Service}} {{.Health}}') 2>$null
        $ps | Out-File (Join-Path $LogDir 'docker-ps.log')
        if (($ps -match 'api.*healthy') -and ($ps -match 'postgres.*healthy') -and ($ps -match 'redis.*healthy')) { $healthy=$true; break }
        Start-Sleep 5
    }
    Record 'containers healthy' ($(if($healthy){'PASS'}else{'FAIL'})) 'docker-ps.log'

    function Probe($name,$url){
        try { $r=Invoke-WebRequest -UseBasicParsing -Uri $url -TimeoutSec 15
              Record $name ($(if($r.StatusCode -eq 200){'PASS'}else{'FAIL'})) "HTTP $($r.StatusCode)" }
        catch { Record $name 'FAIL' $_.Exception.Message }
    }
    Probe 'health/live'      'http://localhost:8080/api/v1/health/live'
    Probe 'health/ready'     'http://localhost:8080/api/v1/health/ready'
    Probe 'frontend homepage' 'http://localhost:8080/'

    # Fatal-error scan of container logs.
    $logs = (& docker @Compose logs --no-color) 2>&1
    $logs | Out-File (Join-Path $LogDir 'stack-logs.log')
    $fatal = $logs | Select-String -Pattern 'PHP Fatal|Uncaught|Stack trace|FATAL|segfault' -CaseSensitive:$false
    Record 'no fatal errors in logs' ($(if($fatal){'FAIL'}else{'PASS'})) $(if($fatal){"$($fatal.Count) matches — see stack-logs.log"}else{'clean'})

    # -----------------------------------------------------------------------
    # 6. Backup / restore drill (inside the compose Postgres — disposable)
    # -----------------------------------------------------------------------
    Gate 'backup/restore drill' 'backup-drill.log' {
        & docker @Compose exec -T postgres sh -lc `
          'set -e; SRC=$(psql -U helbaron -d helbaron -tAc "select count(*) from information_schema.tables where table_schema=''public''"); \
           pg_dump -U helbaron -Fc helbaron > /tmp/rc.dump; \
           dropdb -U helbaron --if-exists rc_restore; createdb -U helbaron rc_restore; \
           pg_restore -U helbaron --clean --if-exists --no-owner -d rc_restore /tmp/rc.dump; \
           RES=$(psql -U helbaron -d rc_restore -tAc "select count(*) from information_schema.tables where table_schema=''public''"); \
           dropdb -U helbaron rc_restore; \
           echo "source=$SRC restored=$RES"; test "$SRC" = "$RES"'
    }

    # -----------------------------------------------------------------------
    # 7. Playwright + axe (against the running stack)
    # -----------------------------------------------------------------------
    if (-not $SkipBrowser) {
        Push-Location 'apps\web'
        $env:PLAYWRIGHT_BASE_URL='http://localhost:8080'
        Gate 'playwright: install chromium' 'pw-install.log' { & npx playwright install --with-deps chromium }
        Gate 'playwright: e2e (smoke + a11y + RTL)' 'pw-e2e.log' { & npx playwright test }
        Pop-Location
    } else {
        Record 'playwright + axe' 'SKIP' '-SkipBrowser'
    }
}
finally {
    if ($stackUp -and -not $KeepStackUp) {
        Log 'Tearing down stack (docker compose down -v)...'
        & docker @Compose down -v *> (Join-Path $LogDir 'docker-down.log')
    }
}

# ---------------------------------------------------------------------------
# 8. Evidence file + summary
# ---------------------------------------------------------------------------
$fail = ($results | Where-Object Status -eq 'FAIL').Count
$pass = ($results | Where-Object Status -eq 'PASS').Count
$skip = ($results | Where-Object Status -eq 'SKIP').Count
$overall = if($fail -eq 0){'PASS'}else{'FAIL'}

$md = @()
$md += "# W09 Local UAT Evidence — 1.0.0-rc.1"
$md += ""
$md += "- Timestamp: $Stamp"
$md += "- Host: $env:COMPUTERNAME  |  Docker: $(& docker version --format '{{.Server.Version}}')  |  Node: $(& node -v)"
$md += "- Overall: **$overall**  (PASS=$pass FAIL=$fail SKIP=$skip)"
$md += ""
$md += "| Gate | Status | Detail |"
$md += "|---|---|---|"
foreach($r in $results){ $md += "| $($r.Gate) | $($r.Status) | $($r.Detail) |" }
$md += ""
$md += "Logs: `logs/` in this directory."
$md -join "`r`n" | Set-Content -Encoding UTF8 $Evidence

Write-Host ""
Write-Host "==================================================" -ForegroundColor White
Write-Host "  W09 LOCAL UAT: $overall  (PASS=$pass FAIL=$fail SKIP=$skip)" -ForegroundColor $(if($overall -eq 'PASS'){'Green'}else{'Red'})
Write-Host "  Evidence: $Evidence" -ForegroundColor White
Write-Host "  Logs:     $LogDir" -ForegroundColor White
Write-Host "==================================================" -ForegroundColor White
if ($fail -gt 0) { exit 1 } else { exit 0 }
