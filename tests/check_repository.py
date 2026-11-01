"""Reject tracked application/data files and unexpected Docker COPY inputs."""
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
EXACT = {
    '.gitignore', '.gitattributes', '.dockerignore', 'README.md', 'compose.yaml',
    'deploy/.env.example', 'deploy/README.md', 'deploy/init-storage.sh',
    'deploy/nginx.conf', 'deploy/worker.sh', 'deploy/check.sh',
    'deploy/check-runtime.php', 'deploy/admin-password.php',
    'docker/php/Dockerfile', 'docker/php/production.ini', 'docker/php/scheduler.cron',
    '.github/workflows/publish.yml', '.github/workflows/validate.yml',
    'tests/check_repository.py', 'tests/test_repository.py',
}


def allowed(name):
    return name in EXACT or bool(re.fullmatch(r'docs/[a-z0-9-]+\.md', name))


def validate(names):
    errors = ['Unexpected tracked file: ' + name for name in names if not allowed(name)]
    dockerfile = (ROOT / 'docker/php/Dockerfile').read_text()
    expected = [
        'COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer',
        'COPY docker/php/production.ini /usr/local/etc/php/conf.d/99-amfot.ini',
        'COPY docker/php/scheduler.cron /etc/cron.d/amfot',
    ]
    copies = [line.strip() for line in dockerfile.splitlines()
              if re.match(r'^\s*(COPY|ADD)\b', line, re.I)]
    if copies != expected:
        errors.append('Docker COPY/ADD inputs differ from runtime-only allowlist')
    return errors


if __name__ == '__main__':
    names = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
    errors = validate([name for name in names if name])
    for error in errors:
        print(error, file=sys.stderr)
    if errors:
        sys.exit(1)
    print('OK: tracked files and Docker COPY inputs are infrastructure only')
