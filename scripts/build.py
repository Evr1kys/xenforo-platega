#!/usr/bin/env python3
"""Build a XenForo installable ZIP from the public upload tree."""
import hashlib
import json
from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parents[1]
UPLOAD = ROOT / 'upload'
ADDON = UPLOAD / 'src/addons/Evrik/Platega'
meta = json.loads((ADDON / 'addon.json').read_text())
files = sorted(p for p in UPLOAD.rglob('*') if p.is_file() and p.name != 'hashes.json')
assert all(not any(part.startswith('.') for part in p.relative_to(UPLOAD).parts) for p in files)
hashes = {p.relative_to(UPLOAD).as_posix(): hashlib.sha256(p.read_bytes().replace(b'\r', b'')).hexdigest() for p in files}
(ADDON / 'hashes.json').write_text(json.dumps(hashes, indent=4) + '\n')
files.append(ADDON / 'hashes.json')
dist = ROOT / 'dist'
dist.mkdir(exist_ok=True)
archive = dist / ('Evrik-Platega-' + str(meta['version_id']) + '.zip')
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as target:
    for p in sorted(files + [ROOT / 'LICENSE']):
        entry = zipfile.ZipInfo(p.relative_to(ROOT).as_posix(), (2026, 1, 1, 0, 0, 0))
        entry.compress_type = zipfile.ZIP_DEFLATED
        entry.external_attr = 0o100644 << 16
        target.writestr(entry, p.read_bytes())
checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
(dist / (archive.name + '.sha256')).write_text(checksum + '  ' + archive.name + '\n')
print(archive)
