# Builds briefly.zip for uploading to Hostinger (File Manager -> public_html -> Extract).
# Never includes your database, local config, sessions or dev tools, so re-uploading
# the zip over an existing install is a safe update.
#
#   powershell -ExecutionPolicy Bypass -File tools\package.ps1

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$dist = Join-Path $root 'dist'
$zipPath = Join-Path $dist 'briefly.zip'

$include = @('index.php', '.htaccess', 'app', 'assets', 'cron', 'data/.htaccess')

New-Item -ItemType Directory -Force -Path $dist | Out-Null
if (Test-Path $zipPath) { Remove-Item $zipPath }

$files = foreach ($item in $include) {
    $path = Join-Path $root $item
    if (Test-Path $path -PathType Container) {
        Get-ChildItem $path -Recurse -File -Force
    } elseif (Test-Path $path) {
        Get-Item $path -Force
    }
}

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
try {
    foreach ($file in $files) {
        $entry = $file.FullName.Substring($root.Length + 1).Replace('\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $file.FullName, $entry, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
} finally {
    $zip.Dispose()
}

$size = [math]::Round((Get-Item $zipPath).Length / 1KB)
Write-Host "Built $zipPath ($(@($files).Count) files, $size KB)"
