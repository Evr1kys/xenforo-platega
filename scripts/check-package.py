#!/usr/bin/env python3
import json
import re
from pathlib import Path
from xml.etree import ElementTree as ET

root = Path(__file__).resolve().parents[1] / 'upload/src/addons/Evrik/Platega'
meta = json.loads((root / 'addon.json').read_text())
assert meta['require']['XF'][0] == 2020070
phrases = {p.attrib['title'] for p in ET.parse(root / '_data/phrases.xml').getroot()}
templates = ET.parse(root / '_data/templates.xml').getroot()
assert len(templates) == 3
for path in (root / "_data").glob("*.xml"):
    ET.parse(path)
for path in [*root.rglob('*.php'), root / '_data/templates.xml']:
    for phrase in re.findall(r"phrase\('([^']+)'", path.read_text()):
        assert phrase in phrases, f'Missing phrase: {phrase}'
print('OK: add-on metadata, XML and phrase references')
