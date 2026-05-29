param(
    [int]$Port = 8080
)

$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $projectRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Host "Docker is not available in PATH." -ForegroundColor Red
    Write-Host "Install Docker Desktop to run this project." -ForegroundColor Yellow
    exit 1
}

$image = "diablo-php-cli"
$projectRootUnix = $projectRoot -replace '\\', '/'
$workspaceRoot = Split-Path -Parent $projectRoot
$dockerfileCandidates = @(
    (Join-Path $workspaceRoot 'Dockerfile.php'),
    (Join-Path $workspaceRoot 'projects\Legacy-BOS-Gunslinger-Onboarding\docker\Dockerfile.php'),
    (Join-Path $workspaceRoot '..\Legacy-BOS-Gunslinger-Onboarding\docker\Dockerfile.php')
)
$dockerfilePath = $dockerfileCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1

if (-not $dockerfilePath) {
    Write-Host "Could not find Dockerfile.php for image $image." -ForegroundColor Red
    exit 1
}

docker image inspect $image *> $null
if ($LASTEXITCODE -ne 0) {
    Write-Host "Docker image $image not found. Building from $dockerfilePath..." -ForegroundColor Yellow
    $dockerContext = Split-Path -Parent $dockerfilePath
    docker build -f "$dockerfilePath" -t $image "$dockerContext"
    if ($LASTEXITCODE -ne 0) {
        Write-Host "Failed to build Docker image $image." -ForegroundColor Red
        exit 1
    }
}

Write-Host "Starting Distributor and Deal Manager in Docker on http://127.0.0.1:$Port" -ForegroundColor Green
docker run --rm -p "$Port`:$Port" -v "${projectRootUnix}:/app" -w /app $image php -S "0.0.0.0:$Port" -t public
