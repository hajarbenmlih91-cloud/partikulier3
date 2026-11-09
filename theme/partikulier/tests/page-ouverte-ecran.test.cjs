"use strict";

const fs = require("fs");
const assert = require("assert");
const path = require("path");

const jsPath = path.join(__dirname, "../assets/js/main.js");
const js = fs.readFileSync(jsPath, "utf8");
const start = js.indexOf("function watchInboundMatch");
const end = js.indexOf("function setStatus");
assert.ok(start > 0 && end > start, "fonction de la page ouverte absente");
const source = js.slice(start, end);
assert.ok(!/wa\.me|sendMessage|reply/i.test(source), "la page n'envoie pas de réponse");

const locations = [];
const ticks = [];
global.window = {
  setInterval(callback) {
    ticks.push(callback);
    return 1;
  },
  clearInterval() {},
  location: {},
};
Object.defineProperty(global.window.location, "href", {
  set(value) {
    locations.push(value);
  },
  get() {
    return locations.length ? locations[locations.length - 1] : "";
  },
});
const replies = [];
global.fetch = async function () {
  replies.push("poll");
  const next = responses.shift();
  return { json: async () => next };
};
let responses = [];
async function main() {
const watchInboundMatch = new Function(source + "\nreturn watchInboundMatch;")();
const listing = "http://127.0.0.1:8099/?p=essai";
watchInboundMatch({ status_url: "http://127.0.0.1:8099/wp-admin/admin-ajax.php?action=pk_whatsapp_match_status", url: listing }, {});
assert.strictEqual(ticks.length, 1, "la page ouverte interroge");
assert.strictEqual(locations.length, 0, "aucun changement avant le sondage");

async function settle() {
  await ticks[0]();
  await new Promise((resolve) => setImmediate(resolve));
}
responses.push({ success: true, data: { matched: false, url: "" } });
await settle();
assert.strictEqual(locations.length, 0, "sans message reçu, l'écran ne change pas");

responses.push({ success: true, data: { matched: true, url: listing } });
await settle();
assert.strictEqual(locations.length, 1, "l'écran change une fois");
assert.strictEqual(locations[0], listing, "l'écran ouvre l'annonce");
assert.strictEqual(replies.length, 2);
console.log("ECRAN_OK");
}
main();
