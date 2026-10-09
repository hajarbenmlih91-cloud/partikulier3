#!/usr/bin/env python3
"""Scénario de visite complète — 3 langues × tous les filtres.
Header (barre de recherche), hero (recherche ville), archive (barre haute +
facettes gauche), pages annonce/dépôt/favoris. Détecte : fuites de langue,
mots bizarres, liens/boutons cassés, compteurs non actualisés.
"""
import re, json, html as htmllib, urllib.request, urllib.parse, collections

BASE = 'https://darkcyan-yak-151002.hostingersite.com'
UA = {'User-Agent': 'Mozilla/5.0 (Arena-QA)'}
LANGS = ['fr', 'en', 'ar']
PREFIX = {'fr': '/fr', 'en': '/en', 'ar': '/ar'}

findings = []
def report(lang, page, kind, detail):
    findings.append({'lang': lang, 'page': page, 'kind': kind, 'detail': detail})

_QA_TS = __import__('time').time_ns()
def get(url, raw=False):
    sep = '&' if '?' in url else '?'
    url = url + sep + 'pkqa=' + str(_QA_TS)
    try:
        r = urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=30)
        body = r.read()
        return r.status, (body if raw else body.decode('utf-8', 'replace'))
    except urllib.error.HTTPError as e:
        return e.code, ''
    except Exception as e:
        return -1, str(e)

def visible(page_html):
    t = re.sub(r'(?s)<(script|style|noscript|svg|template)[^>]*>.*?</\1>', ' ', page_html)
    t = re.sub(r'(?s)<!--.*?-->', ' ', t)
    t = re.sub(r'<[^>]+>', ' ', t)
    return htmllib.unescape(t)

LATIN_WORDS = re.compile(r'[A-Za-zÀ-öø-ÿ]{3,}')
WHITELIST = {'partikulier', 'partikulier.com', 'whatsapp', 'facebook', 'instagram', 'pdf', 'ok', 'wifi',
             'the', 'and', 'for', 'from', 'owners', 'buy', 'rent', 'directly', 'search', 'post', 'free',
             'city', 'all', 'listings', 'morocco', 'fr', 'en', 'ar', 'mad', 'contact', 'email', 'tel',
             'http', 'https', 'com', 'www', 'wa.me', 'app', 'api', 'sms', 'duplex', 'riad', 'casablanca',
             'studio', 'terrain', 'villa', 'immo', 'arial', 'sans', 'serif', 'ltr', 'rtl', 'html', 'css'}

def lang_purity(lang, page, text):
    if lang == 'ar':
        bad = []
        for w in set(LATIN_WORDS.findall(text)):
            wl = w.lower()
            if wl not in WHITELIST and not wl.startswith(('pk', 'wp', 'aria', 'href', 'class', 'id')):
                bad.append(w)
        if bad:
            report(lang, page, 'fuite-latine', ', '.join(sorted(bad)[:12]))
    if lang == 'en':
        for fr in ['Déposer', 'annonces', 'entre particuliers', 'Rechercher', 'Favoris', 'Ville', 'Type de bien', 'Accueil', 'Voir l', 'mois', 'gratuitement', 'Étage', 'chambres']:
            if fr in text:
                report(lang, page, 'fuite-fr', fr)
    if lang == 'fr':
        for enw in ['Buy and rent', 'Post for free', 'Search by city', 'directly from owners', 'View listing']:
            if enw in text:
                report(lang, page, 'fuite-en', enw)
    if lang == 'ar' and 'MAD' in text:
        report(lang, page, 'devise-MAD', 'MAD visible (devrait être درهم / MAD seulement si validé)')

PAGES = {'home': '/', 'archive': '/annonces/', 'deposer': '/deposer/', 'favoris': '/favoris/'}

# ---------- 1) Pages de base : statut, <html lang/dir>, pureté ----------
listing_urls = {}
for lang in LANGS:
    for name, path in PAGES.items():
        st, htmlpage = get(BASE + PREFIX[lang] + path)
        if st != 200:
            report(lang, name, 'http', f'status {st}')
            continue
        m = re.search(r'<html[^>]*lang="([^"]+)"', htmlpage)
        if m and not m.group(1).startswith(lang):
            report(lang, name, 'html-lang', m.group(1))
        if lang == 'ar' and 'dir="rtl"' not in htmlpage[:600]:
            report(lang, name, 'rtl', 'dir=rtl absent du <html>')
        lang_purity(lang, name, visible(htmlpage))
        if name == 'archive':
            urls = re.findall(r'href="([^"]*(?:/properties/|/annonce/)[^"]+)"', htmlpage)
            if urls:
                listing_urls[lang] = htmllib.unescape(urls[0])

# page annonce (1 par langue)
for lang in LANGS:
    u = listing_urls.get(lang)
    if not u:
        report(lang, 'annonce', 'absent', "aucune annonce trouvée dans l'archive")
        continue
    st, htmlpage = get(u)
    if st != 200:
        report(lang, 'annonce', 'http', f'{u} -> {st}')
    else:
        lang_purity(lang, 'annonce', visible(htmlpage))

# ---------- 2) Recherche header/hero : autocomplete + soumission ----------
for lang in LANGS:
    st, htmlpage = get(BASE + PREFIX[lang] + '/')
    cfg_m = re.search(r'var pkConfig\s*=\s*(\{.*?\});', htmlpage, re.S)
    if not cfg_m:
        report(lang, 'hero', 'config', 'pkConfig absent')
        continue
    # autocomplete lieux (saisie native + saisie fuzzy)
    queries = {'fr': 'casabla', 'en': 'marrak', 'ar': 'الدار'}
    q = queries[lang]
    st2, body = get(BASE + '/wp-admin/admin-ajax.php?action=pk_places_search&scope=city&q='
                    + urllib.parse.quote(q) + '&lang=' + lang)
    try:
        res = json.loads(body).get('data', {}).get('results', [])
        if not res:
            report(lang, 'hero-autocomplete', 'vide', f'q={q}')
    except Exception:
        report(lang, 'hero-autocomplete', 'reponse', body[:120])
    # soumission hero : ville -> archive filtrée
    st3, arch = get(BASE + PREFIX[lang] + '/annonces/?es_city=casablanca-ar')
    if st3 != 200:
        report(lang, 'hero-submit', 'http', f'{st3}')
    else:
        if 'casablanca' not in arch.lower() and 'الدار' not in arch:
            report(lang, 'hero-submit', 'filtre-ville-ignorer', 'archive ne reflète pas es_city')

# ---------- 3) Archive : barre haute (action/type) + facettes gauche ----------
def facet_counts(htmlpage):
    out = {}
    for m in re.finditer(r'<li><a href="[^"]*"[^>]*>(.*?)\s*<span class="pk-filter-count">\((\d+)\)</span></a></li>', htmlpage, re.S):
        name = re.sub(r'<[^>]+>', '', m.group(1)).strip()
        out[name] = int(m.group(2))
    return out

for lang in LANGS:
    base_arch = BASE + PREFIX[lang] + '/annonces/'
    st, sale = get(base_arch + '?es_action=a-vendre')
    st2, rent = get(base_arch + '?es_action=a-louer')
    if st != 200 or st2 != 200:
        report(lang, 'archive-top', 'http', f'vente {st} / location {st2}')
        continue
    cs, cr = facet_counts(sale), facet_counts(rent)
    if not cs or not cr:
        report(lang, 'archive-facettes', 'parse', 'compteurs introuvables')
        continue
    if cs == cr:
        report(lang, 'archive-facettes', 'compteurs-statiques', 'identiques vente/location')
    # budget : le plafond réduit les compteurs
    st3, bud = get(base_arch + '?es_action=a-vendre&es_price_max=500000')
    cb = facet_counts(bud) if st3 == 200 else {}
    if cb and sum(cb.values()) >= sum(cs.values()):
        report(lang, 'archive-budget', 'compteurs-non-filtres', f'sans plafond {sum(cs.values())} vs avec {sum(cb.values())}')
    # croisement type+action (exemple user : duplex location = 0)
    st4, cross = get(base_arch + '?es_action=a-louer&es_type=' + ('duplex-ar' if lang == 'ar' else 'duplex'))
    if st4 == 200:
        cc = facet_counts(cross)
        # en location+duplex, le compteur duplex doit être 0-contexte ou cohérent

# ---------- 4) Audit des liens internes (home + archive) ----------
for lang in LANGS:
    for name, path in [('home', '/'), ('archive', '/annonces/')]:
        st, htmlpage = get(BASE + PREFIX[lang] + path)
        if st != 200:
            continue
        hrefs = sorted(set(htmllib.unescape(x) for x in re.findall(r'href="([^"#]+)"', htmlpage)
                           if x.startswith('http') and 'darkcyan-yak' in x and '/wp-content/' not in x
                           and 'feed' not in x and 'wp-login' not in x and 'wp-json' not in x))
        bad = []
        for u in hrefs[:60]:
            s, _ = get(u)
            if s not in (200, 301, 302):
                bad.append(f'{u} -> {s}')
        if bad:
            report(lang, name, 'liens-casses', ' ; '.join(bad[:8]))

# ---------- sortie ----------
print(json.dumps(findings, ensure_ascii=False, indent=1))
print('TOTAL FINDINGS:', len(findings))
