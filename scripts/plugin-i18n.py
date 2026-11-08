#!/usr/bin/env python3
"""
Translations for the WordPress plugin, without gettext tools on the box.

  plugin-i18n.py extract   write languages/haman-ai-chatbot.pot from the PHP sources
  plugin-i18n.py compile   compile every languages/*.po to its .mo
  plugin-i18n.py check     fail if a source string is missing/untranslated in a .po,
                           a .po entry is stale, placeholders differ, or a .mo is
                           not what compiling its .po produces

Only the text domain 'haman-ai-chatbot' is collected. Strings are single-quoted
PHP literals (the codebase convention); a call whose first argument is not a
plain literal is reported, not silently skipped.
"""
import re
import struct
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PLUGIN = ROOT / 'wordpress-plugin' / 'haman-ai-chatbot'
LANG = PLUGIN / 'languages'
DOMAIN = 'haman-ai-chatbot'

FUNCS = r'(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)'
LIT = r"'((?:[^'\\]|\\.)*)'"
CALL = re.compile(FUNCS + r"\(\s*" + LIT + r"\s*,\s*'" + re.escape(DOMAIN) + r"'\s*\)")
ANY_CALL = re.compile(r"(?<![\w>:$])" + FUNCS + r"\((?!\))")  # "__()" in prose is not a call
PLACEHOLDER = re.compile(r'%(?:\d+\$)?[sd]')


def php_unescape(s: str) -> str:
    return s.replace("\\'", "'").replace('\\\\', '\\')


def extract() -> dict:
    """msgid -> list of 'file:line' references."""
    found, problems = {}, []
    for path in sorted(PLUGIN.rglob('*.php')):
        if 'node_modules' in path.parts or 'tests' in path.parts:
            continue
        text = path.read_text(encoding='utf-8')
        rel = path.relative_to(PLUGIN).as_posix()
        ok_spans = []
        for m in CALL.finditer(text):
            line = text.count('\n', 0, m.start()) + 1
            found.setdefault(php_unescape(m.group(1)), []).append(f'{rel}:{line}')
            ok_spans.append(m.start())
        for m in ANY_CALL.finditer(text):
            if m.start() not in ok_spans:
                line = text.count('\n', 0, m.start()) + 1
                snippet = text[m.start():m.start() + 70].split('\n')[0]
                problems.append(f'{rel}:{line}: not extractable: {snippet}')
    if problems:
        sys.exit('\n'.join(problems))
    return found


def po_quote(s: str) -> str:
    return '"' + s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n') + '"'


def write_pot(found: dict) -> None:
    out = [
        'msgid ""', 'msgstr ""',
        '"Project-Id-Version: Haman AI Chatbot\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"X-Domain: haman-ai-chatbot\\n"', '',
    ]
    for msgid in sorted(found):
        out.append('#: ' + ' '.join(found[msgid][:4]))
        out += ['msgid ' + po_quote(msgid), 'msgstr ""', '']
    LANG.mkdir(exist_ok=True)
    (LANG / f'{DOMAIN}.pot').write_text('\n'.join(out), encoding='utf-8', newline='\n')


def parse_po(path: Path) -> tuple:
    """(header, {msgid: msgstr}) — single-form entries only (the plugin has no plurals)."""
    entries, cur, key = {}, {}, None

    def unq(s):
        s = s.strip()[1:-1]
        return re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t'}.get(m.group(1), m.group(1)), s)

    def flush():
        if 'msgid' in cur:
            entries[cur['msgid']] = cur.get('msgstr', '')

    for raw in path.read_text(encoding='utf-8').splitlines() + ['']:
        line = raw.strip()
        if not line:
            flush(); cur, key = {}, None
        elif line.startswith('#'):
            continue
        elif line.startswith('msgid '):
            flush(); cur, key = {'msgid': unq(line[6:])}, 'msgid'
        elif line.startswith('msgstr '):
            cur['msgstr'], key = unq(line[7:]), 'msgstr'
        elif line.startswith('"') and key:
            cur[key] += unq(line)
    header = entries.pop('', '')
    return header, entries


def mo_bytes(header: str, entries: dict) -> bytes:
    """GNU .mo, the layout WordPress's MO reader expects (little-endian, sorted)."""
    items = sorted([('', header)] + [(k, v) for k, v in entries.items() if v])
    ids = b''.join(k.encode() + b'\0' for k, _ in items)
    strs = b''.join(v.encode() + b'\0' for _, v in items)
    n = len(items)
    o_ids, o_strs = 28, 28 + n * 8
    data_start = 28 + n * 16
    id_tab, str_tab, off = [], [], data_start
    for k, _ in items:
        id_tab.append((len(k.encode()), off)); off += len(k.encode()) + 1
    for _, v in items:
        str_tab.append((len(v.encode()), off)); off += len(v.encode()) + 1
    out = struct.pack('<7I', 0x950412de, 0, n, o_ids, o_strs, 0, 0)
    out += b''.join(struct.pack('<2I', *t) for t in id_tab)
    out += b''.join(struct.pack('<2I', *t) for t in str_tab)
    return out + ids + strs


def compile_all() -> None:
    for po in sorted(LANG.glob('*.po')):
        po.with_suffix('.mo').write_bytes(mo_bytes(*parse_po(po)))
        print(f'compiled {po.name}')


def check() -> None:
    found = extract()
    errors = []
    pos = sorted(LANG.glob('*.po'))
    if not pos:
        errors.append('no .po files in languages/')
    for po in pos:
        header, entries = parse_po(po)
        for msgid in found:
            if not entries.get(msgid):
                errors.append(f'{po.name}: untranslated: {msgid!r} ({found[msgid][0]})')
            elif sorted(PLACEHOLDER.findall(msgid)) != sorted(PLACEHOLDER.findall(entries[msgid])):
                errors.append(f'{po.name}: placeholders differ: {msgid!r}')
        for msgid in entries:
            if msgid not in found:
                errors.append(f'{po.name}: stale entry (no longer in the source): {msgid!r}')
        mo = po.with_suffix('.mo')
        if not mo.exists() or mo.read_bytes() != mo_bytes(header, entries):
            errors.append(f'{mo.name}: out of date — run scripts/plugin-i18n.py compile')
    if errors:
        sys.exit('\n'.join(errors))
    print(f'ok: {len(found)} strings, {len(pos)} translation(s) complete')


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    if cmd == 'extract':
        f = extract(); write_pot(f); print(f'{len(f)} strings')
    elif cmd == 'compile':
        compile_all()
    elif cmd == 'check':
        check()
    else:
        sys.exit(__doc__)
