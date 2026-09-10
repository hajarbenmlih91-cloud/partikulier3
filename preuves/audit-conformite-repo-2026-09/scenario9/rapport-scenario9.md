# Scénario 9 — Rendu AR et données structurées (SIM-09 rejouée)

**Site :** sandbox http://127.0.0.1:8091 (thème 6.17.29, 10 annonces × 3 langues) · **Date :** 2026-09-10T20:53:11
**Périmètre :** lecture seule — aucune donnée créée/modifiée, aucun correctif appliqué (règle absolue n°3).

| # | Contrôle | Verdict | Détail |
|---|---|---|---|
| S9.1 | /ar/ lang + dir | **PASS** | lang="ar" dir="rtl" · <title> Partikulier Sandbox |
| S9.2 | /ar/annonces/ RTL + 10 cartes | **PASS** | lang="ar" dir="rtl" · 10 cartes |
| S9.3 | Titres de cartes sans caractères latins | **PASS** | 12 titres échantillonnés, 0 mot latin |
| S9.4 | Libellés screen-reader des cartes traduits en AR | **PASS** | libellés: المساحة | النوع | التكوين | حمامات | شرفة |
| S9.5 | Placeholders traduits en AR | **PASS** | placeholders: المدينة، الرمز البريدي، الحي… | كل المدن | كل المدن |
| S9.6 | JSON-LD archive AR sans résidu français | **PASS** | ItemList.name = "إعلانات عقارية مجانية" · anciens FAIL mots-clés: aucun |
| S9.7 | Alternates hreflang complets | **PASS** | en→http://127.0.0.1:8091/annonces/ · fr→http://127.0.0.1:8091/fr/annonces/ · ar→http://127.0.0.1:8091/ar/annonces/ · fr-FR→http://127.0.0.1:8091/fr/annonces/ · ar-MA→http://127.0.0.1:8091/ar/annonces/ · en-US→http://127.0.0.1:8091/annonces/ · x-default→http://127.0.0.1:8091/fr/annonces/ |
| S9.8 | Fiches AR : RTL + JSON-LD sans résidu latin ni entité | **PASS** | fiche 1: lang=ar, dir=rtl, résidus-mots-clés=0, valeurs-latines=0, entité-&hellip;=absente ; fiche 2: lang=ar, dir=rtl, résidus-mots-clés=0, valeurs-latines=0, entité-&hellip;=absente ; fiche 3: lang=ar, dir=rtl, résidus-mots-clés=0, valeurs-latines=0, entité-&hellip;=absente |
| S9.10 | <title> des fiches AR sans variation SEO française | **PASS** | أرض بمساحة 500 م² للبيع في تارغة، مراكش &#8211; للبيع أرض في مراكش | P ; شقة بمساحة 105 م² مع تراس بمساحة 10 م² للكراء في هيفيرناژ، مراكش &#821 ; رياض بمساحة 180 م² مع تراس بمساحة 30 م² للبيع في المدينة القديمة، مراك |
| S9.11 | Options des selects du hero traduites en AR | **PASS** | action: الكل/بيع/كراء · type: الكل/شقة/دوبلكس/منزل |
| S9.9 | Mobile AR : une carte par rangée | **PASS** | 10 cartes · une par rangée: true |

**Bilan : 11/11 PASS.**