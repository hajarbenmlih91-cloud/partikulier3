const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');

const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const recipient = '212600000000';
const parse = new AsyncFunction('$input', fs.readFileSync(path.join(__dirname, '..', 'n8n-whatsapp-parse.js'), 'utf8')
  .replace('__WHATSAPP_TEST_RECIPIENT__', recipient));
const reply = new AsyncFunction('$input', '$', fs.readFileSync(path.join(__dirname, '..', 'n8n-whatsapp-reply.js'), 'utf8'));

function input(json) {
  return { all: () => json.map(value => ({ json: value })) };
}
function message(text, extra = {}) {
  return { id: 'test-message', from: recipient, type: 'text', text: { body: text }, ...extra };
}
function requests(operations) {
  return () => ({ itemMatching: index => ({ json: { recipient, operation: operations[index] } }) });
}

test('TEST produces help without a WordPress operation', async () => {
  const result = await parse(input([{ messages: [message('TEST')] }]));
  assert.equal(result[0].json.operation, '');
  assert.equal(result[0].json.recipient, recipient);
  assert.match(result[0].json.reply, /PK-/);
});

test('property reference and provider message ID are preserved', async () => {
  const result = await parse(input([{ messages: [message('Bonjour pk-123-abcd')] }]));
  assert.equal(result[0].json.operation, 'contact-authorization');
  assert.deepEqual(result[0].json.payload, {
    wa_id: recipient, provider_message_id: 'test-message', reference: 'PK-123-ABCD',
    text: 'Bonjour pk-123-abcd',
  });
});

test('STOP takes precedence over any supplied reference', async () => {
  const result = await parse(input([{ messages: [message('STOP PK-123-ABCD')] }]));
  assert.equal(result[0].json.operation, 'opt-out');
  assert.equal(result[0].json.payload.reference, undefined);
});

test('delivery statuses, other recipients and non-text messages do not reply', async () => {
  const result = await parse(input([
    { statuses: [{ status: 'delivered' }] },
    { messages: [message('TEST', { from: '212611111111' }), message('TEST', { type: 'image' })] },
  ]));
  assert.deepEqual(result, []);
});

test('multiple messages retain input-item pairing', async () => {
  const result = await parse(input([
    { messages: [message('TEST')] },
    { messages: [message('STOP')] },
  ]));
  assert.equal(result.length, 2);
  assert.deepEqual(result.map(item => item.pairedItem.item), [0, 1]);
});

test('allowed contact formats only the authorized owner response', async () => {
  const result = await reply(input([{ allowed: true, owner: { name: 'Demo', phone: '212622222222' } }]), requests(['contact-authorization']));
  assert.match(result[0].json.reply, /Demo/);
  assert.match(result[0].json.reply, /212622222222/);
  assert.equal(result[0].json.recipient, recipient);
});

test('duplicate contact and replayed STOP suppress duplicate replies', async () => {
  const result = await reply(input([
    { allowed: false, reason: 'duplicate_message' },
    { processed: true, replayed: true },
  ]), requests(['contact-authorization', 'opt-out']));
  assert.deepEqual(result, []);
});

test('STOP and all supported refusal reasons produce explicit messages', async () => {
  for (const reason of ['daily_limit', 'property_unavailable', 'owner_unavailable', 'opted_out']) {
    const result = await reply(input([{ allowed: false, reason }]), requests(['contact-authorization']));
    assert.equal(result.length, 1);
    assert.ok(result[0].json.reply);
  }
  const stopped = await reply(input([{ processed: true, replayed: false }]), requests(['opt-out']));
  assert.match(stopped[0].json.reply, /STOP/);
});

test('invalid business responses fail instead of disclosing or claiming success', async () => {
  await assert.rejects(reply(input([{ allowed: true, owner: {} }]), requests(['contact-authorization'])), /no owner phone/);
  await assert.rejects(reply(input([{ allowed: false, reason: 'unexpected' }]), requests(['contact-authorization'])), /Unexpected/);
  await assert.rejects(reply(input([{ processed: false }]), requests(['opt-out'])), /did not process/);
});

test('explicit qualification answers are signed operations, not implicit consent', async () => {
  for (const [text, expected] of [['PARTICULIER', true], ['PRIVATE', true], ['INTERMEDIAIRE', false], ['AGENT', false]]) {
    const result = await parse(input([{ messages: [message(text)] }]));
    assert.equal(result[0].json.operation, 'qualification');
    assert.equal(result[0].json.payload.is_particulier, expected);
    assert.equal(result[0].json.payload.provider_message_id, 'test-message');
  }
  const unknown = await parse(input([{ messages: [message('oui')] }]));
  assert.equal(unknown[0].json.operation, '');
});

test('buyer bridge signs qualification using the exact upstream route and raw body', async () => {
  const crypto = require('node:crypto');
  const secret = Buffer.alloc(48, 7).toString('base64');
  const sign = new AsyncFunction('$input', '$env', 'require', fs.readFileSync(path.join(__dirname, '..', 'n8n-buyer.js'), 'utf8'));
  const payload = { wa_id: recipient, is_particulier: true, provider_message_id: 'qualification-message' };
  const result = await sign({ first: () => ({ json: { body: { operation: 'qualification', payload } } }) }, { PARTIKULIER_N8N_SECRET: secret }, require);
  const request = result[0].json;
  assert.equal(request.url, 'http://wordpress:8099/wp-json/partikulier/v1/qualification');
  assert.equal(request.body, JSON.stringify(payload));
  const expected = crypto.createHmac('sha256', Buffer.from(secret, 'base64'))
    .update(`POST\n/partikulier/v1/qualification\n${request.headers['X-Partikulier-Timestamp']}\n${request.body}`).digest('hex');
  assert.equal(request.headers['X-Partikulier-Signature'], `sha256=${expected}`);
});

test('approval accepts signed raw bodies without transmitting the shared secret header', async () => {
  const crypto = require('node:crypto');
  const secret = Buffer.alloc(48, 9).toString('base64');
  const approval = new AsyncFunction('$input', '$env', 'require', fs.readFileSync(path.join(__dirname, '..', 'n8n-approval.js'), 'utf8'));
  const raw = JSON.stringify({ event: 'listing_approved', listing: { id: 1, title: 'Fixture', url: 'http://example.test' }, account: { send_credentials: false } });
  const timestamp = String(Math.floor(Date.now() / 1000));
  const signature = crypto.createHmac('sha256', Buffer.from(secret, 'base64'))
    .update(`POST\n/webhook/partikulier-listing-approved\n${timestamp}\n${raw}`).digest('hex');
  const headers = { 'x-partikulier-timestamp': timestamp, 'x-partikulier-key-id': 'env', 'x-partikulier-signature': `sha256=${signature}` };
  const context = { helpers: { getBinaryDataBuffer: async () => Buffer.from(raw) } };
  const invoke = values => approval.call(context, { first: () => ({ json: { headers: values } }) }, { PARTIKULIER_N8N_SECRET: secret }, require);
  const result = await invoke(headers);
  assert.equal(result[0].json.send_credentials, false);
  assert.doesNotMatch(result[0].json.message, /Password:/);
  await assert.rejects(invoke({ ...headers, 'x-partikulier-signature': 'sha256=' + '0'.repeat(64) }), /Invalid Partikulier/);
  await assert.rejects(invoke({}), /Invalid Partikulier/);
});

test('qualification and manual-review responses preserve WordPress messages without leaking contacts', async () => {
  for (const reason of ['need_qualification', 'need_qualification_pending', 'intermediary_refused', 'manual_review']) {
    const response = { allowed: false, reason, question: 'Qualification?', message: 'Review required', owner: { phone: 'secret-phone' } };
    const result = await reply(input([response]), requests(['contact-authorization']));
    assert.match(result[0].json.reply, reason.startsWith('need_') ? /Qualification/ : /Review required/);
    assert.doesNotMatch(result[0].json.reply, /secret-phone/);
  }
  const result = await reply(input([{ qualified: 'particulier' }]), requests(['qualification']));
  assert.match(result[0].json.reply, /Renvoyez la reference/);
  await assert.rejects(reply(input([{ qualified: 'unknown' }]), requests(['qualification'])), /did not record/);
  await assert.rejects(reply(input([{ allowed: false, reason: 'manual_review' }]), requests(['contact-authorization'])), /message is missing/);
  await assert.rejects(reply(input([{ allowed: false, reason: 'need_qualification' }]), requests(['contact-authorization'])), /question is missing/);
});
