#!/usr/bin/env python3
"""Build and execute the job-local signed n8n workflow; never prints its secret or body."""
import base64
import hashlib
import hmac
import json
import os
import pathlib
import re
import sys
import time
import urllib.error
import urllib.request
import uuid


def build():
    temp = pathlib.Path(os.environ['RUNNER_TEMP'])
    secret = os.environ['PK_EPHEMERAL_SECRET']
    key = base64.b64decode(secret, validate=True)
    wp_ip = os.environ['WP_CONTAINER_IP']
    wp_home = os.environ['WP_HOME']
    listing = json.loads((temp / 'listing.json').read_text())
    run_id = os.environ['GITHUB_RUN_ID']
    attempt = os.environ['GITHUB_RUN_ATTEMPT']
    endpoint = f'http://{wp_ip}/wp-json/partikulier/v1/automation-event'

    events = [
        ('WhatsApp status only', 'whatsapp_status', 'n8n-page-status', {'code': listing['code'], 'provider_message_id': 'ci-status', 'status': 'delivered'}),
        ('Payment status only', 'payment_status', 'n8n-page-payment', {'code': listing['code'], 'provider_message_id': 'ci-payment', 'status': 'paid'}),
        ('Unknown inbound code', 'whatsapp_inbound', 'n8n-page-other-code', {'code': 'NO-MATCH-' + run_id, 'provider_message_id': 'ci-unknown-code'}),
        ('First signed inbound', 'whatsapp_inbound', 'n8n-page-inbound', {'code': listing['code'], 'provider_message_id': 'ci-inbound-' + run_id}),
        ('Exact inbound replay', 'whatsapp_inbound', 'n8n-page-inbound', {'code': listing['code'], 'provider_message_id': 'ci-inbound-' + run_id}),
    ]
    nodes = [{'id': str(uuid.uuid4()), 'name': 'Manual Trigger', 'type': 'n8n-nodes-base.manualTrigger', 'typeVersion': 1, 'position': [200, 300], 'parameters': {}}]
    names = ['Manual Trigger']
    for index, (name, event_type, suffix, payload) in enumerate(events):
        event_id = f'{suffix}-{run_id}-{attempt}'
        body = json.dumps({'event_id': event_id, 'event_type': event_type, 'source': 'n8n', 'payload': payload}, separators=(',', ':'), ensure_ascii=False)
        timestamp = str(int(time.time()))
        canonical = f'POST\n/wp-json/partikulier/v1/automation-event\n{timestamp}\n{body}'.encode()
        signature = 'sha256=' + hmac.new(key, canonical, hashlib.sha256).hexdigest()
        headers = [
            {'name': 'Content-Type', 'value': 'application/json'},
            {'name': 'Host', 'value': 'wordpress:8080'},
            {'name': 'X-Partikulier-Automation', 'value': secret},
            {'name': 'X-Partikulier-Algorithm', 'value': 'sha256'},
            {'name': 'X-Partikulier-Timestamp', 'value': timestamp},
            {'name': 'X-Partikulier-Key-Id', 'value': 'N'},
            {'name': 'X-Partikulier-Signature', 'value': signature},
        ]
        params = {
            'method': 'POST', 'url': endpoint, 'sendHeaders': True,
            'headerParameters': {'parameters': headers}, 'sendBody': True,
            'contentType': 'raw', 'rawContentType': 'application/json', 'body': body,
            'options': {'response': {'response': {'fullResponse': True, 'responseFormat': 'json'}}},
        }
        node_name = name
        nodes.append({'id': str(uuid.uuid4()), 'name': node_name, 'type': 'n8n-nodes-base.httpRequest', 'typeVersion': 4.2, 'position': [440 + index * 240, 300], 'parameters': params})
        names.append(node_name)

    wait_name = 'Allow browser polls after non-matching events'
    nodes.append({'id': str(uuid.uuid4()), 'name': wait_name, 'type': 'n8n-nodes-base.wait', 'typeVersion': 1.1, 'position': [1640, 500], 'parameters': {'amount': 10, 'unit': 'seconds'}})
    # Reorder the delayed valid inbound/replay after the three non-matching event requests.
    # Existing positions are cosmetic; connections define the execution order.
    connections = {
        'Manual Trigger': {'main': [[{'node': events[0][0], 'type': 'main', 'index': 0}]]},
        events[0][0]: {'main': [[{'node': events[1][0], 'type': 'main', 'index': 0}]]},
        events[1][0]: {'main': [[{'node': events[2][0], 'type': 'main', 'index': 0}]]},
        events[2][0]: {'main': [[{'node': wait_name, 'type': 'main', 'index': 0}]]},
        wait_name: {'main': [[{'node': events[3][0], 'type': 'main', 'index': 0}]]},
        events[3][0]: {'main': [[{'node': events[4][0], 'type': 'main', 'index': 0}]]},
    }
    workflow_id = str(uuid.uuid4())
    workflow = {'id': workflow_id, 'name': 'Disposable signed WhatsApp inbound to open-page match', 'active': False, 'nodes': nodes, 'connections': connections, 'settings': {'executionOrder': 'v1'}, 'staticData': None, 'pinData': {}, 'tags': []}
    node_types = [node['type'] for node in nodes]
    http_nodes = [node for node in nodes if node['type'] == 'n8n-nodes-base.httpRequest']
    assert len(http_nodes) == 5 and len({node['parameters']['url'] for node in http_nodes}) == 1
    assert all(node['parameters']['url'] == endpoint for node in http_nodes)
    assert set(node_types) == {'n8n-nodes-base.manualTrigger', 'n8n-nodes-base.httpRequest', 'n8n-nodes-base.wait'}
    assert all(node['parameters']['headerParameters']['parameters'][1]['value'] == 'wordpress:8080' for node in http_nodes)
    serialized = json.dumps(workflow).lower()
    assert not any(term in serialized for term in ('graph.facebook.com', 'evolution api', 'whatsapp node', 'meta node', 'wa.me'))
    target = temp / 'whatsapp-n8n-workflow.json'
    target.write_text(json.dumps(workflow, separators=(',', ':'), ensure_ascii=False))
    target.chmod(0o600)
    (temp / 'whatsapp-n8n-workflow-id').write_text(workflow_id)
    (temp / 'whatsapp-n8n-endpoint').write_text(endpoint)
    print(json.dumps({'workflow_nodes': 7, 'http_nodes': 5, 'destination': endpoint}, ensure_ascii=False))


def execute():
    import http.cookiejar
    temp = pathlib.Path(os.environ['RUNNER_TEMP'])
    base = os.environ['N8N_BASE'].rstrip('/')
    password = os.environ['N8N_OWNER_PASSWORD']
    workflow_id = (temp / 'whatsapp-n8n-workflow-id').read_text().strip()
    jar = http.cookiejar.CookieJar()
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

    def call(method, path, payload=None):
        data = None if payload is None else json.dumps(payload, separators=(',', ':')).encode()
        req = urllib.request.Request(base + path, data=data, method=method, headers={'Content-Type': 'application/json', 'Accept': 'application/json'})
        try:
            with client.open(req, timeout=30) as res:
                raw = res.read().decode('utf-8', 'replace')
                return res.status, json.loads(raw) if raw else {}
        except urllib.error.HTTPError as exc:
            raw = exc.read().decode('utf-8', 'replace')
            try: body = json.loads(raw)
            except Exception: body = {}
            return exc.code, body

    def find(value, key):
        if isinstance(value, dict):
            if key in value: return value[key]
            for child in value.values():
                found = find(child, key)
                if found is not None: return found
        elif isinstance(value, list):
            for child in value:
                found = find(child, key)
                if found is not None: return found
        return None

    def response_for(run_data, node_name):
        for run in run_data.get(node_name, []) if isinstance(run_data.get(node_name), list) else []:
            groups = run.get('data', {}).get('main', []) if isinstance(run, dict) else []
            for group in groups if isinstance(groups, list) else []:
                for entry in group if isinstance(group, list) else []:
                    value = entry.get('json', {}) if isinstance(entry, dict) else {}
                    if not isinstance(value, dict): continue
                    status = value.get('statusCode', value.get('status', 0))
                    body = value.get('body', value)
                    if isinstance(body, str):
                        try: body = json.loads(body)
                        except Exception: body = {}
                    if isinstance(body, dict):
                        return {'http': int(status or 0), 'accepted': body.get('accepted'), 'duplicate': body.get('duplicate'), 'processing': body.get('processing')}
        return None

    try:
        owner_code, _ = call('POST', '/rest/owner/setup', {'email': 'ci-owner@example.invalid', 'firstName': 'Disposable', 'lastName': 'CI', 'password': password})
        if owner_code not in (200, 201): raise RuntimeError('owner setup failed')
        login_code, _ = call('POST', '/rest/login', {'emailOrLdapLoginId': 'ci-owner@example.invalid', 'password': password})
        if login_code != 200: raise RuntimeError('owner login failed')
        started_code, started = call('POST', f'/rest/workflows/{workflow_id}/run', {'triggerToStartFrom': {'name': 'Manual Trigger'}})
        if started_code != 200: raise RuntimeError(f'manual run start HTTP {started_code}')
        execution_id = find(started, 'executionId')
        if not execution_id: raise RuntimeError('execution id missing')
        execution = {}
        for _ in range(120):
            status_code, execution = call('GET', f'/rest/executions/{execution_id}')
            if status_code == 200:
                finished = find(execution, 'finished')
                state = find(execution, 'status')
                err = find(find(execution, 'resultData') or {}, 'error')
                if finished is True or state in ('success', 'error', 'crashed', 'canceled') or err is not None: break
            time.sleep(1)
        else: raise RuntimeError('execution timeout')
        if find(find(execution, 'resultData') or {}, 'error') is not None:
            raise RuntimeError('n8n execution failed; raw diagnostic withheld')
        run_data = find(execution, 'runData')
        if not isinstance(run_data, dict): raise RuntimeError('n8n execution data missing')
        names = ['WhatsApp status only', 'Payment status only', 'Unknown inbound code', 'First signed inbound', 'Exact inbound replay']
        outcomes = {name: response_for(run_data, name) for name in names}
        if any(value is None for value in outcomes.values()): raise RuntimeError('one or more WordPress request results are missing')
        expected = [
            (200, True, False, 'disabled'), (200, True, False, 'disabled'),
            (200, True, False, 'disabled'), (200, True, False, 'disabled'),
            (200, True, True, 'disabled'),
        ]
        for (name, value), want in zip(outcomes.items(), expected):
            actual = (value['http'], value['accepted'], value['duplicate'], value['processing'])
            if actual != want: raise RuntimeError(f'unexpected WordPress result for {name}: HTTP {value["http"]}')
        output = {'responses': {name: {'http': value['http'], 'accepted': value['accepted'], 'duplicate': value['duplicate'], 'processing': value['processing']} for name, value in outcomes.items()}}
        (temp / 'n8n-results.json').write_text(json.dumps(output, separators=(',', ':')))
        print(json.dumps(output, separators=(',', ':')))
    except Exception as exc:
        # Intentionally suppress raw n8n responses, headers, execution data, and bodies.
        print(f'n8n integration failed: {str(exc)[:180]}', file=sys.stderr)
        raise SystemExit(1)


if __name__ == '__main__':
    if len(sys.argv) != 2 or sys.argv[1] not in ('build', 'execute'):
        raise SystemExit('usage: ephemeral-whatsapp-n8n.py build|execute')
    {'build': build, 'execute': execute}[sys.argv[1]]()
