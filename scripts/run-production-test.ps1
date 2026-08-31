[CmdletBinding()]
param(
    [string]$EnvFile
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Import-PritsetEnvironmentFile {
    param([Parameter(Mandatory)][string]$Path)

    $allowedNames = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
    @(
        "PRITSET_BASE_URL",
        "PRITSET_ACCESS_TOKEN",
        "PRITSET_SECRET",
        "PRITSET_WEBHOOK_URL",
        "PRITSET_WEBHOOK_SETTLE_SECONDS",
        "PRITSET_ALLOW_PRODUCTION",
        "PRITSET_PRODUCTION_TEST_USER_CONFIRMED",
        "PRITSET_TEST_RUN_PREFIX",
        "PRITSET_TEMPLATE_PATH",
        "PRITSET_CA_BUNDLE_PATH"
    ) | ForEach-Object { [void]$allowedNames.Add($_) }

    $values = @{}
    $lineNumber = 0
    foreach ($rawLine in [IO.File]::ReadAllLines($Path)) {
        $lineNumber++
        $line = $rawLine.Trim()
        if ([string]::IsNullOrWhiteSpace($line) -or $line.StartsWith("#")) {
            continue
        }
        if ($line.StartsWith("export ", [StringComparison]::Ordinal)) {
            $line = $line.Substring(7).TrimStart()
        }

        $separator = $line.IndexOf("=")
        if ($separator -le 0) {
            throw "Invalid .env entry at line $lineNumber. Expected NAME=value."
        }

        $name = $line.Substring(0, $separator).Trim()
        if (-not $allowedNames.Contains($name)) {
            throw "Unsupported .env setting '$name' at line $lineNumber."
        }
        if ($values.ContainsKey($name)) {
            throw "Duplicate .env setting '$name' at line $lineNumber."
        }

        $value = $line.Substring($separator + 1).Trim()
        if ($value.Length -ge 2 -and
            (($value.StartsWith('"') -and $value.EndsWith('"')) -or
             ($value.StartsWith("'") -and $value.EndsWith("'")))) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        elseif ($value.StartsWith('"') -or $value.EndsWith('"') -or
                $value.StartsWith("'") -or $value.EndsWith("'")) {
            throw "Unmatched quote in .env setting '$name' at line $lineNumber."
        }

        $values[$name] = $value
    }

    return $values
}

function Get-RequiredEnvironmentValue {
    param(
        [Parameter(Mandatory)][hashtable]$Values,
        [Parameter(Mandatory)][string]$Name
    )

    if (-not $Values.ContainsKey($Name) -or
        [string]::IsNullOrWhiteSpace([string]$Values[$Name]) -or
        $Values[$Name] -eq "replace-me") {
        throw "Set $Name in the .env file before running the production test."
    }

    return [string]$Values[$Name]
}

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$defaultFixturePath = Join-Path $repositoryRoot "tests/fixtures/staging-template.docx"

if ([string]::IsNullOrWhiteSpace($EnvFile)) {
    $EnvFile = Join-Path $repositoryRoot ".env"
}
elseif (-not [IO.Path]::IsPathRooted($EnvFile)) {
    $EnvFile = Join-Path (Get-Location) $EnvFile
}

if (-not (Test-Path -LiteralPath $EnvFile -PathType Leaf)) {
    throw ".env file was not found at $EnvFile. Copy .env.example to .env and fill in the production test-user settings."
}
$resolvedEnvFile = (Resolve-Path -LiteralPath $EnvFile).Path

$environmentValues = Import-PritsetEnvironmentFile -Path $resolvedEnvFile
$baseUrl = Get-RequiredEnvironmentValue -Values $environmentValues -Name "PRITSET_BASE_URL"
$accessToken = Get-RequiredEnvironmentValue -Values $environmentValues -Name "PRITSET_ACCESS_TOKEN"
$secret = Get-RequiredEnvironmentValue -Values $environmentValues -Name "PRITSET_SECRET"
$webhookUrl = Get-RequiredEnvironmentValue -Values $environmentValues -Name "PRITSET_WEBHOOK_URL"
$allowProduction = Get-RequiredEnvironmentValue -Values $environmentValues -Name "PRITSET_ALLOW_PRODUCTION"
$testUserConfirmed = Get-RequiredEnvironmentValue -Values $environmentValues -Name "PRITSET_PRODUCTION_TEST_USER_CONFIRMED"
$fixturePath = $defaultFixturePath
if ($environmentValues.ContainsKey("PRITSET_TEMPLATE_PATH") -and
    -not [string]::IsNullOrWhiteSpace([string]$environmentValues["PRITSET_TEMPLATE_PATH"])) {
    $fixturePath = [string]$environmentValues["PRITSET_TEMPLATE_PATH"]
    if (-not [IO.Path]::IsPathRooted($fixturePath)) {
        $fixturePath = Join-Path $repositoryRoot $fixturePath
    }
}

if ($baseUrl -cne "https://api.pritset.com") {
    throw "PRITSET_BASE_URL must be exactly https://api.pritset.com for this launcher."
}
if ($allowProduction -cne "true") {
    throw "Set PRITSET_ALLOW_PRODUCTION=true in the .env file to allow this production run."
}
if ($testUserConfirmed -cne "true") {
    throw "Set PRITSET_PRODUCTION_TEST_USER_CONFIRMED=true in the .env file after confirming these credentials belong to the dedicated production test user."
}
if ($accessToken.StartsWith("Bearer ", [StringComparison]::OrdinalIgnoreCase)) {
    throw "PRITSET_ACCESS_TOKEN must be the raw Pritset access token without a Bearer prefix."
}
if ($accessToken -match "\s" -or $secret -match "\s") {
    throw "PRITSET_ACCESS_TOKEN and PRITSET_SECRET cannot contain whitespace. Check for accidental spaces or line breaks."
}

if ($environmentValues.ContainsKey("PRITSET_WEBHOOK_SETTLE_SECONDS")) {
    [int]$settleSeconds = 0
    if (-not [int]::TryParse([string]$environmentValues["PRITSET_WEBHOOK_SETTLE_SECONDS"], [ref]$settleSeconds) -or
        $settleSeconds -lt 0 -or $settleSeconds -gt 60) {
        throw "PRITSET_WEBHOOK_SETTLE_SECONDS must be a whole number between 0 and 60."
    }
}

if (-not (Test-Path -LiteralPath $fixturePath -PathType Leaf)) {
    throw "Production test fixture was not found at $fixturePath."
}
$resolvedFixturePath = (Resolve-Path -LiteralPath $fixturePath).Path
if ([IO.Path]::GetExtension($resolvedFixturePath) -ine ".docx") {
    throw "PRITSET_TEMPLATE_PATH must point to a .docx file."
}
$fixtureInfo = Get-Item -LiteralPath $resolvedFixturePath
if ($fixtureInfo.Length -le 0 -or $fixtureInfo.Length -gt 5120000) {
    throw "Production test fixture must be between 1 byte and 5 MB."
}
$fixtureBytes = [IO.File]::ReadAllBytes($resolvedFixturePath)
if ($fixtureBytes.Length -lt 4 -or $fixtureBytes[0] -ne 0x50 -or $fixtureBytes[1] -ne 0x4B) {
    throw "Production test fixture is not a DOCX ZIP archive."
}
$hasZipEndMarker = $false
for ($index = [Math]::Max(0, $fixtureBytes.Length - 65557); $index -le $fixtureBytes.Length - 4; $index++) {
    if ($fixtureBytes[$index] -eq 0x50 -and $fixtureBytes[$index + 1] -eq 0x4B -and
        $fixtureBytes[$index + 2] -eq 0x05 -and $fixtureBytes[$index + 3] -eq 0x06) {
        $hasZipEndMarker = $true
        break
    }
}
if (-not $hasZipEndMarker) {
    throw "Production test fixture has no ZIP end marker."
}
$fixtureBytes = $null

$php = Get-Command php -ErrorAction SilentlyContinue
if ($null -eq $php) {
    $php = Get-Command php -ErrorAction SilentlyContinue
}
if ($null -eq $php) {
    throw "PHP was not found. Install PHP 8.3 or newer and add it to PATH before running this script."
}
$phpExecutable = $php.Source

if (-not (Test-Path -LiteralPath (Join-Path $repositoryRoot "vendor/autoload.php") -PathType Leaf)) {
    throw "SDK dependencies are missing. Run composer install in $repositoryRoot before the production test."
}

$caBundleCandidates = [Collections.Generic.List[string]]::new()
if ($environmentValues.ContainsKey("PRITSET_CA_BUNDLE_PATH") -and
    -not [string]::IsNullOrWhiteSpace([string]$environmentValues["PRITSET_CA_BUNDLE_PATH"])) {
    $customCaBundle = [string]$environmentValues["PRITSET_CA_BUNDLE_PATH"]
    if (-not [IO.Path]::IsPathRooted($customCaBundle)) {
        $customCaBundle = Join-Path $repositoryRoot $customCaBundle
    }
    if (-not (Test-Path -LiteralPath $customCaBundle -PathType Leaf)) {
        throw "PRITSET_CA_BUNDLE_PATH was not found at $customCaBundle."
    }
    $caBundleCandidates.Add((Resolve-Path -LiteralPath $customCaBundle).Path)
}
else {
    $caProbe = & $phpExecutable -r '$locations = openssl_get_cert_locations(); echo json_encode(["curl" => ini_get("curl.cainfo"), "openssl" => ini_get("openssl.cafile"), "default" => $locations["default_cert_file"] ?? ""]);'
    if ($LASTEXITCODE -ne 0) {
        throw "PHP CA certificate configuration could not be inspected."
    }
    try {
        $phpCaConfiguration = $caProbe | ConvertFrom-Json -ErrorAction Stop
    }
    catch {
        throw "PHP returned an unexpected result while inspecting CA certificate configuration."
    }

    @($phpCaConfiguration.curl, $phpCaConfiguration.openssl, $phpCaConfiguration.default) |
        Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) } |
        ForEach-Object { $caBundleCandidates.Add([string]$_) }

    @(
        "C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt",
        "C:\Program Files\Git\usr\ssl\certs\ca-bundle.crt"
    ) | ForEach-Object { $caBundleCandidates.Add($_) }
}

$caBundlePath = $null
foreach ($candidate in $caBundleCandidates) {
    if (Test-Path -LiteralPath $candidate -PathType Leaf) {
        $candidateInfo = Get-Item -LiteralPath $candidate
        if ($candidateInfo.Length -gt 0) {
            $caBundlePath = $candidateInfo.FullName
            break
        }
    }
}
if ($null -eq $caBundlePath) {
    throw "PHP has no readable CA certificate bundle. Set PRITSET_CA_BUNDLE_PATH in .env to a trusted PEM bundle."
}

[Uri]$parsedWebhookUrl = $null
if (-not [Uri]::TryCreate($webhookUrl, [UriKind]::Absolute, [ref]$parsedWebhookUrl) -or
    $parsedWebhookUrl.Scheme -cne "https" -or
    -not [string]::IsNullOrEmpty($parsedWebhookUrl.UserInfo)) {
    throw "PRITSET_WEBHOOK_URL must be an absolute HTTPS URL without embedded credentials."
}

Write-Host "Loaded production test settings from $resolvedEnvFile. Secret values will not be printed."
Write-Host "Using CA certificate bundle $caBundlePath."
Write-Warning "This test targets https://api.pritset.com using a dedicated production test user."
Write-Warning "It creates and deletes a temporary template, generates PDFs, submits a webhook job, and may consume test-user credit."

$confirmation = Read-Host "Type RUN-PRODUCTION-TEST to continue"
if ($confirmation -cne "RUN-PRODUCTION-TEST") {
    throw "Production test was not explicitly confirmed."
}

$locationChanged = $false
$previousEnvironment = @{}
foreach ($name in $environmentValues.Keys) {
    $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, "Process")
}

try {
    foreach ($name in $environmentValues.Keys) {
        [Environment]::SetEnvironmentVariable($name, [string]$environmentValues[$name], "Process")
    }

    Push-Location -LiteralPath $repositoryRoot
    $locationChanged = $true

    & $phpExecutable -d "curl.cainfo=$caBundlePath" -d "openssl.cafile=$caBundlePath" scripts/production-lifecycle.php
    if ($LASTEXITCODE -ne 0) {
        throw "Production lifecycle failed with exit code $LASTEXITCODE. Review cleanup output before retrying."
    }

    Write-Host "Production test-user lifecycle completed successfully."
}
finally {
    if ($locationChanged) {
        Pop-Location
    }

    foreach ($name in $environmentValues.Keys) {
        $previousValue = $previousEnvironment[$name]
        if ($null -eq $previousValue) {
            Remove-Item -Path "Env:$name" -ErrorAction SilentlyContinue
        }
        else {
            Set-Item -Path "Env:$name" -Value $previousValue
        }
    }

    $accessToken = $null
    $secret = $null
    $webhookUrl = $null
    $environmentValues.Clear()
    $previousEnvironment.Clear()
}
