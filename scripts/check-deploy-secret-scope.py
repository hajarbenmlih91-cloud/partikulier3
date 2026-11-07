#!/usr/bin/env python3
"""Static regression: deployment credentials must not be job-wide env vars."""
from pathlib import Path
import re

workflow = Path('.github/workflows/deploy.yml').read_text(encoding='utf-8')
match = re.search(r'(?ms)^  deploy:\n(?P<job>.*)$', workflow)
if not match:
    raise SystemExit('deploy job missing')
job = match.group('job')
env_match = re.search(r'(?ms)^    env:\n(?P<env>.*?)(?=^    defaults:)', job)
if not env_match:
    raise SystemExit('deploy job environment block missing')
if 'secrets.' in env_match.group('env'):
    raise SystemExit('deployment secrets must not be exposed as job-wide environment variables')

ssh_step = re.search(r'(?ms)^      - name: Configurer SSH strict\n(?P<step>.*?)(?=^      - name:)', job)
if not ssh_step:
    raise SystemExit('SSH setup step missing')
ssh_env = ssh_step.group('step').split('        run: |', 1)[0]
required = ('SSH_HOST', 'SSH_PORT', 'SSH_USER', 'SSH_PRIVATE_KEY', 'SSH_KNOWN_HOSTS', 'WP_PATH')
if any(f'{name}: ${{{{ secrets.{name} }}}}' not in ssh_env for name in required):
    raise SystemExit('SSH setup secrets are not explicitly scoped to the SSH setup step')
if job.index('      - name: Construire et préparer les artefacts') > job.index('      - name: Configurer SSH strict'):
    raise SystemExit('secret-free build must complete before SSH credentials are introduced')
print('Deploy secret scope: PASS (no job-wide secrets; SSH credentials are step-scoped after build)')
