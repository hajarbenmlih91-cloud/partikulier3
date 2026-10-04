const result = [];
for (const [index, item] of $input.all().entries()) {
  const request = $('Parse incoming WhatsApp').itemMatching(index).json;
  const response = item.json;
  let reply;
  if (request.operation === 'opt-out') {
    if (response.processed !== true) throw new Error('WordPress did not process STOP');
    if (response.replayed === true) continue;
    reply = 'Votre demande STOP a ete enregistree. Aucun nouveau contact ne sera transmis.';
  } else if (request.operation === 'qualification') {
    if (!['particulier', 'intermediaire'].includes(response.qualified)) {
      throw new Error('WordPress did not record qualification');
    }
    reply = response.qualified === 'particulier' ?
      'Votre reponse a ete enregistree. Renvoyez la reference PK-... pour demander le contact.' :
      'Votre reponse a ete enregistree. Les demandes des intermediaires ne sont pas autorisees.';
  } else if (request.operation === 'contact-authorization') {
    if (response.allowed === true) {
      if (!response.owner?.phone) throw new Error('Allowed contact has no owner phone');
      reply = `Contact du proprietaire: ${response.owner.name || 'Proprietaire'}\nTelephone: ${response.owner.phone}`;
    } else {
      const messages = {
        daily_limit: 'Votre limite quotidienne de contacts est atteinte.',
        property_unavailable: 'Cette annonce est indisponible.',
        owner_unavailable: 'Les coordonnees du proprietaire sont indisponibles.',
        opted_out: 'Votre opposition STOP est active. Aucun contact ne sera transmis.',
      };
      if (response.reason === 'duplicate_message') continue;
      if (['need_qualification', 'need_qualification_pending'].includes(response.reason)) {
        if (typeof response.question !== 'string' || !response.question.trim()) {
          throw new Error('WordPress qualification question is missing');
        }
        reply = `${response.question}\nRepondez PARTICULIER ou INTERMEDIAIRE, puis renvoyez la reference PK-...`;
      } else if (['intermediary_refused', 'manual_review'].includes(response.reason)) {
        if (typeof response.message !== 'string' || !response.message.trim()) {
          throw new Error('WordPress refusal message is missing');
        }
        reply = response.message;
      } else {
        reply = messages[response.reason];
      }
      if (!reply) throw new Error('Unexpected WordPress contact response');
    }
  } else {
    throw new Error('Unexpected WhatsApp operation');
  }
  result.push({ json: { recipient: request.recipient, reply }, pairedItem: { item: index } });
}
return result;
