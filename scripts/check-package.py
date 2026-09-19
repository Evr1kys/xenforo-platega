#!/usr/bin/env python3
import json
import re
from pathlib import Path
from xml.etree import ElementTree as ET

root = Path(__file__).resolve().parents[1] / 'upload/src/addons/Evrik/Platega'
data = root / '_data'
meta = json.loads((root / 'addon.json').read_text(encoding='utf-8'))

assert isinstance(meta.get('version_id'), int) and meta['version_id'] > 0
assert isinstance(meta.get('version_string'), str) and meta['version_string'].strip()
assert meta['require']['XF'][0] == 2020070
assert meta['require']['php'][0] == '7.4.0'

required = [
    root / 'Setup.php',
    root / 'Payment/Platega.php',
    root / 'Payment/State.php',
    root / 'Api/Client.php',
    root / 'Api/Protocol.php',
    root / 'Admin/Controller/Payment.php',
    data / 'admin_navigation.xml',
    data / 'phrases.xml',
    data / 'routes.xml',
    data / 'templates.xml'
]
for path in required:
    assert path.is_file(), f'Missing package file: {path.relative_to(root)}'

xml_roots = {}
for path in sorted(data.glob('*.xml')):
    xml_roots[path.name] = ET.parse(path).getroot()

phrases_root = xml_roots['phrases.xml']
templates_root = xml_roots['templates.xml']
phrase_titles = [node.attrib['title'] for node in phrases_root]
template_titles = [node.attrib['title'] for node in templates_root]
assert len(phrase_titles) == len(set(phrase_titles)), 'Duplicate phrase title'
assert len(template_titles) == len(set(template_titles)), 'Duplicate template title'
assert {
    'payment_profile_evrikPlatega',
    'evrik_platega_payments',
    'evrik_platega_result'
}.issubset(template_titles)

for path_name in ('phrases.xml', 'templates.xml'):
    for node in xml_roots[path_name]:
        version_id = int(node.attrib.get('version_id', '0'))
        assert 0 < version_id <= meta['version_id'], (
            f'{path_name}: invalid version_id {version_id} for {node.attrib.get("title")}'
        )

phrase_set = set(phrase_titles)
for path in [*root.rglob('*.php'), data / 'templates.xml']:
    source = path.read_text(encoding='utf-8')
    for phrase in re.findall(r"phrase\(\s*['\"]([^'\"]+)['\"]", source):
        assert phrase in phrase_set, f'Missing phrase: {phrase} ({path.relative_to(root)})'

for path in root.rglob('*.php'):
    raw = path.read_bytes()
    assert not raw.startswith(b'\xef\xbb\xbf'), f'UTF-8 BOM in {path.relative_to(root)}'
    source = raw.decode('utf-8')
    assert source.startswith('<?php'), f'PHP opening tag missing in {path.relative_to(root)}'
    assert not source.rstrip().endswith('?>'), f'Closing PHP tag in {path.relative_to(root)}'

print(
    'OK: add-on metadata, required files, XML, versions, unique data IDs, '
    'phrase references and PHP source hygiene'
)

legacy = root.parents[4] / 'legacy'
assert (legacy / 'upload/library/Evrik/Platega/Protocol.php').read_bytes() == (root / 'Api/Protocol.php').read_bytes(), 'Legacy protocol copy must match the shared implementation'
legacy_xml = ET.parse(legacy / 'addon-EvrikPlategaLegacy.xml').getroot()
assert legacy_xml.tag == 'addon' and legacy_xml.attrib['addon_id'] == 'EvrikPlategaLegacy'
assert legacy_xml.attrib['version_id'] == '1000070'
assert {x.attrib['hint'] for x in legacy_xml.find('code_event_listeners')} == {
    'XenForo_ControllerPublic_Account', 'XenForo_DataWriter_UserUpgrade'}
assert '_xfToken' in legacy_xml.find('templates/template').text
print('OK: legacy installer, controller registration, CSRF field and shared protocol')

expected_legacy_files = {'README.md', 'addon-EvrikPlategaLegacy.xml',
    'upload/platega_callback.php', 'upload/platega_reconcile.php'}
expected_legacy_files.update('upload/library/Evrik/Platega/' + name + '.php'
    for name in ['Account', 'Client', 'Protocol', 'Service', 'Setup', 'UserUpgrade'])
assert {p.relative_to(legacy).as_posix() for p in legacy.rglob('*') if p.is_file()} == expected_legacy_files
