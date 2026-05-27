#!/usr/bin/env pwsh
# Builds the CIHRMS Delivery Dossier .docx from chapter markdown files.
# Usage:   pwsh -File build.ps1 [-Version v0.1]

param([string]$Version = "v0.1")

$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
$chapters = Get-ChildItem "$root\chapters" -Filter "*.md" | Sort-Object Name
if ($chapters.Count -eq 0) { throw "No chapter files found in $root\chapters" }

Write-Host "Building dossier from $($chapters.Count) chapter file(s)..."

$outputPath = "$root\build\CIHRMS_Delivery_Dossier_$Version.docx"

# Resolve pandoc explicitly so the script works regardless of session PATH.
$pandocCandidates = @(
  "C:\Users\j.nadi\AppData\Local\Pandoc\pandoc.exe",
  "$env:LOCALAPPDATA\Pandoc\pandoc.exe",
  "pandoc.exe"
)
$pandoc = $null
foreach ($cand in $pandocCandidates) {
  if (Test-Path $cand) { $pandoc = $cand; break }
  if ((Get-Command $cand -ErrorAction SilentlyContinue)) { $pandoc = $cand; break }
}
if (-not $pandoc) { throw "Pandoc not found. Install with: winget install --id JohnMacFarlane.Pandoc -e" }

& $pandoc $chapters.FullName `
  --reference-doc="$root\reference.docx" `
  --toc --toc-depth=3 `
  --top-level-division=chapter `
  --resource-path="$root" `
  --metadata title="CIHRMS Delivery Dossier" `
  --metadata subtitle="Features, Standards, and Market Readiness" `
  --metadata author="CIHRMS Engineering Team" `
  --metadata date="2026-05-24" `
  -o $outputPath

if (-not (Test-Path $outputPath)) { throw "Build failed - output not created." }
$size = [math]::Round((Get-Item $outputPath).Length / 1KB, 1)
Write-Host "Built $outputPath ($size KB)"
