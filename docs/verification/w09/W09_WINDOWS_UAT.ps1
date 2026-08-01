#Requires -Version 5.1
<#
    W09_WINDOWS_UAT.ps1 - HELBARON LMS 1.0.0-rc.1 local release validation (single orchestrator).

    Runs every gate that cannot run in the cloud sandbox (Docker build, stack health, Trivy,
    Playwright, axe, backup/restore, operational failure tests) plus the host unit gates, and
    writes ALL evidence into ONE timestamped directory. Copy-paste runnable. Non-destructive
    outside its own disposable Docker volumes and a disposable test database.

    SAFETY
    - No real secrets are embedded. If a REAL apps\api\.env.production already exists (no disposable
      marker), it is validated and used, never overwritten. Otherwise a DISPOSABLE local-only file is
      written (placeholders + a generated APP_KEY + a random local DB password).
    - Backend gates run against EPHEMERAL, published Postgres + Redis containers started just for the
      gates and removed in finally, so the running app's database is never touched.
    - Evidence files contain variable NAMES and PASS/FAIL only - never secret values.
    - Critical-stage failure short-circuits dependent stages; the stack is always torn down; evidence
      is always written.

    PREREQUISITES (fail-fast): Docker Desktop running, Node.js + npm, PHP 8.4 + Composer, Trivy.
    Playwright browsers are installed by the script. The browser stage can be skipped with -SkipBrowser.

    USAGE (copy-paste):
        powershell -NoProfile -ExecutionPolicy Bypass -File `
          "D:\Claude_Files\Projects\LMS\CoreLMS Implementation\corelms\docs\verification\w09\W09_WINDOWS_UAT.ps1"
#>

param(
    [string]$RepoRoot   = "D:\Claude_Files\Projects\LMS\CoreLMS Implementation\corelms",
    [string]$ExpectedHead = "9dbe6ed",
    [string]$ExpectedVersion = "1.0.0-rc.1",
    [switch]$SkipBrowser,
    [switch]$KeepStackUp
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# ------------------------------------------------------------------ evidence + results
$Stamp    = Get-Date -Format 'yyyyMMdd-HHmmss'
$Ev       = Join-Path $RepoRoot "docs\verification\w09\evidence\$Stamp"
$ClogDir  = Join-Path $Ev 'container-logs'
$PwDir    = Join-Path $Ev 'playwright-results'
$AxeDir   = Join-Path $Ev 'axe-results'
$Compose  = @('compose','--env-file','apps\api\.env.production','-f','docker-compose.prod.yml')
$results  = [System.Collections.Generic.List[object]]::new()
$Aborted  = $false
$GatePg   = 'w09_gate_pg'; $GateRd = 'w09_gate_redis'; $GatePw = 'w09gatepw'  # ephemeral backend-gate DB/Redis
New-Item -ItemType Directory -Force -Path $Ev,$ClogDir,$PwDir,$AxeDir | Out-Null

function Log([string]$m){ Write-Host "[W09] $m" -ForegroundColor Cyan }
function Record([string]$name,[string]$status,[string]$detail=''){
    $results.Add([pscustomobject]@{ gate=$name; status=$status; detail=$detail })
    $c = @{PASS='Green';FAIL='Red';SKIP='Yellow'}[$status]; if(-not $c){$c='Gray'}
    Write-Host ("  {0,-40} {1} {2}" -f $name,$status,$detail) -ForegroundColor $c
}
function Save([string]$file,[string]$text){ $text | Out-File -Encoding UTF8 (Join-Path $Ev $file) }
# Run an external gate, tee output to an evidence file, record PASS/FAIL by exit code. Returns bool.
function Gate([string]$name,[string]$evFile,[scriptblock]$body){
    if($Aborted){ Record $name 'SKIP' 'aborted upstream'; return $false }
    Log "-> $name"
    $out = ''
    # External tools (docker/trivy/npm/composer/playwright) stream progress to STDERR. Under
    # ErrorActionPreference='Stop', PS 5.1 turns that first stderr line into a terminating
    # NativeCommandError and aborts a perfectly good build. Judge native commands by exit code only.
    $prevEAP = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $out = (& $body 2>&1 | Out-String); $code = $LASTEXITCODE }
    catch { $out = ($_ | Out-String); $code = 1 }
    finally { $ErrorActionPreference = $prevEAP }
    Add-Content -Path (Join-Path $Ev $evFile) -Value $out
    if($null -eq $code){ $code = 0 }
    if($code -eq 0){ Record $name 'PASS' $evFile; return $true }
    Record $name 'FAIL' "exit $code ($evFile)"; return $false
}
# HTTP status via curl.exe (robust across PS 5.1/7 and works for non-2xx like 503).
function HttpStatus([string]$url){ (& curl.exe -s -o NUL -w "%{http_code}" --max-time 15 $url) 2>$null }
function Abort([string]$stage,[int]$code){
    $Script:Aborted = $true
    Record "ABORT at $stage" 'FAIL' "exit $code"
    Log "Critical stage failed: $stage (exit $code). Dependent stages will be skipped; tearing down."
}

Log "Evidence: $Ev"

# ================================================================== 0. Prerequisites
function Has($c){ [bool](Get-Command $c -ErrorAction SilentlyContinue) }
if(-not (Has 'docker')){ Abort 'prereq:docker' 1 }
if(-not $Aborted){
    & docker version --format '{{.Server.Version}}' *> $null
    if($LASTEXITCODE -ne 0){ Abort 'prereq:docker-daemon' 1 } else { Record 'prereq: docker daemon' 'PASS' (& docker version --format '{{.Server.Version}}') }
}
foreach($t in 'node','npm','php','composer','trivy'){
    if(Has $t){ Record "prereq: $t" 'PASS' } else {
        if($t -in @('php','composer','trivy')){ Abort "prereq:$t" 1 } else { Record "prereq: $t" 'FAIL' 'missing' }
    }
}

# ================================================================== 1. Repository checks
if(-not $Aborted){
    if(-not (Test-Path (Join-Path $RepoRoot 'docker-compose.prod.yml'))){ Abort 'repo:path' 1 }
    else {
        Set-Location $RepoRoot
        $branch = (& git rev-parse --abbrev-ref HEAD).Trim()
        $head   = (& git rev-parse --short HEAD).Trim()
        & git merge-base --is-ancestor $ExpectedHead HEAD 2>$null; $isDesc = ($LASTEXITCODE -eq 0)
        $porcelain = (& git status --porcelain)
        $clean = [string]::IsNullOrWhiteSpace(($porcelain | Out-String))
        $ver = ''
        if(Test-Path 'VERSION'){ $ver = (Get-Content 'VERSION' -Raw).Trim() }
        Save 'git-status.txt' ("branch=$branch`nHEAD=$head`nexpected_ancestor=$ExpectedHead is-ancestor=$isDesc`nversion=$ver`n`n" + ($porcelain | Out-String))
        Record 'repo: branch is main'       ($(if($branch -eq 'main'){'PASS'}else{'FAIL'})) $branch
        Record 'repo: HEAD >= 9dbe6ed'      ($(if($isDesc){'PASS'}else{'FAIL'})) $head
        Record 'repo: working tree clean'   ($(if($clean){'PASS'}else{'FAIL'})) $(if($clean){'clean'}else{'DIRTY'})
        Record 'repo: version 1.0.0-rc.1'   ($(if($ver -eq $ExpectedVersion){'PASS'}else{'FAIL'})) $ver
    }
}

# ================================================================== 2. Environment (validated, never printed)
if(-not $Aborted){
    $DispoMarker = '# HELBARON-W09-DISPOSABLE (safe to delete; not real secrets)'
    $ApiEnv = 'apps\api\.env.production'; $WebEnv = 'apps\web\.env.production'
    # A file is a placeholder TEMPLATE (not a real filled env) if it still contains any of these tokens.
    function Test-IsTemplate([string]$path){
        if(-not (Test-Path $path)){ return $false }
        $t = Get-Content $path -Raw
        foreach($ph in '<','whsec_fake','changeme','your-domain','placeholder'){ if($t -match [regex]::Escape($ph)){ return $true } }
        return $false
    }
    $apiExists = Test-Path $ApiEnv
    $apiMarker = $apiExists -and (Select-String -Path $ApiEnv -SimpleMatch $DispoMarker -Quiet)
    # "Real" == exists, no disposable marker, and no placeholder tokens. Real files are NEVER overwritten.
    $apiRealFilled = $apiExists -and -not $apiMarker -and -not (Test-IsTemplate $ApiEnv)
    if($apiRealFilled){
        Record 'env: using existing real .env.production' 'PASS' 'left untouched'
    } else {
        # Disposable path: absent file, our own disposable marker, or a placeholder template.
        # Any existing non-marker file (a real-looking or template file) is BACKED UP, never destroyed.
        $why = if(-not $apiExists){'no env file'} elseif($apiMarker){'disposable marker'} else{'placeholder template'}
        foreach($p in @($ApiEnv,$WebEnv)){
            if((Test-Path $p) -and -not (Select-String -Path $p -SimpleMatch $DispoMarker -Quiet)){
                Move-Item -Force -Path $p -Destination ($p + '.pretest.bak')
            }
        }
        $DispoDbPw = 'local_dispo_' + (Get-Random)
        $raw = [byte[]]::new(32); [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($raw)
        $AppKey = 'base64:' + [Convert]::ToBase64String($raw)
        @"
$DispoMarker
APP_NAME=HELBARON
APP_ENV=production
APP_KEY=$AppKey
APP_DEBUG=false
APP_URL=https://localhost
APP_VERSION=$ExpectedVersion
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
"@ | Set-Content -Encoding ASCII $ApiEnv
        # NEXT_PUBLIC_* are build-time inlined; at runtime they only feed the app's boot-time env
        # contract (src/instrumentation.ts -> src/lib/env.ts), which THROWS in production if the
        # public API base is localhost/127.0.0.1. Use valid non-localhost production-like URLs so the
        # web server boots. Browser API calls go through the same-origin /api/backend BFF proxy, which
        # uses API_INTERNAL_URL below, so these placeholder public URLs do not affect real requests.
        @"
$DispoMarker
NEXT_PUBLIC_API_BASE_URL=https://uat.helbaron.local/api/v1
NEXT_PUBLIC_SITE_URL=https://uat.helbaron.local
API_INTERNAL_URL=http://nginx:80/api/v1
NODE_ENV=production
"@ | Set-Content -Encoding ASCII $WebEnv
        Record 'env: wrote disposable local env' 'PASS' "reason: $why (any prior file kept as *.pretest.bak)"
    }
    # Placeholder-secret + required-name validation. NAMES + PASS/FAIL only - no values.
    $envText = Get-Content $ApiEnv -Raw
    $required = 'APP_KEY','APP_ENV','APP_URL','DB_PASSWORD','REDIS_HOST','COMMERCE_WEBHOOK_SECRET'
    $names = @(); $bad = @()
    foreach($k in $required){
        $m = [regex]::Match($envText, "(?m)^$k=(.*)$")
        $present = $m.Success -and $m.Groups[1].Value.Trim() -ne ''
        $names += ("{0}: {1}" -f $k, ($(if($present){'present'}else{'MISSING'})))
        if(-not $present){ $bad += $k }
    }
    foreach($ph in '<','whsec_fake','changeme','your-domain','placeholder'){
        if($envText -match [regex]::Escape($ph)){ $bad += "placeholder:$ph" }
    }
    if($envText -match '(?m)^APP_ENV=production' -and $envText -match '(?m)^COMMERCE_ALLOW_FAKE_GATEWAY=true'){ $bad += 'fake-gateway-enabled' }
    Save 'environment-validation.txt' (($names -join "`n") + "`n`nissues: " + ($(if($bad){$bad -join ', '}else{'none'})))
    Record 'env: no placeholder production secrets' ($(if($bad.Count -eq 0){'PASS'}else{'FAIL'})) ($(if($bad){$bad -join ','}else{'ok'}))
    if($bad.Count -gt 0){ Abort 'env:placeholders' 1 }
}

# ================================================================== 3. Compose validation
if(-not $Aborted){ if(-not (Gate 'docker: compose config' 'docker-compose.txt' { & docker @Compose config })){ Abort 'compose:config' 1 } }

# ================================================================== 4. Build images
if(-not $Aborted){ if(-not (Gate 'docker: build API image' 'docker-build-api.txt' { & docker @Compose build api })){ Abort 'build:api' 1 } }
if(-not $Aborted){ if(-not (Gate 'docker: build Web image' 'docker-build-web.txt' { & docker @Compose build web })){ Abort 'build:web' 1 } }

# ================================================================== 5. Trivy + secret scan (fail on HIGH/CRITICAL)
$imgApi='helbaron-api:1.0.0-rc.1'; $imgWeb='helbaron-web:1.0.0-rc.1'
$tv=@('image','--severity','HIGH,CRITICAL','--ignore-unfixed','--exit-code','1','--no-progress')
if(-not $Aborted){ Gate 'trivy: API image (HIGH/CRITICAL)' 'trivy-api.txt' { & trivy @tv $imgApi } | Out-Null }
if(-not $Aborted){ Gate 'trivy: Web image (HIGH/CRITICAL)' 'trivy-web.txt' { & trivy @tv $imgWeb } | Out-Null }
if(-not $Aborted){ Gate 'secret scan: images + repo' 'secret-scan.txt' {
    & trivy image --scanners secret --exit-code 1 --no-progress $imgApi; if($LASTEXITCODE){ throw "api image secret finding (exit $LASTEXITCODE)" }
    & trivy image --scanners secret --exit-code 1 --no-progress $imgWeb; if($LASTEXITCODE){ throw "web image secret finding (exit $LASTEXITCODE)" }
    & trivy fs --scanners secret --exit-code 1 --no-progress --skip-dirs node_modules --skip-dirs vendor .; if($LASTEXITCODE){ throw "repo secret finding (exit $LASTEXITCODE)" }
} | Out-Null }

# ================================================================== 6. Start stack + wait for health
$stackUp = $false
# Stages 6-12 make several out-of-Gate native docker calls (ps, logs, stop/start redis) that also
# stream to stderr; keep ErrorActionPreference=Continue for this whole block so they don't terminate.
$prevBodyEAP = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
try {
if(-not $Aborted){
    if(Gate 'docker: stack up -d' 'container-status.txt' { & docker @Compose up -d }){
        $stackUp = $true
        Log 'Waiting up to 240s for containers to become healthy...'
        $deadline=(Get-Date).AddSeconds(240); $ready=$false
        while((Get-Date) -lt $deadline){
            $ps = (& docker @Compose ps --format '{{.Service}}|{{.Health}}|{{.State}}') 2>$null
            $ps | Out-File (Join-Path $Ev 'container-status.txt')
            $h = @{}; foreach($line in $ps){ $p=$line -split '\|'; if($p.Count -ge 3){ $h[$p[0]]="$($p[1])/$($p[2])" } }
            # Anchored match: "$health/$state". Use -like 'healthy/*' so "unhealthy/running" does NOT pass.
            $apiOK=($h['api'] -like 'healthy/*'); $pgOK=($h['postgres'] -like 'healthy/*'); $rdOK=($h['redis'] -like 'healthy/*')
            $webOK=($h['web'] -like 'healthy/*'); $hzOK=($h['horizon'] -like 'healthy/*'); $schOK=($h['scheduler'] -like '*/running')
            if($apiOK -and $pgOK -and $rdOK -and $webOK -and $hzOK -and $schOK){ $ready=$true; break }
            Start-Sleep 5
        }
        Record 'stack: database (postgres) healthy' ($(if($pgOK){'PASS'}else{'FAIL'})) $h['postgres']
        Record 'stack: redis healthy'               ($(if($rdOK){'PASS'}else{'FAIL'})) $h['redis']
        Record 'stack: API healthy'                 ($(if($apiOK){'PASS'}else{'FAIL'})) $h['api']
        Record 'stack: Web healthy'                 ($(if($webOK){'PASS'}else{'FAIL'})) $h['web']
        Record 'stack: queue worker (horizon)'      ($(if($hzOK){'PASS'}else{'FAIL'})) $h['horizon']
        Record 'stack: scheduler running'           ($(if($schOK){'PASS'}else{'FAIL'})) $h['scheduler']
        # Capture container logs while the stack is up (even if a container is unhealthy) so failures
        # can be diagnosed. This runs BEFORE any stack:health abort so evidence is never lost.
        (& docker @Compose logs --no-color --tail 2000) 2>&1 | Out-File (Join-Path $ClogDir 'stack.log')
        # Scan for genuine APPLICATION crashes only. Exclude infra containers: Postgres logs routine
        # "FATAL:" lines during startup ("the database system is starting up") and Redis emits warnings
        # that are not app failures. Match specific app-fatal signatures, not a bare case-insensitive FATAL.
        $logLines = @(Get-Content (Join-Path $ClogDir 'stack.log'))
        $appLines = @($logLines | Where-Object { (($_ -split '\|',2)[0]) -notmatch '(?i)postgres|redis' })
        $fatal = @($appLines | Select-String -Pattern 'PHP Fatal error|Fatal error:|Uncaught (Error|Exception|TypeError)|production\.(CRITICAL|EMERGENCY|ALERT)')
        if($fatal.Count -gt 0){ Save 'log-fatal-matches.txt' (($fatal | ForEach-Object { $_.Line }) -join "`r`n") }
        Record 'logs: no fatal errors' ($(if($fatal.Count -gt 0){'FAIL'}else{'PASS'})) $(if($fatal.Count -gt 0){"$($fatal.Count) matches (see log-fatal-matches.txt)"}else{'clean'})
        # Per-service status snapshot for quick triage.
        Save 'stack-health.txt' (($h.GetEnumerator() | ForEach-Object { "{0} = {1}" -f $_.Key,$_.Value }) -join "`r`n")
        if(-not $ready){ Abort 'stack:health' 1 }
    } else { Abort 'stack:up' 1 }
}

# ================================================================== 7. Health + logs
if(-not $Aborted){
    $hc = @()
    $live=HttpStatus 'http://localhost:8080/api/v1/health/live'
    $rdy =HttpStatus 'http://localhost:8080/api/v1/health/ready'
    $homeStatus=HttpStatus 'http://localhost:8080/'
    $apiv=HttpStatus 'http://localhost:8080/api/v1/health'
    $hc += "live=$live ready=$rdy home=$homeStatus apiv=$apiv"
    Record 'health: API liveness (200)'  ($(if($live -eq '200'){'PASS'}else{'FAIL'})) "HTTP $live"
    Record 'health: API readiness (200)' ($(if($rdy  -eq '200'){'PASS'}else{'FAIL'})) "HTTP $rdy"
    Record 'health: frontend homepage'   ($(if($homeStatus -match '^(200|30\d)$'){'PASS'}else{'FAIL'})) "HTTP $homeStatus"
    Record 'health: API v1 route'        ($(if($apiv -eq '200'){'PASS'}else{'FAIL'})) "HTTP $apiv"
    Save 'health-checks.txt' ($hc -join "`n")
}

# ================================================================== 8. Production config validation (no fake providers)
if(-not $Aborted){
    Gate 'security: config:validate --strict (container)' 'health-checks.txt' {
        & docker @Compose exec -T api php artisan config:validate --strict
    } | Out-Null
    # Debug must be OFF: a 404 must not leak a stack trace.
    $body = (& curl.exe -s --max-time 15 'http://localhost:8080/api/v1/__definitely_not_a_route__') 2>$null
    $leak = ($body -match 'Stack trace|vendor/laravel|APP_DEBUG')
    Record 'security: no debug/stack-trace leak' ($(if($leak){'FAIL'}else{'PASS'})) $(if($leak){'LEAK'}else{'ok'})
}

# ================================================================== 9. Backend gates
# Backend gates run on the host against EPHEMERAL, published Postgres + Redis started just for the
# gates (the prod stack does NOT expose its DB/Redis to the host). These are removed in `finally`.
if(-not $Aborted){
    Gate 'backend: start ephemeral pg+redis' 'backend-gates.txt' {
        & docker rm -f $GatePg $GateRd 2>$null | Out-Null
        & docker run -d --name $GatePg -e POSTGRES_USER=helbaron -e POSTGRES_PASSWORD=$GatePw -e POSTGRES_DB=helbaron_test -p 55432:5432 postgres:16-alpine
        if($LASTEXITCODE){ throw 'pg start failed' }
        & docker run -d --name $GateRd -p 6380:6379 redis:7-alpine
        if($LASTEXITCODE){ throw 'redis start failed' }
        # Wait for pg to accept connections. NOTE: a `for` loop runs in the current scope, so the
        # $ok assignment propagates (a ForEach-Object block would not - it runs in a child scope).
        $ok=$false
        for($i=0; $i -lt 30 -and -not $ok; $i++){
            & docker exec $GatePg pg_isready -U helbaron *>$null
            if($LASTEXITCODE -eq 0){ $ok=$true } else { Start-Sleep 2 }
        }
        if(-not $ok){ throw 'pg not ready' }
    } | Out-Null
    Push-Location 'apps\api'
    $env:DB_HOST='127.0.0.1'; $env:DB_PORT='55432'; $env:DB_DATABASE='helbaron_test'
    $env:DB_USERNAME='helbaron'; $env:DB_PASSWORD=$GatePw; $env:CACHE_STORE='array'
    $env:REDIS_HOST='127.0.0.1'; $env:REDIS_PORT='6380'; $env:QUEUE_CONNECTION='sync'
    Gate 'backend: composer install'       'backend-gates.txt' { & composer install --no-interaction --prefer-dist } | Out-Null
    Gate 'backend: migrate:fresh --seed'   'backend-gates.txt' { & php artisan migrate:fresh --seed --force } | Out-Null
    Gate 'backend: pest'                    'backend-gates.txt' { & vendor\bin\pest } | Out-Null
    Gate 'backend: pint --test'             'backend-gates.txt' { & vendor\bin\pint --test } | Out-Null
    Gate 'backend: phpstan'                 'backend-gates.txt' { & vendor\bin\phpstan analyse --no-progress } | Out-Null
    Gate 'backend: deptrac'                 'backend-gates.txt' { & vendor\bin\deptrac analyse --no-progress } | Out-Null
    Pop-Location
    Remove-Item Env:DB_HOST,Env:DB_PORT,Env:DB_DATABASE,Env:DB_USERNAME,Env:DB_PASSWORD,Env:CACHE_STORE,Env:REDIS_HOST,Env:REDIS_PORT,Env:QUEUE_CONNECTION -ErrorAction SilentlyContinue
}

# ================================================================== 10. Frontend gates
if(-not $Aborted){
    Push-Location 'apps\web'
    Gate 'frontend: npm ci'    'frontend-gates.txt' { & npm ci } | Out-Null
    Gate 'frontend: typecheck' 'frontend-gates.txt' { & npm run typecheck } | Out-Null
    Gate 'frontend: lint'      'frontend-gates.txt' { & npm run lint } | Out-Null
    Gate 'frontend: vitest'    'frontend-gates.txt' { & npx vitest run } | Out-Null
    Gate 'frontend: build'     'frontend-gates.txt' { & npm run build } | Out-Null
    Pop-Location
}

# ================================================================== 11. Browser UAT (Playwright: desktop + mobile, EN + AR) + axe
if(-not $Aborted -and -not $SkipBrowser){
    Push-Location 'apps\web'
    $env:PLAYWRIGHT_BASE_URL='http://localhost:8080'
    Gate 'playwright: install chromium'  'playwright-results\install.txt' { & npx playwright install chromium } | Out-Null
    Gate 'playwright: e2e (smoke/RTL/mobile) + axe' 'playwright-results\run.txt' {
        & npx playwright test --reporter=list --output="$PwDir"
    } | Out-Null
    if(Test-Path 'playwright-report'){ Copy-Item -Recurse -Force 'playwright-report' (Join-Path $PwDir 'report') }
    if(Test-Path 'test-results'){ Copy-Item -Recurse -Force 'test-results' (Join-Path $PwDir 'test-results') }
    'axe assertions run inside the a11y Playwright spec; see playwright-results/.' | Out-File (Join-Path $AxeDir 'README.txt')
    Remove-Item Env:PLAYWRIGHT_BASE_URL -ErrorAction SilentlyContinue
    Pop-Location
} elseif($SkipBrowser){ Record 'browser + axe' 'SKIP' '-SkipBrowser' }

# ================================================================== 12. Operational tests
if(-not $Aborted){
    # 12a. Correlation-ID response header echoes an inbound value.
    $cid = 'w09-' + (Get-Random)
    $hdrs = (& curl.exe -s -D - -o NUL --max-time 15 -H "X-Correlation-ID: $cid" 'http://localhost:8080/api/v1/health/live') 2>$null
    $hasCid = ($hdrs -match "(?i)X-Correlation-ID:\s*$cid")
    Record 'ops: correlation-id response header' ($(if($hasCid){'PASS'}else{'FAIL'})) $(if($hasCid){'echoed'}else{'missing'})

    # 12b. Readiness fails (503) when Redis is down; liveness stays 200; recovers after restart.
    & docker @Compose stop redis *> (Join-Path $Ev 'ops-redis.txt')
    Start-Sleep 6
    $rdyDown=HttpStatus 'http://localhost:8080/api/v1/health/ready'
    $liveDown=HttpStatus 'http://localhost:8080/api/v1/health/live'
    & docker @Compose start redis *>> (Join-Path $Ev 'ops-redis.txt')
    $rec=$false; $deadline=(Get-Date).AddSeconds(90)
    while((Get-Date) -lt $deadline){ if((HttpStatus 'http://localhost:8080/api/v1/health/ready') -eq '200'){ $rec=$true; break }; Start-Sleep 5 }
    Record 'ops: readiness 503 when redis down' ($(if($rdyDown -eq '503'){'PASS'}else{'FAIL'})) "HTTP $rdyDown"
    Record 'ops: liveness stays 200 (redis down)' ($(if($liveDown -eq '200'){'PASS'}else{'FAIL'})) "HTTP $liveDown"
    Record 'ops: readiness recovers after restart' ($(if($rec){'PASS'}else{'FAIL'})) $(if($rec){'recovered'}else{'not recovered'})

    # 12c. Backup / restore drill inside the stack Postgres (checksum + source/restored table counts).
    Gate 'ops: backup + checksum + restore + table-count' 'backup-restore.txt' {
        & docker @Compose exec -T postgres sh -lc @'
set -e
SRC=$(psql -U helbaron -d helbaron -tAc "select count(*) from information_schema.tables where table_schema='public'")
pg_dump -U helbaron -Fc helbaron > /tmp/rc.dump
sha256sum /tmp/rc.dump | tee /tmp/rc.sha256
sha256sum -c /tmp/rc.sha256
dropdb -U helbaron --if-exists rc_restore; createdb -U helbaron rc_restore
pg_restore -U helbaron --clean --if-exists --no-owner -d rc_restore /tmp/rc.dump
RES=$(psql -U helbaron -d rc_restore -tAc "select count(*) from information_schema.tables where table_schema='public'")
dropdb -U helbaron rc_restore
echo "source_tables=$SRC restored_tables=$RES"
test "$SRC" = "$RES"
'@
    } | Out-Null
}
}
finally {
    # Teardown must never throw (e.g. docker missing) or it would mask the summary. Guard each call.
    try { & docker rm -f $GatePg $GateRd *> (Join-Path $Ev 'gate-db-teardown.txt') } catch { }
    if($stackUp -and -not $KeepStackUp){
        Log 'Tearing down stack (docker compose down -v)...'
        try { & docker @Compose down -v *> (Join-Path $Ev 'container-teardown.txt') } catch { }
    }
    $ErrorActionPreference = $prevBodyEAP
}

# ================================================================== 13. Summary + evidence
$fail = @($results | Where-Object status -eq 'FAIL')
$pass = @($results | Where-Object status -eq 'PASS').Count
$skip = @($results | Where-Object status -eq 'SKIP').Count
$overall = if($fail.Count -eq 0 -and -not $Aborted){'PASS'}else{'FAIL'}

$sb = @()
$sb += "HELBARON W09 Local UAT - 1.0.0-rc.1"
$sb += "timestamp=$Stamp host=$env:COMPUTERNAME"
$sb += "overall=$overall  pass=$pass fail=$($fail.Count) skip=$skip  aborted=$Aborted"
$sb += ''
$sb += ($results | ForEach-Object { "{0,-42} {1} {2}" -f $_.gate,$_.status,$_.detail })
Save 'summary.txt' ($sb -join "`r`n")
$json = [pscustomobject]@{ version=$ExpectedVersion; timestamp=$Stamp; overall=$overall; pass=$pass; fail=$fail.Count; skip=$skip; aborted=$Aborted; results=$results } |
    ConvertTo-Json -Depth 5
Save 'summary.json' $json
Save 'failures.txt' ($(if($fail.Count){ ($fail | ForEach-Object { "{0}  {1}" -f $_.gate,$_.detail }) -join "`r`n" }else{'none'}))

Write-Host ''
Write-Host '==================================================' -ForegroundColor White
Write-Host ("  W09 LOCAL UAT: {0}  (pass={1} fail={2} skip={3})" -f $overall,$pass,$fail.Count,$skip) -ForegroundColor $(if($overall -eq 'PASS'){'Green'}else{'Red'})
Write-Host "  Evidence: $Ev" -ForegroundColor White
if($fail.Count){ Write-Host '  Failed gates:' -ForegroundColor Red; $fail | ForEach-Object { Write-Host "   - $($_.gate): $($_.detail)" -ForegroundColor Red } }
Write-Host '==================================================' -ForegroundColor White
if($overall -eq 'PASS'){ Write-Host 'W09 LOCAL UAT: PASS' -ForegroundColor Green; exit 0 }
else { Write-Host 'W09 LOCAL UAT: FAIL' -ForegroundColor Red; exit 1 }
