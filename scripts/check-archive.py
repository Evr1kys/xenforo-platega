#!/usr/bin/env python3
"""Validate the installable archive produced by scripts/build.py."""
import hashlib
import json
from pathlib import Path, PurePosixPath
import stat
import zipfile

ROOT = Path(__file__).resolve().parents[1]
ADDON = ROOT / 'upload/src/addons/Evrik/Platega'
META = json.loads((ADDON / 'addon.json').read_text(encoding='utf-8'))
ARCHIVE = ROOT / 'dist' / f"Evrik-Platega-{META['version_id']}.zip"
CHECKSUM = ARCHIVE.with_name(ARCHIVE.name + '.sha256')

assert ARCHIVE.is_file(), f'Missing archive: {ARCHIVE}'
assert CHECKSUM.is_file(), f'Missing checksum: {CHECKSUM}'

archive_digest = hashlib.sha256(ARCHIVE.read_bytes()).hexdigest()
expected_checksum = f'{archive_digest}  {ARCHIVE.name}\n'
assert CHECKSUM.read_text(encoding='utf-8') == expected_checksum, 'Archive SHA-256 file does not match'

with zipfile.ZipFile(ARCHIVE) as source:
    infos = source.infolist()
    names = [info.filename for info in infos]
    assert len(names) == len(set(names)), 'Duplicate archive entry'
    for info in infos:
        path = PurePosixPath(info.filename)
        assert not path.is_absolute(), f'Absolute archive path: {info.filename}'
        assert '..' not in path.parts and '\\' not in info.filename, f'Unsafe archive path: {info.filename}'
        mode = (info.external_attr >> 16) & 0o170000
        assert mode != stat.S_IFLNK, f'Symlink in archive: {info.filename}'
        assert info.date_time == (2026, 1, 1, 0, 0, 0), f'Non-reproducible timestamp: {info.filename}'

    addon_path = 'upload/src/addons/Evrik/Platega/addon.json'
    hashes_path = 'upload/src/addons/Evrik/Platega/hashes.json'
    assert addon_path in names and hashes_path in names and 'LICENSE' in names, 'Required archive entries missing'
    archived_meta = json.loads(source.read(addon_path))
    assert archived_meta['version_id'] == META['version_id'], 'Archive contains a different add-on version'

    hashes = json.loads(source.read(hashes_path))
    archived_upload_files = {
        name[len('upload/'):]
        for name in names
        if name.startswith('upload/') and name != hashes_path
    }
    assert set(hashes) == archived_upload_files, 'hashes.json does not cover exactly the upload files'
    for relative_path, expected in hashes.items():
        content = source.read('upload/' + relative_path)
        actual = hashlib.sha256(content.replace(b'\r', b'')).hexdigest()
        assert actual == expected, f'Hash mismatch: {relative_path}'

print(f'OK: {ARCHIVE.name} ({len(infos)} files, SHA-256 {archive_digest})')
