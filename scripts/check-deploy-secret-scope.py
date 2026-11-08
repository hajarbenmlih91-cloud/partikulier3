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
attestation = re.search(r'(?ms)^      - name: Attestation GO production liée au SHA\n(?P<step>.*?)(?=^      - name:)', job)
build = job.find('      - name: Construire et préparer les artefacts')
ssh_setup = job.find('      - name: Configurer SSH strict')
if not attestation or build < 0 or ssh_setup < 0:
    raise SystemExit('production readiness attestation, build, or SSH setup step missing')
attestation_step = attestation.group('step')
for required_check in ('vars.PRD_READINESS_ATTESTATION_SHA', 'vars.PRD_READINESS_EVIDENCE_URL', '"$ATTESTED_SHA" != "$GITHUB_SHA"', 'https://'):
    if required_check not in attestation_step:
        raise SystemExit('production readiness attestation missing guard: ' + required_check)
if not (job.index(attestation.group(0)) < build < ssh_setup):
    raise SystemExit('readiness attestation must precede build and any SSH credentials')
if job.index('      - name: Construire et préparer les artefacts') > job.index('      - name: Configurer SSH strict'):
    raise SystemExit('secret-free build must complete before SSH credentials are introduced')
preflight = re.search(r'(?ms)^      - name: Prévalidation HMAC production en lecture seule\n(?P<step>.*?)(?=^      - name:)', job)
backup = job.find('      - name: Sauvegarde pré-déploiement')
if not preflight or backup < 0 or job.index(preflight.group(0)) > backup:
    raise SystemExit('read-only production HMAC preflight must run before any backup or deployment mutation')
for required_check in ('getenv("PK_DEPLOY_ENV") === "prd"', '$wpEnvironment !== "production"', '$mode !== "enforce"', 'is_strong_secret($secret)'):
    if required_check not in preflight.group('step'):
        raise SystemExit('production HMAC preflight missing guard: ' + required_check)
print('Deploy secret scope: PASS (release-SHA readiness attestation before build/SSH; step-scoped credentials; read-only HMAC preflight before mutation)')
