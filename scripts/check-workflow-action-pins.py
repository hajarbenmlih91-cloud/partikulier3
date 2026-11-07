#!/usr/bin/env python3
"""Require immutable commit pins for every external GitHub Actions workflow use."""
from pathlib import Path
import re
import sys

pattern = re.compile(r'^\s*uses:\s*([^\s#]+)\s*(?:#.*)?$', re.MULTILINE)
sha_pattern = re.compile(r'^[0-9a-f]{40}$')
failures: list[str] = []
checked = 0

for path in sorted(Path('.github/workflows').glob('*.yml')):
    for reference in pattern.findall(path.read_text(encoding='utf-8')):
        if reference.startswith('./'):
            continue  # reusable workflow in this same repository
        if '@' not in reference:
            failures.append(f'{path}: external action has no pinned ref: {reference}')
            continue
        repository, ref = reference.rsplit('@', 1)
        if '/' not in repository:
            failures.append(f'{path}: not a qualified action reference: {reference}')
        elif not sha_pattern.fullmatch(ref):
            failures.append(f'{path}: action ref must be a full immutable SHA: {reference}')
        else:
            checked += 1

if failures:
    print('\n'.join(failures), file=sys.stderr)
    raise SystemExit(1)
print(f'Workflow action pinning: PASS ({checked} external uses pinned to 40-character SHAs)')
