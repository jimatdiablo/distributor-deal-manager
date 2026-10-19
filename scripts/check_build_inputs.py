"""Check non-secret runtime scope and Unix entrypoint line endings."""
from pathlib import Path

root = Path(__file__).resolve().parents[1]
for path in (root / 'docker').glob('*.sh'):
    if b'\r' in path.read_bytes():
        raise SystemExit(f'{path.name} requires LF line endings')
print('Docker shell scripts: LF')
allowed = {'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DDM_APP_PORT', 'DDM_RUN_MIGRATIONS', 'MAAT_ENABLED', 'MAAT_INTERNAL_URL', 'MAAT_SECURE_COOKIES'}
for line in (root / '.env').read_text().splitlines():
    key, _, value = line.partition('=')
    if key in allowed:
        print(f'{key}={value}')
