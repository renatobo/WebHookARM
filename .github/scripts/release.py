"""Release eligibility, metadata and artifact guards (standard library only)."""
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys
import zipfile


def eligible_pr(pr):
    if not pr.get('merged') or pr.get('base', {}).get('ref') != 'main':
        raise ValueError('Release requires a PR merged into main')
    if 'release' not in [label['name'] for label in pr.get('labels', [])]:
        raise ValueError('Merged PR must retain the release label')
    sha = pr.get('merge_commit_sha', '')
    if not re.fullmatch(r'[0-9a-f]{40}', sha):
        raise ValueError('Missing valid merge commit')
    return sha


def plugin_version(text, config):
    header = re.search(r'^\s*(?:\*\s*)?Version:\s*(\S+)', text, re.M)
    constant = re.search(r"define\(\s*['\"]" + re.escape(config['constant']) + r"['\"]\s*,\s*['\"]([^'\"]+)['\"]", text)
    if not header or not constant or header[1] != constant[1]:
        raise ValueError('Plugin header and version constant must match')
    version = header[1]
    if not re.fullmatch(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)', version):
        raise ValueError('Version must be X.Y.Z without leading zeroes')
    return version


def version(root, config):
    value = plugin_version((root / config['main']).read_text(), config)
    if config['readme']:
        text = (root / 'readme.txt').read_text()
        stable = re.search(r'^Stable tag:\s*(\S+)', text, re.M)
        readme_version = re.search(r'^Version:\s*(\S+)', text, re.M)
        if not stable or stable[1] != value or (readme_version and readme_version[1] != value):
            raise ValueError('readme.txt version metadata must match')
    for filename, keys in config.get('specs', []):
        document = json.loads((root / filename).read_text())
        for key in keys:
            document = document[key]
        if document != value:
            raise ValueError(f'{filename} {keys} must match plugin version')
    return value


def metadata(root, config):
    value = version(root, config)
    text = (root / 'release-notes' / (value + '.md')).read_text()
    for heading in ['## New Features', '## Improvements', '## Bug Fixes']:
        if heading not in text.splitlines():
            raise ValueError('Release notes missing ' + heading)
    return value


def validate_zip(path, value, config):
    forbidden = {'vendor', 'tests', 'node_modules', 'scripts', 'release-notes', 'temp'}
    forbidden_files = {'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'AGENTS.md', 'CLAUDE.md', 'LOCAL-DEV.md', 'build.sh', 'release.sh', 'phpunit.xml', 'phpunit.xml.dist', 'phpcs.xml.dist', 'phpstan.neon.dist', 'psalm.xml'}
    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()
        if not names or len(names) != len(set(names)) or archive.testzip() is not None:
            raise ValueError('Empty, duplicate-member or corrupt ZIP')
        for info in archive.infolist():
            name = PurePosixPath(info.filename)
            if name.is_absolute() or '..' in name.parts or '\\' in info.filename or name.parts[0] != config['slug']:
                raise ValueError('Unsafe archive path: ' + info.filename)
            if any(part.startswith('.') or part in forbidden for part in name.parts) or name.name in forbidden_files:
                raise ValueError('Development file in archive: ' + info.filename)
            if stat.S_ISLNK(info.external_attr >> 16):
                raise ValueError('Symlink in archive: ' + info.filename)
        for required in [config['main'], 'uninstall.php'] + (['readme.txt'] if config['readme'] else []):
            if config['slug'] + '/' + required not in names:
                raise ValueError('Missing runtime file: ' + required)
        packaged = archive.read(config['slug'] + '/' + config['main']).decode()
        if plugin_version(packaged, config) != value:
            raise ValueError('ZIP bootstrap version differs from source')


def output(key, value):
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a') as stream:
            stream.write(f'{key}={value}\n')
    print(f'{key}={value}')


if __name__ == '__main__':
    root = Path.cwd()
    config = json.loads((root / '.github/release.json').read_text())
    command = sys.argv[1]
    if command == 'resolve':
        number = os.environ['PR_NUMBER']
        if not re.fullmatch(r'[1-9][0-9]*', number):
            raise ValueError('PR number must be positive')
        pr = json.loads(subprocess.check_output(['gh', 'api', f"repos/{os.environ['GH_REPO']}/pulls/{number}"], text=True))
        output('sha', eligible_pr(pr))
    elif command in ['metadata', 'version']:
        value = metadata(root, config) if command == 'metadata' else version(root, config)
        output('version', value)
        output('zip', 'dist/' + config['zip'].format(version=value))
    elif command in ['package', 'verify']:
        value = version(root, config)
        filename = config['zip'].format(version=value)
        path = root / 'dist' / filename
        validate_zip(path, value, config)
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        if command == 'package':
            sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
            (root / 'dist/release-manifest.json').write_text(json.dumps(dict(commit=sha, version=value, filename=filename, sha256=digest)) + '\n')
            (root / 'dist' / (filename + '.sha256')).write_text(f'{digest}  {filename}\n')
        else:
            manifest = json.loads((root / 'dist/release-manifest.json').read_text())
            expected = dict(commit=os.environ['RELEASE_SHA'], version=value, filename=filename, sha256=digest)
            if manifest != expected:
                raise ValueError('Downloaded artifact does not match release commit/version/digest')
            if (root / 'dist' / (filename + '.sha256')).read_text() != f'{digest}  {filename}\n':
                raise ValueError('Checksum sidecar mismatch')
        output('zip', 'dist/' + filename)
    else:
        raise ValueError('Unknown command')
