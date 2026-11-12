# Build Shop.co on this PC into two zip files to upload to shared hosting (deploy/cpanel/README.md):
#   shopco-web.zip  the storefront, ready for cPanel's "Setup Node.js App" (no npm install on the host)
#   shopco-api.zip  the PHP API (no tests, settings, logs or backups)
#
#   powershell -ExecutionPolicy Bypass -File deploy/cpanel/build.ps1 -ApiUrl https://api.yourshop.example
#
# Only committed code is built, in a fresh temporary folder, so your running shop is not touched.
# -ApiUrl is the API's public address: it is written into the storefront build, so a new address
# needs a new build. Put the API online first, or pages start empty until they refresh (a minute).
# The picture library (sharp) is added for Linux, because a Windows build carries only Windows files.
param(
    [Parameter(Mandatory = $true)][string]$ApiUrl,
    [string]$OutDir = "$env:USERPROFILE\Desktop\shopco-cpanel",
    [ValidateSet('x64', 'arm64')][string]$Cpu = 'x64',
    [switch]$AllowHttp,
    # For a host whose Linux is too old for the picture library (check-host.php says so), or that
    # runs out of processes resizing pictures: pictures are sent at their full size instead.
    [switch]$NoImageResizing
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
Set-Location $repo

$ApiUrl = $ApiUrl.TrimEnd('/')
if (-not $AllowHttp -and $ApiUrl -notmatch '^https://[^/\s]+$') { throw '-ApiUrl must look like https://api.yourshop.example (https, no path).' }
if ($ApiUrl -notmatch '^https?://[^/\s]+$') { throw '-ApiUrl must be an address with no path.' }

# Windows' own tar, which also writes zip files. Git Bash's tar, if first on PATH, can't read C: paths.
$tar = Join-Path $env:SystemRoot 'System32\tar.exe'
function Step($text) { Write-Host "==> $text" }
function Run($file, [string[]]$arguments) {
    & $file @arguments
    if ($LASTEXITCODE -ne 0) { throw "$file $($arguments -join ' ') failed" }
}

if (git status --porcelain --untracked-files=no) { Write-Warning 'You have changes that are not committed. Only committed code is built.' }
$commit = (git rev-parse --short HEAD).Trim()

$work = Join-Path $env:TEMP 'shopco-cpanel-build'
if (Test-Path $work) { cmd /c rmdir /s /q "$work" | Out-Null }
$src = Join-Path $work 'src'
New-Item -ItemType Directory -Force $src | Out-Null

Step "Exporting commit $commit"
$archive = Join-Path $work 'code.tar'
Run git @('-c', 'core.autocrlf=false', '-c', 'core.eol=lf', 'archive', '--format=tar', '-o', $archive, 'HEAD')
Run $tar @('-xf', $archive, '-C', $src)

Set-Location $src
Step 'Installing packages (a few minutes the first time)'
Run npm @('ci', '--no-audit', '--no-fund')

Step "Building the storefront for $ApiUrl"
$env:SHOPCO_STANDALONE = 'true'
$env:BACKEND_ORIGIN = $ApiUrl
$env:NEXT_TELEMETRY_DISABLED = '1'
if ($NoImageResizing) { $env:SHOPCO_NO_IMAGE_RESIZING = 'true' }
Run npx @('next', 'build')
Remove-Item Env:SHOPCO_STANDALONE
Remove-Item Env:SHOPCO_NO_IMAGE_RESIZING -ErrorAction SilentlyContinue

$standalone = Join-Path $src '.next\standalone'
if (-not (Test-Path (Join-Path $standalone 'server.js'))) { throw 'The build did not produce a standalone server.' }

Step 'Assembling the storefront'
$web = Join-Path $work 'web'
# /SL would keep links; without it robocopy follows them, so links and junctions become real files
# that survive the move to another machine.
robocopy $standalone $web /E /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'Copying the standalone build failed' }
robocopy (Join-Path $src '.next\static') (Join-Path $web '.next\static') /E /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'Copying the static files failed' }
robocopy (Join-Path $src 'public') (Join-Path $web 'public') /E /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'Copying the public files failed' }

Step "Adding the picture library for Linux ($Cpu)"
$sharpVersion = (Get-Content (Join-Path $src 'node_modules\sharp\package.json') -Raw | ConvertFrom-Json).version
$linux = Join-Path $work 'linux'
New-Item -ItemType Directory -Force $linux | Out-Null
Set-Location $linux
'{"name":"shopco-linux-sharp","private":true}' | Out-File -Encoding ascii package.json
Run npm @('install', '--no-audit', '--no-fund', "--os=linux", "--cpu=$Cpu", '--libc=glibc', "sharp@$sharpVersion")
$linuxImg = Join-Path $linux 'node_modules\@img'
$found = Get-ChildItem $linuxImg -Directory | Where-Object { $_.Name -like "*linux-$Cpu*" }
if (-not $found) { throw "The Linux $Cpu picture library was not downloaded." }
# Everywhere the build put sharp's own files, so whichever copy is loaded finds them.
$targets = @(Get-ChildItem $web -Recurse -Directory -Filter '@img' -Force | Select-Object -ExpandProperty FullName)
if ($targets.Count -eq 0) { $targets = @(Join-Path $web 'node_modules\@img') }
foreach ($target in $targets) {
    foreach ($item in $found) { Copy-Item $item.FullName (Join-Path $target $item.Name) -Recurse -Force }
}

# Anything still a link would be broken on the host.
$links = @(Get-ChildItem $web -Recurse -Force | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint })
if ($links.Count -gt 0) { throw "The build still has $($links.Count) links, which would break on the host: $($links[0].FullName)" }

Step 'Assembling the API'
$api = Join-Path $work 'api'
robocopy (Join-Path $src 'backend') $api /E /NFL /NDL /NJH /NJS /NP /XD tests | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'Copying the API failed' }
foreach ($folder in 'logs', 'cache', 'backups', 'framework') {
    New-Item -ItemType Directory -Force (Join-Path $api "storage\$folder") | Out-Null
}
Copy-Item (Join-Path $src 'deploy\cpanel\env.cpanel.example') (Join-Path $api 'env.cpanel.example')
# Used only where the API's address can't point at shopco-api/public (README step 2).
robocopy (Join-Path $src 'deploy\cpanel\api-entry') (Join-Path $api 'api-entry') /E /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'Copying the API entry folder failed' }

Step 'Packing'
New-Item -ItemType Directory -Force $OutDir | Out-Null
$webZip = Join-Path $OutDir 'shopco-web.zip'
$apiZip = Join-Path $OutDir 'shopco-api.zip'
foreach ($zip in $webZip, $apiZip) { if (Test-Path $zip) { Remove-Item $zip -Force } }
$pack = Join-Path $PSScriptRoot 'pack.mjs'
Run node @($pack, $web, $webZip)
Run node @($pack, $api, $apiZip)
Copy-Item (Join-Path $src 'deploy\cpanel\check-host.php') (Join-Path $OutDir 'check-host.php') -Force
Set-Location $repo
cmd /c rmdir /s /q "$work" | Out-Null

Write-Host ''
Write-Host "Built commit $commit for $ApiUrl"
Get-ChildItem $webZip, $apiZip | ForEach-Object { Write-Host ("  {0}  {1:N1} MB" -f $_.FullName, ($_.Length / 1MB)) }
Write-Host 'Next: deploy/cpanel/README.md'
