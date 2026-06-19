param(
    [string]$Version = ""
)

$ErrorActionPreference = "Stop"

$moduleName = "oe-module-medsov-telehealth"
$repoRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
$modulePath = Join-Path $repoRoot $moduleName
$distPath = Join-Path $repoRoot "dist"
$stageRoot = Join-Path $repoRoot ".package"
$stageModule = Join-Path $stageRoot $moduleName

function Assert-ChildPath {
    param(
        [string]$Path,
        [string]$Parent
    )

    $resolvedParent = [System.IO.Path]::GetFullPath($Parent)
    $resolvedPath = [System.IO.Path]::GetFullPath($Path)
    if (!$resolvedPath.StartsWith($resolvedParent, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to operate outside expected directory: $resolvedPath"
    }
}

if (!(Test-Path -LiteralPath $modulePath)) {
    throw "Module path not found: $modulePath"
}

if ($Version -eq "") {
    $versionFile = Join-Path $modulePath "version.php"
    $versionText = Get-Content -LiteralPath $versionFile -Raw
    $major = [regex]::Match($versionText, "\`$v_major\s*=\s*'([^']+)'").Groups[1].Value
    $minor = [regex]::Match($versionText, "\`$v_minor\s*=\s*'([^']+)'").Groups[1].Value
    $patch = [regex]::Match($versionText, "\`$v_patch\s*=\s*'([^']+)'").Groups[1].Value
    $tag = [regex]::Match($versionText, "\`$v_tag\s*=\s*'([^']*)'").Groups[1].Value
    $Version = "$major.$minor.$patch"
    if ($tag -ne "") {
        $Version = "$Version-$tag"
    }
}

New-Item -ItemType Directory -Force -Path $distPath | Out-Null

Assert-ChildPath -Path $stageRoot -Parent $repoRoot
if (Test-Path -LiteralPath $stageRoot) {
    Remove-Item -LiteralPath $stageRoot -Recurse -Force
}
New-Item -ItemType Directory -Force -Path $stageModule | Out-Null

$includePaths = @(
    "composer.json",
    "cleanup.sql",
    "info.txt",
    "moduleConfig.php",
    "openemr.bootstrap.php",
    "README.md",
    "table.sql",
    "version.php",
    "src",
    "templates",
    "scripts/validate_install.php"
)

foreach ($relativePath in $includePaths) {
    $source = Join-Path $modulePath $relativePath
    if (!(Test-Path -LiteralPath $source)) {
        throw "Required package file missing: $relativePath"
    }

    $target = Join-Path $stageModule $relativePath
    $targetParent = Split-Path -Parent $target
    New-Item -ItemType Directory -Force -Path $targetParent | Out-Null

    if ((Get-Item -LiteralPath $source).PSIsContainer) {
        Copy-Item -LiteralPath $source -Destination $target -Recurse -Force
    } else {
        Copy-Item -LiteralPath $source -Destination $target -Force
    }
}

# Docker/OpenEMR may mark bind-mounted module files read-only while enforcing
# container permissions. Clear that flag only in the disposable staging copy so
# Compress-Archive can read every file consistently on Windows.
Get-ChildItem -LiteralPath $stageModule -Recurse -Force | ForEach-Object {
    if ($_.Attributes -band [System.IO.FileAttributes]::ReadOnly) {
        $_.Attributes = $_.Attributes -band (-bnot [System.IO.FileAttributes]::ReadOnly)
    }
}

$zipPath = Join-Path $distPath "$moduleName-$Version.zip"
if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zipFile = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::CreateNew)
try {
    $zip = [System.IO.Compression.ZipArchive]::new($zipFile, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        $stageRootFull = [System.IO.Path]::GetFullPath($stageRoot).TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar
        Get-ChildItem -LiteralPath $stageModule -Recurse -File | ForEach-Object {
            $fullPath = $_.FullName
            $fullPathResolved = [System.IO.Path]::GetFullPath($fullPath)
            $relative = $fullPathResolved.Substring($stageRootFull.Length)
            $entryName = $relative.Replace([System.IO.Path]::DirectorySeparatorChar, '/').Replace('\', '/')
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $fullPath, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    } finally {
        $zip.Dispose()
    }
} finally {
    $zipFile.Dispose()
}

Assert-ChildPath -Path $stageRoot -Parent $repoRoot
Remove-Item -LiteralPath $stageRoot -Recurse -Force

$zipItem = Get-Item -LiteralPath $zipPath
Write-Host "Created package: $($zipItem.FullName)"
Write-Host "Package size: $([Math]::Round($zipItem.Length / 1KB, 2)) KB"
