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
