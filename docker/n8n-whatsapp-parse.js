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
    const qualification = /^(?:PARTICULIER|PRIVATE)$/i.test(text) ? true :
      (/^(?:INTERMEDIAIRE|INTERM\u00c9DIAIRE|AGENT)$/i.test(text) ? false : undefined);
    result.push({
      json: {
        recipient: message.from,
        operation: stopped ? 'opt-out' : (qualification !== undefined ? 'qualification' : (reference ? 'contact-authorization' : '')),
        payload: {
          wa_id: message.from,
          provider_message_id: message.id,
          ...(reference && !stopped ? { reference } : {}),
          ...(!stopped && qualification !== undefined ? { is_particulier: qualification } : {}),
          ...(reference && !stopped ? { text } : {}),
        },
        reply: 'Message recu par n8n. Envoyez la reference PK-... affichee sur une annonce Partikulier. Si demande, repondez PARTICULIER ou INTERMEDIAIRE, puis renvoyez la reference. Envoyez STOP pour vous desinscrire.',
      },
      pairedItem: { item: index },
    });
  }
}
return result;
