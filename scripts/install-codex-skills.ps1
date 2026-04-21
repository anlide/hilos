param(
    [string]$TargetRoot = $(if ($env:CODEX_HOME) { Join-Path $env:CODEX_HOME 'skills' } else { Join-Path (Join-Path $HOME '.codex') 'skills' })
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$sourceRoot = Join-Path $repoRoot 'skills'

if (-not (Test-Path -LiteralPath $sourceRoot)) {
    throw "Skill source directory not found: $sourceRoot"
}

New-Item -ItemType Directory -Force -Path $TargetRoot | Out-Null

Get-ChildItem -LiteralPath $sourceRoot -Directory -Filter 'hilos-*' | ForEach-Object {
    $target = Join-Path $TargetRoot $_.Name
    New-Item -ItemType Directory -Force -Path $target | Out-Null

    Get-ChildItem -LiteralPath $_.FullName -Force | ForEach-Object {
        Copy-Item -LiteralPath $_.FullName -Destination $target -Recurse -Force
    }

    Write-Host "Installed $($_.Name) to $target"
}

Write-Host "Codex Hilos skills installed in $TargetRoot"
