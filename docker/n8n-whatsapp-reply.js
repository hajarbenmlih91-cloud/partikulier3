const result = [];
for (const [index, item] of $input.all().entries()) {
  const request = $('Parse incoming WhatsApp').itemMatching(index).json;
  const response = item.json;
  let reply;
  if (request.operation === 'opt-out') {
    if (response.processed !== true) throw new Error('WordPress did not process STOP');
    if (response.replayed === true) continue;
    reply = 'Votre demande STOP a ete enregistree. Aucun nouveau contact ne sera transmis.';
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
      reply = messages[response.reason];
      if (!reply) throw new Error('Unexpected WordPress contact response');
    }
  } else {
    throw new Error('Unexpected WhatsApp operation');
  }
  result.push({ json: { recipient: request.recipient, reply }, pairedItem: { item: index } });
}
return result;
