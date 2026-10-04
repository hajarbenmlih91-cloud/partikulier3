const crypto = require('crypto');
const item = $input.first();
const headers = item.json.headers;
const raw = (await this.helpers.getBinaryDataBuffer(0, 'data')).toString('utf8');
const timestamp = headers['x-partikulier-timestamp'];
const signature = headers['x-partikulier-signature'];
const canonical = `POST\n/webhook/partikulier-listing-approved\n${timestamp}\n${raw}`;
const digest = crypto.createHmac('sha256', Buffer.from($env.PARTIKULIER_N8N_SECRET, 'base64'))
  .update(canonical).digest('hex');
const expected = `sha256=${digest}`;
if (!/^\d+$/.test(timestamp || '') || Math.abs(Date.now() / 1000 - Number(timestamp)) > 300 ||
    headers['x-partikulier-key-id'] !== 'env' || typeof signature !== 'string' ||
    signature.length !== expected.length ||
    !crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected))) {
  throw new Error('Invalid Partikulier webhook signature');
}
const payload = JSON.parse(raw);
if (payload.event !== 'listing_approved' || !payload.listing?.id || !payload.account) {
  throw new Error('Invalid listing approval payload');
}
const credentials = payload.account.send_credentials === true;
const lines = [
  'LOCAL EXAMPLE: simulated WhatsApp delivery, captured only in Mailpit.',
  `Listing: ${payload.listing.title}`,
  `URL: ${payload.listing.url}`,
];
if (credentials) {
  if (!payload.account.login || !payload.account.password) {
    throw new Error('Missing credentials for first approval');
  }
  lines.push(`Login: ${payload.account.login}`, `Password: ${payload.account.password}`,
    `Sign in: ${payload.account.login_url}`);
} else {
  lines.push('Listing published. Existing account credentials are unchanged.');
}
return [{ json: {
  subject: `Partikulier local approval #${payload.listing.id}`,
  message: lines.join('\n'),
  listing_id: payload.listing.id,
  send_credentials: credentials,
} }];
