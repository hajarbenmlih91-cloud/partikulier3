const allowedRecipient = '__WHATSAPP_TEST_RECIPIENT__';
const result = [];
for (const [index, event] of $input.all().entries()) {
  for (const message of event.json.messages ?? []) {
    if (message.from !== allowedRecipient || message.type !== 'text' ||
        typeof message.text?.body !== 'string' || !message.id) {
      continue;
    }
    const text = message.text.body.trim();
    const reference = text.match(/\bPK-\d+-[A-Z0-9]+\b/i)?.[0].toUpperCase();
    const stopped = /^STOP(?:\s|$)/i.test(text);
    result.push({
      json: {
        recipient: message.from,
        operation: stopped ? 'opt-out' : (reference ? 'contact-authorization' : ''),
        payload: {
          wa_id: message.from,
          provider_message_id: message.id,
          ...(reference && !stopped ? { reference } : {}),
        },
        reply: 'Message recu par n8n. Envoyez la reference PK-... affichee sur une annonce Partikulier. Envoyez STOP pour vous desinscrire.',
      },
      pairedItem: { item: index },
    });
  }
}
return result;
