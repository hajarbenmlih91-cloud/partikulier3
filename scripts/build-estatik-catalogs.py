#!/usr/bin/env python3
"""Compile les catalogues Estatik du thème (languages/estatik/es-*.po → .mo).

Même format canonique que scripts/build-catalogs.php (GNU rev 0, table de
hachage vide, originaux puis traductions, entrées triées par octets de clé,
clé = msgctxt + \\x04 + msgid quand msgctxt présent). L'entrée d'en-tête
(msgid "") n'est pas embarquée.

Usage : python3 scripts/build-estatik-catalogs.py [--check]
"""
import struct, sys, os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ES_DIR = os.path.join(ROOT, 'theme/partikulier/languages/estatik')


def po_unescape(s):
    out = []
    i = 0
    while i < len(s):
        c = s[i]
        if c == '\\' and i + 1 < len(s):
            n = s[i + 1]
            if n == 'n': out.append('\n'); i += 2; continue
            if n == 't': out.append('\t'); i += 2; continue
            if n == '"': out.append('"'); i += 2; continue
            if n == '\\': out.append('\\'); i += 2; continue
        out.append(c); i += 1
    return ''.join(out)


def po_inner(s):
    s = s.strip()
    if not s.startswith('"'):
        return ''
    end = s.rfind('"')
    return s[1:end] if end > 0 else ''


def parse_po(path):
    with open(path, encoding='utf-8') as f:
        raw = f.read()
    entries = {}
    for block in raw.split('\n\n'):
        lines = [l.rstrip() for l in block.split('\n') if l.rstrip() != '']
        if not lines:
            continue
        field = None
        ctxt, id_parts, str_parts = [], [], []
        for line in lines:
            if line.startswith('msgctxt '):
                field = 'ctxt'; ctxt = [po_inner(line[8:])]
            elif line.startswith('msgid '):
                field = 'id'; id_parts = [po_inner(line[6:])]
            elif line.startswith('msgstr '):
                field = 'str'; str_parts = [po_inner(line[7:])]
            elif line.startswith('"') and line.endswith('"'):
                if field == 'ctxt': ctxt.append(po_inner(line))
                elif field == 'id': id_parts.append(po_inner(line))
                elif field == 'str': str_parts.append(po_inner(line))
        if not id_parts:
            continue
        msgid = po_unescape(''.join(id_parts))
        if msgid == '':
            continue  # en-tête non embarqué
        key = (po_unescape(''.join(ctxt)) + '\x04' + msgid) if ctxt else msgid
        entries[key] = po_unescape(''.join(str_parts))
    return entries


def compile_mo(entries):
    items = sorted(entries.items(), key=lambda kv: kv[0].encode('utf-8'))
    count = len(items)
    header = struct.pack('<7I', 0x950412DE, 0, count, 28, 28 + 8 * count, 0, 28 + 16 * count)
    offset = 28 + 16 * count
    orig_table = b''; orig_strings = b''
    pairs = []
    for msgid, msgstr in items:
        kb = msgid.encode('utf-8'); vb = msgstr.encode('utf-8')
        pairs.append((kb, vb))
        orig_table += struct.pack('<2I', len(kb), offset + len(orig_strings))
        orig_strings += kb + b'\0'
    trans_base = offset + len(orig_strings)
    trans_table = b''; trans_strings = b''
    for kb, vb in pairs:
        trans_table += struct.pack('<2I', len(vb), trans_base + len(trans_strings))
        trans_strings += vb + b'\0'
    return header + orig_table + trans_table + orig_strings + trans_strings


def main():
    check = '--check' in sys.argv
    failures = 0
    for name in sorted(os.listdir(ES_DIR)):
        if not name.endswith('.po'):
            continue
        po = os.path.join(ES_DIR, name)
        mo = po[:-3] + '.mo'
        binary = compile_mo(parse_po(po))
        if check:
            with open(mo, 'rb') as f:
                shipped = f.read()
            if shipped == binary:
                print('OK      %s → %s (%d entrées, octets identiques)' % (name, os.path.basename(mo), binary and struct.unpack('<I', binary[8:12])[0]))
            else:
                print('ÉCART   %s : recompiler' % mo)
                failures += 1
        else:
            with open(mo, 'wb') as f:
                f.write(binary)
            print('COMPILÉ %s → %s (%d entrées)' % (name, os.path.basename(mo), struct.unpack('<I', binary[8:12])[0]))
    sys.exit(1 if failures else 0)


if __name__ == '__main__':
    main()
