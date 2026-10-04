const crypto = require('crypto');
const input = $input.first().json.body;
const allowed = ['contact-authorization', 'preferences', 'consent', 'opt-out'];
if (!input || !allowed.includes(input.operation) || !input.payload ||
    typeof input.payload !== 'object' || Array.isArray(input.payload)) {
  throw new Error('Expected an allowed operation and a JSON object payload');
}
const route = `/partikulier/v1/${input.operation}`;
const body = JSON.stringify(input.payload);
const timestamp = String(Math.floor(Date.now() / 1000));
const secret = $env.PARTIKULIER_N8N_SECRET;
const signature = crypto.createHmac('sha256', Buffer.from(secret, 'base64'))
  .update(`POST\n${route}\n${timestamp}\n${body}`).digest('hex');
return [{ json: {
  url: `http://wordpress:8099/wp-json${route}`,
  body,
  headers: {
    'X-Partikulier-Automation': secret,
    'X-Partikulier-Timestamp': timestamp,
    'X-Partikulier-Key-Id': 'env',
    'X-Partikulier-Signature': `sha256=${signature}`,
  },
} }];
