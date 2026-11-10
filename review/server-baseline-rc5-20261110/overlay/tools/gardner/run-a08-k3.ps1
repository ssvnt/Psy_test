$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$repository = 'C:\dobry-zhuk-work\bribon'
$output = 'C:\private-gate\qa-real-max-function-canary-010'
$php = 'C:\dobry-zhuk-local-only\php-8.4.19\php.exe'
$caBundle = 'C:\dobry-zhuk-local-only\certs\bribon-ca-certificates.crt'
$handoff = 'C:\dobry-zhuk-local-only\drpe-final-handoff'
$input = 'C:\private-gate\sanitized-input.json'
$runner = 'scripts\run-gardner-v2-1-real-max-gate.php'
$historicalFailure = Join-Path $output 'call_A_batch_08_failure.json'
$inspection = Join-Path $output 'call_A_batch_08_k2_recovery_inspection.json'
$response = Join-Path $output 'call_A_batch_08_response.json'
$checkpoint = Join-Path $output 'call_A_batch_08_checkpoint.json'
$logicalResponse = Join-Path $output 'call_A_response.json'
$logicalCheckpoint = Join-Path $output 'call_A_checkpoint.json'
$metadataPath = Join-Path $output 'provider_metadata.json'
$startedArtifact = Join-Path $output 'call_A_batch_08_operator_attempt_02_started.json'
$completedArtifact = Join-Path $output 'call_A_batch_08_operator_attempt_02_completed.json'
$operatorFailure = Join-Path $output 'call_A_batch_08_operator_attempt_02_failure.json'
$watchdogSeconds = 960
$heartbeatSeconds = 15
$child = $null
$secureKey = $null
$plainKey = $null
$keyBstr = [IntPtr]::Zero

function Assert-DirectTcpRoute {
    param(
        [Parameter(Mandatory = $true)][string] $HostName,
        [Parameter(Mandatory = $true)][int] $Port
    )

    $addresses = @(Resolve-DnsName -Name $HostName -Type A -ErrorAction Stop |
        Where-Object { $_.IPAddress } |
        Select-Object -ExpandProperty IPAddress -Unique)
    if ($addresses.Count -eq 0) {
        throw "No IPv4 address resolved for $HostName."
    }
    foreach ($address in $addresses) {
        $route = Find-NetRoute -RemoteIPAddress $address -ErrorAction Stop
        if ([string]$route.InterfaceAlias -match '(?i)happ-tun') {
            throw "Refusing route to $HostName through happ-tun."
        }
    }
    $probe = Test-NetConnection -ComputerName $HostName -Port $Port -InformationLevel Detailed -WarningAction SilentlyContinue
    if (-not $probe.TcpTestSucceeded) {
        throw "TCP connectivity failed for ${HostName}:$Port."
    }
    if ([string]$probe.InterfaceAlias -match '(?i)happ-tun') {
        throw "Refusing TCP route to $HostName through happ-tun."
    }
    Write-Host "TCP OK: ${HostName}:$Port via $($probe.InterfaceAlias)"
}

try {
    foreach ($requiredPath in @($repository, $output, $php, $caBundle, $handoff, $input, $historicalFailure, $inspection)) {
        if (-not (Test-Path -LiteralPath $requiredPath)) {
            throw "Required path is missing: $requiredPath"
        }
    }
    if ((Test-Path -LiteralPath $response) -or (Test-Path -LiteralPath $checkpoint) -or
        (Test-Path -LiteralPath $startedArtifact) -or (Test-Path -LiteralPath $completedArtifact) -or
        (Test-Path -LiteralPath $operatorFailure)) {
        throw 'A.batch08 operator attempt 2 is already successful, incomplete, or consumed; refusing another execution.'
    }

    Assert-DirectTcpRoute -HostName 'api.giga.chat' -Port 443
    Assert-DirectTcpRoute -HostName 'ngw.devices.sberbank.ru' -Port 9443

    $historicalFailureSha256 = (Get-FileHash -LiteralPath $historicalFailure -Algorithm SHA256).Hash.ToLowerInvariant()
    $secureKey = Read-Host 'GigaChat V2 Authorization key' -AsSecureString
    $keyBstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureKey)
    $plainKey = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($keyBstr)
    if ([string]::IsNullOrWhiteSpace($plainKey)) {
        throw 'Authorization key cannot be empty.'
    }

    [Environment]::SetEnvironmentVariable('GIGACHAT_V2_AUTHORIZATION_KEY', $plainKey, 'Process')
    [Environment]::SetEnvironmentVariable('GIGACHAT_V2_CA_BUNDLE', $caBundle, 'Process')
    [Environment]::SetEnvironmentVariable('GIGACHAT_V2_CONNECT_TIMEOUT', '15', 'Process')
    [Environment]::SetEnvironmentVariable('GIGACHAT_V2_REQUEST_TIMEOUT', '300', 'Process')
    [Environment]::SetEnvironmentVariable('GIGACHAT_V2_READ_TIMEOUT', '180', 'Process')
    [Environment]::SetEnvironmentVariable('GIGACHAT_V2_OVERALL_TIMEOUT', '900', 'Process')
    [Environment]::SetEnvironmentVariable('GIGACHAT_V2_RETRY_ATTEMPTS', '1', 'Process')

    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $stdout = Join-Path 'C:\private-gate' "run-a08-k3-$stamp.stdout.log"
    $stderr = Join-Path 'C:\private-gate' "run-a08-k3-$stamp.stderr.log"
    $arguments = @(
        '-d', "curl.cainfo=$caBundle",
        '-d', "openssl.cafile=$caBundle",
        $runner,
        "--input=$input",
        "--output=$output",
        "--handoff=$handoff",
        '--transport=function-batch',
        '--resume',
        '--execute',
        '--operator-reexecute-batch=A.batch08'
    )
    $startedAt = Get-Date
    $deadline = $startedAt.AddSeconds($watchdogSeconds)
    $child = Start-Process -FilePath $php -ArgumentList $arguments -WorkingDirectory $repository `
        -RedirectStandardOutput $stdout -RedirectStandardError $stderr -WindowStyle Hidden -PassThru

    # Start-Process has created the child and copied its process environment; clear the parent immediately.
    [Environment]::SetEnvironmentVariable('GIGACHAT_V2_AUTHORIZATION_KEY', $null, 'Process')
    $plainKey = $null
    if ($keyBstr -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($keyBstr)
        $keyBstr = [IntPtr]::Zero
    }
    $secureKey = $null

    while (-not $child.HasExited) {
        $remainingMilliseconds = [int][Math]::Floor(($deadline - (Get-Date)).TotalMilliseconds)
        if ($remainingMilliseconds -le 0) {
            Stop-Process -Id $child.Id -Force -ErrorAction SilentlyContinue
            throw "A.batch08 external watchdog expired after $watchdogSeconds seconds."
        }
        $waitMilliseconds = [Math]::Min($heartbeatSeconds * 1000, $remainingMilliseconds)
        if ($child.WaitForExit($waitMilliseconds)) {
            break
        }
        $elapsed = [int]((Get-Date) - $startedAt).TotalSeconds
        Write-Host "A.batch08 operator attempt 2 running: $elapsed seconds"
    }
    $child.Refresh()
    if ($child.ExitCode -ne 0) {
        throw "A.batch08 operator process failed with exit code $($child.ExitCode). See $stderr"
    }

    foreach ($requiredSuccess in @($response, $checkpoint, $logicalResponse, $logicalCheckpoint, $metadataPath, $completedArtifact)) {
        if (-not (Test-Path -LiteralPath $requiredSuccess -PathType Leaf)) {
            throw "Validated success artifact is missing: $requiredSuccess"
        }
    }
    if ((Get-FileHash -LiteralPath $historicalFailure -Algorithm SHA256).Hash.ToLowerInvariant() -ne $historicalFailureSha256) {
        throw 'Historical A.batch08 failure evidence was modified.'
    }
    $metadata = Get-Content -LiteralPath $metadataPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $state = $metadata.batches.'A.batch08'
    if ($state -ne 'EXECUTED_AFTER_OPERATOR_REEXECUTION') {
        throw "A.batch08 metadata state is not validated: $state"
    }
    if ($metadata.operator_reexecution -ne $true -or $metadata.operator_reexecution_batch -ne 'A.batch08' -or
        $metadata.max_new_provider_calls -ne 1 -or $metadata.batch_attempt_accounting.'A.batch08'.physical_attempt_count -ne 2) {
        throw 'A.batch08 operator metadata provenance is incomplete.'
    }
    foreach ($index in 1..7) {
        $priorBatch = 'A.batch{0:d2}' -f $index
        if ($metadata.batches.$priorBatch -ne 'REUSED_VALIDATED') {
            throw "$priorBatch was not reused unchanged."
        }
    }
    foreach ($property in $metadata.batches.PSObject.Properties) {
        if ($property.Name -match '^[BCD]\.batch' -and $property.Value -ne 'PENDING') {
            throw "Forbidden non-A execution detected: $($property.Name)=$($property.Value)"
        }
    }
    $checkpointValue = Get-Content -LiteralPath $checkpoint -Raw -Encoding UTF8 | ConvertFrom-Json
    $provenance = $checkpointValue.operator_reexecution_provenance
    if ($provenance.historical_provider_attempt -ne 1 -or $provenance.new_provider_attempt -ne 2 -or
        $provenance.operator_authorized -ne $true -or $provenance.request_identity_preserved -ne $true -or
        $provenance.K2_terminal_aware_reader -ne $true -or $provenance.historical_failure_preserved -ne $true -or
        $provenance.historical_request_sha256 -ne $provenance.outgoing_request_sha256) {
        throw 'A.batch08 checkpoint provenance is not validated.'
    }

    Write-Host "A.batch08 final status: $state"
    Write-Host "Validated logical Call A is complete. Output log: $stdout"
}
finally {
    [Environment]::SetEnvironmentVariable('GIGACHAT_V2_AUTHORIZATION_KEY', $null, 'Process')
    if ($null -ne $child -and -not $child.HasExited) {
        Stop-Process -Id $child.Id -Force -ErrorAction SilentlyContinue
    }
    if ($keyBstr -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($keyBstr)
        $keyBstr = [IntPtr]::Zero
    }
    $plainKey = $null
    $secureKey = $null
    Write-Host 'GigaChat Authorization key cleared from parent process.'
}
