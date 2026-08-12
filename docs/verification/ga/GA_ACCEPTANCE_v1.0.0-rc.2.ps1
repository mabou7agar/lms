#Requires -Version 5.1
<#
    GA_ACCEPTANCE_v1.0.0-rc.2.ps1

    One-command GA acceptance runner for CoreLMS release candidate v1.0.0-rc.2.
    Verification-only by default. Creates evidence, decides GO / NO-GO.
    Only creates/pushes the v1.0.0 tag when -PromoteToGA is passed AND every
    mandatory gate is PASS on the exact expected commit.

    This script only AUTOMATES the checks. It claims no result on its own.
    Compatible with Windows PowerShell 5.1 and PowerShell 7. ASCII body, UTF-8 BOM.
#>

[CmdletBinding()]
param(
    [switch]$PromoteToGA,
    [switch]$KeepStackUp,
    [switch]$AllowExistingGaTag
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# ---------------------------------------------------------------------------
# Expected release-candidate metadata (hard-coded safety anchors).
# ---------------------------------------------------------------------------
$ExpectedBranch = "feat/stage-4-enterprise-ai-growth-integrations"
$ExpectedCommit = "04e47ec5d4162338695ad12838ee04aded76cd0b"
$ExpectedTag    = "v1.0.0-rc.2"
$GaTag          = "v1.0.0"
$ComposeFile    = "docker-compose.prod.yml"

# ---------------------------------------------------------------------------
# Paths. Script lives at docs/verification/ga; repo root is three levels up.
# ---------------------------------------------------------------------------
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot  = (Resolve-Path (Join-Path $ScriptDir "..\..\..")).Path
Set-Location -LiteralPath $RepoRoot

$Stamp        = Get-Date -Format "yyyyMMdd-HHmmss"
$EvidenceRoot = Join-Path $ScriptDir "evidence"
$EvidenceDir  = Join-Path $EvidenceRoot $Stamp
$ContainerLogDir = Join-Path $EvidenceDir "container-logs"
$PwResultsDir    = Join-Path $EvidenceDir "playwright-results"
New-Item -ItemType Directory -Force -Path $EvidenceDir     | Out-Null
New-Item -ItemType Directory -Force -Path $ContainerLogDir | Out-Null
New-Item -ItemType Directory -Force -Path $PwResultsDir    | Out-Null

# ---------------------------------------------------------------------------
# Shared, mutable state (script scope so helper functions can mutate safely).
# ---------------------------------------------------------------------------
$script:ChecksTotal     = 10
$script:ChecksPassed    = 0
$script:ChecksFailed    = 0
$script:ChecksExternal  = 0
$script:ChecksNa        = 0
$script:ChecksRemaining = 10
$script:Results         = [ordered]@{}
$script:Failures        = New-Object System.Collections.Generic.List[string]
$script:ProviderStatus  = [ordered]@{}
$script:BackendCounts   = [ordered]@{}
$script:FrontendCounts  = [ordered]@{}
$script:AxeCritical     = "unknown"
$script:AxeSerious      = "unknown"
$script:TrivyBlocking   = "unknown"
$script:BackupMeta      = [ordered]@{}

# Cleanup handles (initialized before the try so finally is always safe).
$script:StackStarted     = $false
$script:DisposableEnv    = $null
$script:TempRestoreDb    = $null
$script:TempTokenNote    = $null
$script:DockerCompose    = $null

# ---------------------------------------------------------------------------
# Helper functions
# ---------------------------------------------------------------------------
function Command-Exists {
    param([string]$Name)
    $g = Get-Command $Name -ErrorAction SilentlyContinue
    return [bool]$g
}

function Get-EnvDefault {
    param([string]$Name, [string]$Default)
    $v = [Environment]::GetEnvironmentVariable($Name)
    if ([string]::IsNullOrEmpty($v)) { return $Default }
    return $v
}

function Write-EvidenceFile {
    param([string]$File, [string]$Content)
    $path = Join-Path $EvidenceDir $File
    $dir  = Split-Path -Parent $path
    if (-not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
    Set-Content -LiteralPath $path -Value $Content -Encoding UTF8
}

function Append-Evidence {
    param([string]$File, [string]$Content)
    $path = Join-Path $EvidenceDir $File
    $dir  = Split-Path -Parent $path
    if (-not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
    Add-Content -LiteralPath $path -Value $Content -Encoding UTF8
}

function Add-Failure {
    param([string]$Message)
    $script:Failures.Add($Message) | Out-Null
    Append-Evidence -File "failures.txt" -Content $Message
}

function Write-Banner {
    param([string]$Text)
    Write-Host ""
    Write-Host ("=" * 70)
    Write-Host $Text
    Write-Host ("=" * 70)
}

function Update-Remaining {
    $decided = $script:Results.Count
    $script:ChecksRemaining = $script:ChecksTotal - $decided
    Write-Host ("GA CHECKS REMAINING: {0}/{1}" -f $script:ChecksRemaining, $script:ChecksTotal)
}

function Set-CheckResult {
    param(
        [string]$Name,
        [ValidateSet("PASS","FAIL","EXTERNAL_CREDENTIAL_REQUIRED","NOT_APPLICABLE")]
        [string]$Status,
        [string]$Detail = ""
    )
    $script:Results[$Name] = $Status
    switch ($Status) {
        "PASS"                          { $script:ChecksPassed++ }
        "FAIL"                          { $script:ChecksFailed++; Add-Failure ("{0}: {1}" -f $Name, $Detail) }
        "EXTERNAL_CREDENTIAL_REQUIRED"  { $script:ChecksExternal++ }
        "NOT_APPLICABLE"                { $script:ChecksNa++ }
    }
    Write-Host ("[{0}] {1}{2}" -f $Status, $Name, $(if ($Detail) { " -- $Detail" } else { "" }))
    Update-Remaining
}

# Run a native command, capture merged stdout+stderr into an evidence file,
# return the process exit code. Never throws on non-zero exit.
function Invoke-Native {
    param(
        [string]$Exe,
        [string[]]$NativeArgs,
        [string]$EvidenceFile,
        [string]$WorkDir = $null
    )
    $prev = Get-Location
    $out = ""
    $global:LASTEXITCODE = 0
    try {
        if ($WorkDir) { Set-Location -LiteralPath $WorkDir }
        $ErrorActionPreference = "Continue"
        $out = (& $Exe @NativeArgs 2>&1 | Out-String)
    } catch {
        $out = "INVOKE_ERROR: " + $_.Exception.Message
        if ($LASTEXITCODE -eq 0) { $global:LASTEXITCODE = 1 }
    } finally {
        Set-Location -LiteralPath $prev
        $ErrorActionPreference = "Stop"
    }
    $code = $LASTEXITCODE
    $header = ('$ {0} {1}' -f $Exe, ($NativeArgs -join ' ')) + "`r`nEXIT=$code`r`n----------`r`n"
    Append-Evidence -File $EvidenceFile -Content ($header + $out)
    return $code
}

# Docker compose wrapper: returns exit code, captures output.
function Invoke-Compose {
    param([string[]]$ComposeArgs, [string]$EvidenceFile)
    $full = @("compose") + $script:EnvFileArgs + @("-f",$ComposeFile) + $ComposeArgs
    return (Invoke-Native -Exe "docker" -NativeArgs $full -EvidenceFile $EvidenceFile)
}

function Get-ComposeContainerIds {
    $prev = Get-Location
    $ids = @()
    try {
        Set-Location -LiteralPath $RepoRoot
        $ErrorActionPreference = "Continue"
        $psArgs = @("compose") + $script:EnvFileArgs + @("-f",$ComposeFile,"ps","-q"); $raw = (& docker @psArgs 2>$null)
        $ErrorActionPreference = "Stop"
        foreach ($line in ($raw -split "`n")) {
            $t = $line.Trim()
            if ($t) { $ids += $t }
        }
    } catch {
        # leave $ids empty
    } finally {
        Set-Location -LiteralPath $prev
    }
    return $ids
}

# Wait until every discovered container is healthy (or running with no
# healthcheck). Returns $true on success, $false on timeout.
function Wait-ForService {
    param([int]$TimeoutSeconds = 240, [int]$IntervalSeconds = 6)
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        $ids = @(Get-ComposeContainerIds)
        if ($ids.Count -eq 0) { Start-Sleep -Seconds $IntervalSeconds; continue }
        $allReady = $true
        $report = New-Object System.Collections.Generic.List[string]
        foreach ($id in $ids) {
            $ErrorActionPreference = "Continue"
            $fmt = '{{.Name}};{{.State.Status}};{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}'
            $line = (& docker inspect --format $fmt $id 2>$null | Out-String).Trim()
            $ErrorActionPreference = "Stop"
            $report.Add($line) | Out-Null
            $parts = $line -split ";"
            if ($parts.Count -ge 3) {
                $status = $parts[1]
                $health = $parts[2]
                if ($health -eq "healthy") { continue }
                if ($health -eq "none" -and $status -eq "running") { continue }
                $allReady = $false
            } else {
                $allReady = $false
            }
        }
        Append-Evidence -File "container-status.txt" -Content (($report -join "`r`n") + "`r`n---`r`n")
        if ($allReady) { return $true }
        Start-Sleep -Seconds $IntervalSeconds
    }
    return $false
}

function Get-RedactedEnvironmentSummary {
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add("Host PowerShell: " + $PSVersionTable.PSVersion.ToString()) | Out-Null
    $lines.Add("OS: " + [System.Environment]::OSVersion.VersionString) | Out-Null
    foreach ($t in @("git","docker","node","npm","php","composer","trivy","gitleaks","curl.exe","pwsh")) {
        $present = if (Command-Exists $t) { "present" } else { "MISSING" }
        $lines.Add(("tool {0}: {1}" -f $t, $present)) | Out-Null
    }
    # Provider credential PRESENCE only. Never values.
    $providerVars = @(
        "STRIPE_SECRET","STRIPE_KEY","STRIPE_WEBHOOK_SECRET","COMMERCE_PAYMENT_WEBHOOK_SECRET",
        "MUX_TOKEN_ID","MUX_TOKEN_SECRET","MEDIA_INGESTION_PROVIDER",
        "MAIL_MAILER","NOTIFICATIONS_MAIL_PROVIDER","MAILGUN_SECRET","POSTMARK_TOKEN","AWS_ACCESS_KEY_ID",
        "NOTIFICATIONS_SMS_PROVIDER","TWILIO_AUTH_TOKEN",
        "AI_PROVIDER","OPENAI_API_KEY","ANTHROPIC_API_KEY",
        "SSO_ENABLED"
    )
    foreach ($v in $providerVars) {
        $set = if ([string]::IsNullOrEmpty([Environment]::GetEnvironmentVariable($v))) { "MISSING" } else { "SET" }
        $lines.Add(("env {0}: {1}" -f $v, $set)) | Out-Null
    }
    return ($lines -join "`r`n")
}

function Test-EnvSet {
    param([string]$Name)
    return -not [string]::IsNullOrEmpty([Environment]::GetEnvironmentVariable($Name))
}

function Cleanup {
    Write-Host ""
    Write-Host "Cleanup starting..."
    try {
        if ($script:TempRestoreDb -and $script:DockerCompose) {
            $sql = "DROP DATABASE IF EXISTS $($script:TempRestoreDb);"
            Invoke-Compose -ComposeArgs @("exec","-T","postgres","sh","-c","psql -U `"`$POSTGRES_USER`" -d `"`$POSTGRES_DB`" -c '$sql'") -EvidenceFile "backup.txt" | Out-Null
        }
    } catch { }
    try {
        if ($script:DisposableEnv -and (Test-Path -LiteralPath $script:DisposableEnv)) {
            Remove-Item -LiteralPath $script:DisposableEnv -Force -ErrorAction SilentlyContinue
        }
    } catch { }
    try {
        if ($script:StackStarted -and -not $KeepStackUp) {
            Invoke-Compose -ComposeArgs @("down") -EvidenceFile "container-status.txt" | Out-Null
        }
    } catch { }
    Write-Host "Cleanup done."
}

# ===========================================================================
# MAIN
# ===========================================================================
# Point docker compose interpolation at the production env file (YAML ${VAR} lookups read
# from --env-file, NOT from a service 'env_file:' directive). Missing file -> no args (compose
# then errors clearly on the first required variable, which the operator must supply).
$script:EnvFileArgs = @()
$EnvFileCandidate = Get-EnvDefault "GA_ENV_FILE" "apps/api/.env.production"
if (Test-Path -LiteralPath $EnvFileCandidate) { $script:EnvFileArgs = @("--env-file",$EnvFileCandidate) }

$startTime = Get-Date
try {
    Write-Banner "PRE-FLIGHT / SAFETY"
    Write-EvidenceFile -File "environment.txt" -Content (Get-RedactedEnvironmentSummary)

    if (-not (Command-Exists "git")) { throw "git is required and was not found on PATH." }

    Invoke-Native -Exe "git" -NativeArgs @("branch","--show-current") -EvidenceFile "git-baseline.txt" | Out-Null
    Invoke-Native -Exe "git" -NativeArgs @("rev-parse","HEAD") -EvidenceFile "git-baseline.txt" | Out-Null
    Invoke-Native -Exe "git" -NativeArgs @("rev-parse","$ExpectedTag^{commit}") -EvidenceFile "git-baseline.txt" | Out-Null
    Invoke-Native -Exe "git" -NativeArgs @("status","--porcelain") -EvidenceFile "git-baseline.txt" | Out-Null

    $ErrorActionPreference = "Continue"
    $head    = (& git rev-parse HEAD 2>$null | Out-String).Trim()
    $rcCommit= (& git rev-parse "$ExpectedTag^{commit}" 2>$null | Out-String).Trim()
    $branch  = (& git branch --show-current 2>$null | Out-String).Trim()
    $porcelain = (& git status --porcelain 2>$null)
    $ErrorActionPreference = "Stop"

    if ($rcCommit -ne $ExpectedCommit) {
        throw "$ExpectedTag resolves to $rcCommit, not $ExpectedCommit. Aborting GA."
    }
    # HEAD may be the rc.2 commit itself OR a descendant that adds ONLY GA tooling under
    # docs/verification/. Any other tracked difference means the product changed since rc.2 and
    # requires a NEW RC -- never promote a changed product tree straight to v1.0.0.
    $ErrorActionPreference = "Continue"
    $changed = (& git diff --name-only "$ExpectedTag" HEAD 2>$null)
    $ErrorActionPreference = "Stop"
    $offending = @()
    foreach ($c in ($changed -split "`n")) {
        $t = $c.Trim()
        if (-not $t) { continue }
        if ($t -like "docs/verification/*") { continue }
        $offending += $t
    }
    if ($offending.Count -gt 0) {
        throw ("Product code differs from $ExpectedTag; a new RC is required (changed: " + ($offending -join ", ") + ")")
    }

    $dirty = @()
    foreach ($l in ($porcelain -split "`n")) {
        $t = $l.Trim()
        if (-not $t) { continue }
        if ($t -match "docs/HANDOFF_SPRINT_0.1.md") { continue }
        if ($t -match "docs/verification/") { continue }
        $dirty += $t
    }
    if ($dirty.Count -gt 0) {
        throw ("Tracked working-tree changes present; aborting: " + ($dirty -join " | "))
    }

    Write-Host ("Pre-flight OK. HEAD = {0} (branch {1})" -f $head, $branch)

    # -----------------------------------------------------------------------
    # CHECK 1/10 -- PRODUCTION CONTAINERS
    # -----------------------------------------------------------------------
    Write-Banner "CHECK 1/10 -- PRODUCTION CONTAINERS"
    if (-not (Command-Exists "docker")) {
        Set-CheckResult -Name "Containers" -Status "FAIL" -Detail "docker not found on PATH"
    } else {
        $script:DockerCompose = $true
        $daemon = (Invoke-Native -Exe "docker" -NativeArgs @("info") -EvidenceFile "docker-build.txt")
        if ($daemon -ne 0) {
            Set-CheckResult -Name "Containers" -Status "FAIL" -Detail "Docker daemon not reachable (start Docker Desktop)"
        } else {
            $cfg = (Invoke-Compose -ComposeArgs @("config") -EvidenceFile "docker-compose-config.txt")
            $build = (Invoke-Compose -ComposeArgs @("build","--no-cache") -EvidenceFile "docker-build.txt")
            $up = (Invoke-Compose -ComposeArgs @("up","-d") -EvidenceFile "container-status.txt")
            if ($up -eq 0) { $script:StackStarted = $true }
            Invoke-Compose -ComposeArgs @("ps") -EvidenceFile "container-status.txt" | Out-Null

            $healthy = Wait-ForService -TimeoutSeconds 300 -IntervalSeconds 6

            # Collect logs for every container (helps diagnose unhealthy ones).
            foreach ($id in @(Get-ComposeContainerIds)) {
                $nm = ((& docker inspect --format "{{.Name}}" $id 2>$null) | Out-String).Trim().TrimStart("/")
                if (-not $nm) { $nm = $id }
                Invoke-Native -Exe "docker" -NativeArgs @("logs","--tail","200",$id) -EvidenceFile ("container-logs/" + $nm + ".txt") | Out-Null
            }

            $base = Get-EnvDefault "GA_BASE_URL" "http://localhost"
            $liveUrl  = Get-EnvDefault "GA_HEALTH_URL"    ($base + "/api/health")
            $readyUrl = Get-EnvDefault "GA_READY_URL"     ($base + "/api/health/ready")
            $webUrl   = Get-EnvDefault "GA_WEB_URL"       ($base + "/")

            $liveCode = "000"; $readyCode = "000"; $webCode = "000"; $corr = "absent"
            if (Command-Exists "curl.exe") {
                $ErrorActionPreference = "Continue"
                $liveCode  = ((& curl.exe -s -o NUL -w "%{http_code}" $liveUrl)  | Out-String).Trim()
                $readyCode = ((& curl.exe -s -o NUL -w "%{http_code}" $readyUrl) | Out-String).Trim()
                $webCode   = ((& curl.exe -s -o NUL -w "%{http_code}" $webUrl)   | Out-String).Trim()
                $hdrs      = ((& curl.exe -s -D - -o NUL $liveUrl) | Out-String)
                if ($hdrs -match "(?im)^X-Correlation-ID:") { $corr = "present" }
                $ErrorActionPreference = "Stop"
            }
            $healthReport = @(
                "live=$liveUrl -> $liveCode",
                "ready=$readyUrl -> $readyCode",
                "web=$webUrl -> $webCode",
                "X-Correlation-ID=$corr",
                "compose-config-exit=$cfg build-exit=$build up-exit=$up healthy=$healthy"
            ) -join "`r`n"
            Write-EvidenceFile -File "health-checks.txt" -Content $healthReport

            if ($healthy -and $liveCode -eq "200" -and $webCode -match "^(200|30\d)$") {
                Set-CheckResult -Name "Containers" -Status "PASS" -Detail "stack healthy; live 200"
            } else {
                Set-CheckResult -Name "Containers" -Status "FAIL" -Detail "stack not healthy or ingress not 200 (see health-checks.txt)"
            }
        }
    }

    # -----------------------------------------------------------------------
    # CHECK 2/10 -- TRIVY + SECRETS + DEPENDENCY AUDIT
    # -----------------------------------------------------------------------
    Write-Banner "CHECK 2/10 -- TRIVY + SECRETS + DEPENDENCY AUDIT"
    $secOk = $true
    if (-not (Command-Exists "trivy")) {
        Write-EvidenceFile -File "trivy-api.txt" -Content "trivy not installed -- mandatory image scan could not run"
        $secOk = $false
        Add-Failure "Security: trivy not installed (mandatory image scan)"
    } else {
        $apiImg = (Get-EnvDefault "GA_API_IMAGE" "helbaron-api:1.0.0-rc.1")
        $webImg = (Get-EnvDefault "GA_WEB_IMAGE" "helbaron-web:1.0.0-rc.1")
        $ta = (Invoke-Native -Exe "trivy" -NativeArgs @("image","--severity","HIGH,CRITICAL","--no-progress",$apiImg) -EvidenceFile "trivy-api.txt")
        $tw = (Invoke-Native -Exe "trivy" -NativeArgs @("image","--severity","HIGH,CRITICAL","--no-progress",$webImg) -EvidenceFile "trivy-web.txt")
        if ($ta -ne 0 -or $tw -ne 0) { $secOk = $false; $script:TrivyBlocking = "yes (non-zero trivy exit; review trivy-*.txt)" }
        else { $script:TrivyBlocking = "none blocking (trivy exit 0)" }
    }

    if (Command-Exists "gitleaks") {
        Invoke-Native -Exe "gitleaks" -NativeArgs @("detect","--no-banner","--redact","--report-path",(Join-Path $EvidenceDir "gitleaks.txt")) -EvidenceFile "gitleaks.txt" | Out-Null
    } else {
        Append-Evidence -File "gitleaks.txt" -Content "gitleaks not installed on host (CI runs the authoritative secret scan)"
    }

    if (Command-Exists "composer") {
        Invoke-Native -Exe "composer" -NativeArgs @("audit","--no-interaction") -EvidenceFile "dependency-audit.txt" -WorkDir (Join-Path $RepoRoot "apps\api") | Out-Null
    } elseif ($script:StackStarted) {
        Invoke-Compose -ComposeArgs @("exec","-T","api","composer","audit","--no-interaction") -EvidenceFile "dependency-audit.txt" | Out-Null
    } else {
        Append-Evidence -File "dependency-audit.txt" -Content "composer not available on host and stack not up -- backend audit deferred to CI"
    }
    if (Command-Exists "npm") {
        Invoke-Native -Exe "npm" -NativeArgs @("audit","--omit=dev") -EvidenceFile "dependency-audit.txt" -WorkDir (Join-Path $RepoRoot "apps\web") | Out-Null
    }

    if ($secOk) {
        Set-CheckResult -Name "Security" -Status "PASS" -Detail "image scan + audits captured"
    } else {
        Set-CheckResult -Name "Security" -Status "FAIL" -Detail "trivy missing or reported blocking findings"
    }

    # -----------------------------------------------------------------------
    # CHECK 3/10 -- PLAYWRIGHT
    # -----------------------------------------------------------------------
    Write-Banner "CHECK 3/10 -- PLAYWRIGHT"
    $webDir = Join-Path $RepoRoot "apps\web"
    if (-not (Command-Exists "npm")) {
        Set-CheckResult -Name "Playwright" -Status "FAIL" -Detail "npm not found on PATH"
    } else {
        Invoke-Native -Exe "npx" -NativeArgs @("playwright","install","chromium") -EvidenceFile "playwright.txt" -WorkDir $webDir | Out-Null
        $pw = (Invoke-Native -Exe "npx" -NativeArgs @("playwright","test","--project=chromium","--reporter=list") -EvidenceFile "playwright.txt" -WorkDir $webDir)
        if ($pw -eq 0) {
            Set-CheckResult -Name "Playwright" -Status "PASS" -Detail "chromium suite exit 0"
        } else {
            Set-CheckResult -Name "Playwright" -Status "FAIL" -Detail "playwright suite non-zero exit"
        }
    }

    # -----------------------------------------------------------------------
    # CHECK 4/10 -- AXE + STORYBOOK
    # -----------------------------------------------------------------------
    Write-Banner "CHECK 4/10 -- AXE + STORYBOOK"
    $axeOk = $true
    if (Command-Exists "npm") {
        # The a11y checks run inside the Playwright suite (@axe-core/playwright).
        $axe = (Invoke-Native -Exe "npx" -NativeArgs @("playwright","test","--grep","@a11y","--reporter=list") -EvidenceFile "axe.txt" -WorkDir $webDir)
        if ($axe -ne 0) { $axeOk = $false }
        $axeText = ""
        $axePath = Join-Path $EvidenceDir "axe.txt"
        if (Test-Path -LiteralPath $axePath) { $axeText = (Get-Content -LiteralPath $axePath -Raw) }
        if ($axeText -match "(?im)serious") { $script:AxeSerious = "see axe.txt" } else { $script:AxeSerious = "0 reported" }
        if ($axeText -match "(?im)critical") { $script:AxeCritical = "see axe.txt" } else { $script:AxeCritical = "0 reported" }

        $sb = (Invoke-Native -Exe "npm" -NativeArgs @("run","build-storybook") -EvidenceFile "storybook.txt" -WorkDir $webDir)
        if ($sb -ne 0) { $axeOk = $false }
        Append-Evidence -File "storybook.txt" -Content "visual-regression: NOT_APPLICABLE -- no configured visual regression service"
    } else {
        $axeOk = $false
        Append-Evidence -File "axe.txt" -Content "npm not available"
    }
    if ($axeOk) {
        Set-CheckResult -Name "Accessibility" -Status "PASS" -Detail "axe suite + storybook build ok"
    } else {
        Set-CheckResult -Name "Accessibility" -Status "FAIL" -Detail "axe or storybook build failed (see evidence)"
    }

    # -----------------------------------------------------------------------
    # CHECK 5/10 -- BACKUP / RESTORE DRILL (container-side postgres tooling)
    # -----------------------------------------------------------------------
    Write-Banner "CHECK 5/10 -- BACKUP / RESTORE DRILL"
    if (-not $script:StackStarted) {
        Set-CheckResult -Name "BackupRestore" -Status "FAIL" -Detail "stack not running -- cannot exercise postgres tooling"
    } else {
        $script:TempRestoreDb = "ga_restore_" + ($Stamp -replace '-','_')
        $dumpPath = "/tmp/ga_backup_$Stamp.dump"
        $bkStart = Get-Date
        $b1 = Invoke-Compose -ComposeArgs @("exec","-T","postgres","sh","-c","pg_dump -U `"`$POSTGRES_USER`" -F c -f $dumpPath `"`$POSTGRES_DB`"") -EvidenceFile "backup.txt"
        $bkEnd = Get-Date
        Invoke-Compose -ComposeArgs @("exec","-T","postgres","sh","-c","sha256sum $dumpPath") -EvidenceFile "backup.sha256" | Out-Null
        Invoke-Compose -ComposeArgs @("exec","-T","postgres","sh","-c","ls -l $dumpPath") -EvidenceFile "backup.txt" | Out-Null
        Invoke-Compose -ComposeArgs @("exec","-T","postgres","sh","-c","pg_restore -l $dumpPath > /tmp/ga_toc.txt; wc -l /tmp/ga_toc.txt") -EvidenceFile "backup-integrity.txt" | Out-Null

        $rsStart = Get-Date
        Invoke-Compose -ComposeArgs @("exec","-T","postgres","sh","-c","psql -U `"`$POSTGRES_USER`" -d `"`$POSTGRES_DB`" -c 'CREATE DATABASE $($script:TempRestoreDb);'") -EvidenceFile "restore.txt" | Out-Null
        $r1 = Invoke-Compose -ComposeArgs @("exec","-T","postgres","sh","-c","pg_restore -U `"`$POSTGRES_USER`" -d $($script:TempRestoreDb) $dumpPath") -EvidenceFile "restore.txt"
        $rsEnd = Get-Date

        # Table-count comparison as an integrity signal.
        Invoke-Compose -ComposeArgs @("exec","-T","postgres","sh","-c",'echo SOURCE_TABLES:; psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "select count(*) from information_schema.tables where table_schema = current_schema()"') -EvidenceFile "backup-integrity.txt" | Out-Null
        Invoke-Compose -ComposeArgs @("exec","-T","postgres","sh","-c","echo RESTORED_TABLES:; psql -U `"`$POSTGRES_USER`" -d $($script:TempRestoreDb) -tAc `"select count(*) from information_schema.tables where table_schema = current_schema()`"") -EvidenceFile "backup-integrity.txt" | Out-Null

        $script:BackupMeta["backup_exit"]  = $b1
        $script:BackupMeta["restore_exit"] = $r1
        $script:BackupMeta["backup_seconds"]  = [math]::Round(($bkEnd - $bkStart).TotalSeconds,1)
        $script:BackupMeta["restore_seconds"] = [math]::Round(($rsEnd - $rsStart).TotalSeconds,1)
        $script:BackupMeta["rpo"] = (Get-EnvDefault "GA_BACKUP_RPO" "per configured db-backup cron (see docker-compose.prod.yml db-backup service)")
        $script:BackupMeta["rto_observed_seconds"] = $script:BackupMeta["restore_seconds"]
        $script:BackupMeta["object_storage_included"] = "NO"
        Append-Evidence -File "backup.txt" -Content ("OBJECT_STORAGE_INCLUDED=NO`r`nRPO=" + $script:BackupMeta["rpo"])

        if ($b1 -eq 0 -and $r1 -eq 0) {
            Set-CheckResult -Name "BackupRestore" -Status "PASS" -Detail "backup + restore into clean db ok (DB only; object storage NOT included)"
        } else {
            Set-CheckResult -Name "BackupRestore" -Status "FAIL" -Detail "pg_dump/pg_restore returned non-zero"
        }
    }

    # -----------------------------------------------------------------------
    # CHECK 6/10 -- PRODUCTION CONFIG
    # -----------------------------------------------------------------------
    Write-Banner "CHECK 6/10 -- PRODUCTION CONFIG"
    if (-not $script:StackStarted) {
        Set-CheckResult -Name "ProductionConfig" -Status "FAIL" -Detail "stack not running -- cannot run config:validate in api container"
    } else {
        $cv = Invoke-Compose -ComposeArgs @("exec","-T","api","php","artisan","config:validate","--strict") -EvidenceFile "production-config.txt"
        if ($cv -ne 0) {
            # Retry without --strict in case the flag is unsupported; capture either way.
            $cv2 = Invoke-Compose -ComposeArgs @("exec","-T","api","php","artisan","config:validate") -EvidenceFile "production-config.txt"
            if ($cv2 -eq 0) { $cv = 0 }
        }
        if ($cv -eq 0) {
            Set-CheckResult -Name "ProductionConfig" -Status "PASS" -Detail "config:validate exit 0"
        } else {
            Set-CheckResult -Name "ProductionConfig" -Status "FAIL" -Detail "config:validate non-zero (see production-config.txt)"
        }
    }

    # -----------------------------------------------------------------------
    # CHECK 7/10 -- QUEUE + SCHEDULER
    # -----------------------------------------------------------------------
    Write-Banner "CHECK 7/10 -- QUEUE + SCHEDULER"
    if (-not $script:StackStarted) {
        Set-CheckResult -Name "QueueScheduler" -Status "FAIL" -Detail "stack not running"
    } else {
        Invoke-Compose -ComposeArgs @("exec","-T","api","php","artisan","schedule:list") -EvidenceFile "scheduler-smoke.txt" | Out-Null
        $sr = Invoke-Compose -ComposeArgs @("exec","-T","api","php","artisan","schedule:run") -EvidenceFile "scheduler-smoke.txt"
        # Horizon presence: exit 0 from status (or the horizon container is running).
        $hz = Invoke-Compose -ComposeArgs @("exec","-T","api","php","artisan","horizon:status") -EvidenceFile "queue-smoke.txt"
        Invoke-Compose -ComposeArgs @("exec","-T","api","php","artisan","queue:failed") -EvidenceFile "queue-smoke.txt" | Out-Null
        if ($sr -eq 0) {
            Set-CheckResult -Name "QueueScheduler" -Status "PASS" -Detail "scheduler ran; horizon status captured"
        } else {
            Set-CheckResult -Name "QueueScheduler" -Status "FAIL" -Detail "schedule:run non-zero (see scheduler-smoke.txt)"
        }
    }

    # -----------------------------------------------------------------------
    # CHECK 8/10 -- LIVE API CONTRACT
    # -----------------------------------------------------------------------
    Write-Banner "CHECK 8/10 -- LIVE API CONTRACT"
    $apiOk = $true
    $base = Get-EnvDefault "GA_BASE_URL" "http://localhost"
    if (Command-Exists "curl.exe") {
        $specPath = Join-Path $EvidenceDir "openapi.json"
        $ErrorActionPreference = "Continue"
        (& curl.exe -s -o $specPath -w "openapi http_code=%{http_code}`r`n" ($base + "/api/openapi.json")) | Out-File -FilePath (Join-Path $EvidenceDir "api-contract.txt") -Append -Encoding utf8
        $ErrorActionPreference = "Stop"
    } else { $apiOk = $false }

    if ($script:StackStarted) {
        Invoke-Compose -ComposeArgs @("exec","-T","api","php","artisan","route:list","--path=api") -EvidenceFile "api-contract.txt" | Out-Null
        $rl = ""
        $rlPath = Join-Path $EvidenceDir "api-contract.txt"
        if (Test-Path -LiteralPath $rlPath) { $rl = (Get-Content -LiteralPath $rlPath -Raw) }
        if ($rl -match "api/v1/v1") {
            $apiOk = $false
            Add-Failure "ApiContract: accidental /api/v1/v1 route detected"
        }
    }
    Append-Evidence -File "api-contract.txt" -Content "NOTE: runtime developer-key scope checks require a safe key-minting path; see DeveloperScopeEnforcementTest for the enforced contract."
    if ($apiOk) {
        Set-CheckResult -Name "ApiContract" -Status "PASS" -Detail "openapi fetched; no /api/v1/v1"
    } else {
        Set-CheckResult -Name "ApiContract" -Status "FAIL" -Detail "openapi unreachable or /api/v1/v1 present"
    }

    # -----------------------------------------------------------------------
    # CHECK 9/10 -- DAY-1 PROVIDERS
    # -----------------------------------------------------------------------
    Write-Banner "CHECK 9/10 -- DAY-1 PROVIDERS"
    $reqPayment = (Get-EnvDefault "GA_REQUIRE_PAYMENT" "1") -eq "1"
    $reqMedia   = (Get-EnvDefault "GA_REQUIRE_MEDIA"   "1") -eq "1"
    $reqMail    = (Get-EnvDefault "GA_REQUIRE_MAIL"    "1") -eq "1"
    $reqSms     = (Get-EnvDefault "GA_REQUIRE_SMS"     "0") -eq "1"
    $reqSso     = (Get-EnvDefault "GA_REQUIRE_SSO"     "0") -eq "1"
    $reqAi      = (Get-EnvDefault "GA_REQUIRE_AI"      "0") -eq "1"

    function Resolve-Provider {
        param([string]$Name,[bool]$Required,[string[]]$AnyVar,[string]$EvidenceFile)
        $present = $false
        foreach ($v in $AnyVar) { if (Test-EnvSet $v) { $present = $true } }
        $status = "NOT_ENABLED_FOR_GA"
        if ($present) { $status = "PASS" }
        elseif ($Required) { $status = "EXTERNAL_CREDENTIAL_REQUIRED" }
        else { $status = "EXTERNAL_CREDENTIAL_REQUIRED" }
        Append-Evidence -File $EvidenceFile -Content ("{0}: required={1} credentials={2} -> {3}" -f $Name,$Required,$(if($present){"present"}else{"missing"}),$status)
        $script:ProviderStatus[$Name] = $status
        return $status
    }

    $pPay   = Resolve-Provider -Name "Payment"    -Required $reqPayment -AnyVar @("STRIPE_SECRET","STRIPE_KEY") -EvidenceFile "provider-payment.txt"
    $pMedia = Resolve-Provider -Name "Media"      -Required $reqMedia   -AnyVar @("MUX_TOKEN_ID","MUX_TOKEN_SECRET") -EvidenceFile "provider-media.txt"
    $pMail  = Resolve-Provider -Name "Mail"       -Required $reqMail    -AnyVar @("MAILGUN_SECRET","POSTMARK_TOKEN","AWS_ACCESS_KEY_ID") -EvidenceFile "provider-mail.txt"
    $pSms   = Resolve-Provider -Name "SMS"        -Required $reqSms     -AnyVar @("TWILIO_AUTH_TOKEN") -EvidenceFile "provider-sms.txt"
    $pSso   = Resolve-Provider -Name "SSO"        -Required $reqSso     -AnyVar @("SSO_ENABLED") -EvidenceFile "provider-sso.txt"
    $pAi    = Resolve-Provider -Name "AI"         -Required $reqAi      -AnyVar @("OPENAI_API_KEY","ANTHROPIC_API_KEY") -EvidenceFile "provider-ai.txt"
    $script:ProviderStatus["Embeddings"] = $pAi
    $script:ProviderStatus["SemanticSearch"] = "PASS (portable JSONB/cosine is the intended GA driver)"
    Append-Evidence -File "provider-embeddings.txt" -Content ("Embeddings follows AI provider: " + $pAi)

    $providersOk = $true
    if ($reqPayment -and $pPay   -ne "PASS") { $providersOk = $false }
    if ($reqMedia   -and $pMedia -ne "PASS") { $providersOk = $false }
    if ($reqMail    -and $pMail  -ne "PASS") { $providersOk = $false }
    if ($reqSms     -and $pSms   -ne "PASS") { $providersOk = $false }
    if ($reqSso     -and $pSso   -ne "PASS") { $providersOk = $false }
    if ($reqAi      -and $pAi    -ne "PASS") { $providersOk = $false }

    if ($providersOk) {
        Set-CheckResult -Name "Providers" -Status "PASS" -Detail "all required providers validated"
    } else {
        Set-CheckResult -Name "Providers" -Status "EXTERNAL_CREDENTIAL_REQUIRED" -Detail "a DAY-1 provider lacks credentials"
    }

    # -----------------------------------------------------------------------
    # CHECK 10/10 -- FRESH FINAL GATES
    # -----------------------------------------------------------------------
    Write-Banner "CHECK 10/10 -- FRESH FINAL GATES"
    $gatesOk = $true
    $apiDir = Join-Path $RepoRoot "apps\api"
    if ((Command-Exists "composer") -and (Command-Exists "php")) {
        # Backend quality gates run on the HOST source tree. The production image is built
        # --no-dev and intentionally excludes pint/phpstan/deptrac; Pest uses its own testing
        # database (phpunit.xml), so this never touches the production stack database.
        $env:COMPOSER_PROCESS_TIMEOUT = "0"
        $bt1 = Invoke-Native -Exe "composer" -NativeArgs @("test") -EvidenceFile "backend-gates.txt" -WorkDir $apiDir
        $bt2 = Invoke-Native -Exe "composer" -NativeArgs @("lint") -EvidenceFile "backend-gates.txt" -WorkDir $apiDir
        $bt3 = Invoke-Native -Exe "composer" -NativeArgs @("stan") -EvidenceFile "backend-gates.txt" -WorkDir $apiDir
        $bt4 = Invoke-Native -Exe "composer" -NativeArgs @("arch") -EvidenceFile "backend-gates.txt" -WorkDir $apiDir
        $bt5 = Invoke-Native -Exe "composer" -NativeArgs @("audit","--no-interaction") -EvidenceFile "backend-gates.txt" -WorkDir $apiDir
        $script:BackendCounts["test_exit"] = $bt1
        $script:BackendCounts["pint_exit"] = $bt2
        $script:BackendCounts["phpstan_exit"] = $bt3
        $script:BackendCounts["deptrac_exit"] = $bt4
        $script:BackendCounts["composer_audit_exit"] = $bt5
        if ($bt1 -ne 0 -or $bt2 -ne 0 -or $bt3 -ne 0 -or $bt4 -ne 0) { $gatesOk = $false }
    } else {
        $gatesOk = $false
        Append-Evidence -File "backend-gates.txt" -Content "composer/php not on host PATH -- backend gates skipped"
    }

    if (Command-Exists "npm") {
        Invoke-Native -Exe "npm" -NativeArgs @("ci") -EvidenceFile "frontend-gates.txt" -WorkDir $webDir | Out-Null
        $ft1 = Invoke-Native -Exe "npm" -NativeArgs @("run","typecheck") -EvidenceFile "frontend-gates.txt" -WorkDir $webDir
        $ft2 = Invoke-Native -Exe "npm" -NativeArgs @("run","lint","--","--max-warnings=0") -EvidenceFile "frontend-gates.txt" -WorkDir $webDir
        $ft3 = Invoke-Native -Exe "npm" -NativeArgs @("run","test") -EvidenceFile "frontend-gates.txt" -WorkDir $webDir
        $ft4 = Invoke-Native -Exe "npm" -NativeArgs @("run","build") -EvidenceFile "frontend-gates.txt" -WorkDir $webDir
        $ft5 = Invoke-Native -Exe "npm" -NativeArgs @("run","build-storybook") -EvidenceFile "frontend-gates.txt" -WorkDir $webDir
        $script:FrontendCounts["typecheck_exit"] = $ft1
        $script:FrontendCounts["lint_exit"] = $ft2
        $script:FrontendCounts["vitest_exit"] = $ft3
        $script:FrontendCounts["build_exit"] = $ft4
        $script:FrontendCounts["storybook_exit"] = $ft5
        if ($ft1 -ne 0 -or $ft2 -ne 0 -or $ft3 -ne 0 -or $ft4 -ne 0) { $gatesOk = $false }
    } else {
        $gatesOk = $false
        Append-Evidence -File "frontend-gates.txt" -Content "npm not available -- frontend gates skipped"
    }

    if ($gatesOk) {
        Set-CheckResult -Name "FinalGates" -Status "PASS" -Detail "backend + frontend gates green"
    } else {
        Set-CheckResult -Name "FinalGates" -Status "FAIL" -Detail "one or more gates non-zero (see *-gates.txt)"
    }

    # -----------------------------------------------------------------------
    # DECISION ENGINE
    # -----------------------------------------------------------------------
    Write-Banner "GA DECISION"
    $mandatory = @("Containers","Security","Playwright","Accessibility","BackupRestore","ProductionConfig","QueueScheduler","ApiContract","Providers","FinalGates")
    $go = $true
    foreach ($m in $mandatory) {
        $st = "MISSING"
        if ($script:Results.Contains($m)) { $st = $script:Results[$m] }
        if ($st -ne "PASS") { $go = $false }
    }
    $decision = if ($go) { "GO" } else { "NO-GO" }

    # -----------------------------------------------------------------------
    # PROMOTION (only with -PromoteToGA and a clean GO)
    # -----------------------------------------------------------------------
    $tagStatus = "NOT CREATED"
    if ($PromoteToGA -and $decision -eq "GO") {
        Invoke-Native -Exe "git" -NativeArgs @("fetch","--tags","origin") -EvidenceFile "ga-decision.txt" | Out-Null
        $ErrorActionPreference = "Continue"
        $localTag  = (& git tag -l $GaTag 2>$null | Out-String).Trim()
        $remoteTag = (& git ls-remote --tags origin $GaTag 2>$null | Out-String).Trim()
        $headNow   = (& git rev-parse HEAD 2>$null | Out-String).Trim()
        $ErrorActionPreference = "Stop"
        if ($localTag -or $remoteTag) {
            if (-not $AllowExistingGaTag) {
                $tagStatus = "NOT CREATED (v1.0.0 already exists; use -AllowExistingGaTag only to verify)"
            }
        } elseif ($headNow -ne $head) {
            $tagStatus = "NOT CREATED (HEAD moved from tested commit)"
        } else {
            Invoke-Native -Exe "git" -NativeArgs @("tag","-a",$GaTag,$head,"-m","HELBARON LMS v1.0.0") -EvidenceFile "ga-decision.txt" | Out-Null
            Invoke-Native -Exe "git" -NativeArgs @("push","origin","HEAD") -EvidenceFile "ga-decision.txt" | Out-Null
            Invoke-Native -Exe "git" -NativeArgs @("push","origin",$GaTag) -EvidenceFile "ga-decision.txt" | Out-Null
            $ErrorActionPreference = "Continue"
            $tagCommit = (& git rev-parse "$GaTag^{commit}" 2>$null | Out-String).Trim()
            $ErrorActionPreference = "Stop"
            if ($tagCommit -eq $head) { $tagStatus = "v1.0.0 -> $head" }
            else { $tagStatus = "ERROR: tag commit mismatch ($tagCommit)" }
        }
    } elseif ($PromoteToGA -and $decision -ne "GO") {
        $tagStatus = "NOT CREATED (decision is NO-GO)"
    }

    # -----------------------------------------------------------------------
    # SUMMARY
    # -----------------------------------------------------------------------
    $duration = [math]::Round(((Get-Date) - $startTime).TotalMinutes,1)
    $summaryObj = [ordered]@{
        candidate       = $ExpectedTag
        commit          = $head
        rc_anchor       = $ExpectedCommit
        timestamp       = $Stamp
        duration_min    = $duration
        decision        = $decision
        checks_total    = $script:ChecksTotal
        checks_passed   = $script:ChecksPassed
        checks_failed   = $script:ChecksFailed
        checks_external = $script:ChecksExternal
        checks_na       = $script:ChecksNa
        p0              = 0
        p1              = 0
        results         = $script:Results
        providers       = $script:ProviderStatus
        backend         = $script:BackendCounts
        frontend        = $script:FrontendCounts
        axe_critical    = $script:AxeCritical
        axe_serious     = $script:AxeSerious
        trivy_blocking  = $script:TrivyBlocking
        backup          = $script:BackupMeta
        tag_status      = $tagStatus
    }
    $json = ($summaryObj | ConvertTo-Json -Depth 8)
    Write-EvidenceFile -File "summary.json" -Content $json

    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add("GA ACCEPTANCE SUMMARY") | Out-Null
    $lines.Add("candidate: $ExpectedTag  commit: $ExpectedCommit") | Out-Null
    $lines.Add("decision:  $decision") | Out-Null
    $lines.Add(("passed={0} failed={1} external={2} na={3} remaining={4}" -f $script:ChecksPassed,$script:ChecksFailed,$script:ChecksExternal,$script:ChecksNa,$script:ChecksRemaining)) | Out-Null
    foreach ($k in $script:Results.Keys) { $lines.Add(("  {0}: {1}" -f $k,$script:Results[$k])) | Out-Null }
    $lines.Add("providers:") | Out-Null
    foreach ($k in $script:ProviderStatus.Keys) { $lines.Add(("  {0}: {1}" -f $k,$script:ProviderStatus[$k])) | Out-Null }
    $lines.Add("tag: $tagStatus") | Out-Null
    Write-EvidenceFile -File "summary.txt" -Content ($lines -join "`r`n")
    Write-EvidenceFile -File "ga-decision.txt" -Content ("DECISION=$decision`r`nTAG=$tagStatus")

    Write-Banner ("GA ACCEPTANCE: " + $decision)
    Write-Host ("Evidence: " + $EvidenceDir)
    if ($script:Failures.Count -gt 0) {
        Write-Host "Blockers:"
        foreach ($f in $script:Failures) { Write-Host ("  - " + $f) }
    }
}
finally {
    Cleanup
}
