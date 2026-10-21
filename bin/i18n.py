"""Cập nhật bản dịch: languages/smart-content-seo.pot -> smart-content-seo-vi.po (giữ bản dịch cũ), rồi tạo .mo và .json cho JavaScript.

Chạy sau khi tạo lại .pot (bin/build.sh làm việc này). Cần: pip install polib
Chuỗi mới chưa có bản dịch được liệt kê; dịch trong file .po (Poedit hoặc sửa tay) rồi chạy lại.
"""
import hashlib
import json
import os

import polib

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LANG = os.path.join(ROOT, 'languages')
DOMAIN = 'smart-content-seo'

pot = polib.pofile(os.path.join(LANG, DOMAIN + '.pot'))
po_path = os.path.join(LANG, DOMAIN + '-vi.po')
old = polib.pofile(po_path) if os.path.exists(po_path) else polib.POFile()
known = {(e.msgctxt, e.msgid): e for e in old}

po = polib.POFile()
po.metadata = {
    'Project-Id-Version': 'Smart Content SEO',
    'Language': 'vi',
    'MIME-Version': '1.0',
    'Content-Type': 'text/plain; charset=UTF-8',
    'Content-Transfer-Encoding': '8bit',
    'Plural-Forms': 'nplurals=1; plural=0;',
    'X-Domain': DOMAIN,
}
missing = []
for e in pot:
    prev = known.get((e.msgctxt, e.msgid))
    entry = polib.POEntry(msgid=e.msgid, msgctxt=e.msgctxt, occurrences=e.occurrences, comment=e.comment, flags=e.flags)
    if e.msgid_plural:
        entry.msgid_plural = e.msgid_plural
        entry.msgstr_plural = {0: prev.msgstr_plural.get(0, '') if prev and prev.msgid_plural else ''}
        done = entry.msgstr_plural[0]
    else:
        entry.msgstr = prev.msgstr if prev else ''
        done = entry.msgstr
    if not done:
        missing.append(e.msgid)
    po.append(entry)
po.save(po_path)
po.save_as_mofile(os.path.join(LANG, DOMAIN + '-vi.mo'))

# JavaScript (wp_set_script_translations): một file JSON cho mỗi file .js, tên theo md5 của đường dẫn tương đối.
for f in os.listdir(LANG):
    if f.startswith(DOMAIN + '-vi-') and f.endswith('.json'):
        os.remove(os.path.join(LANG, f))
by_file = {}
for e in po:
    for path, _line in e.occurrences:
        if path.endswith('.js'):
            by_file.setdefault(path, []).append(e)
for path, entries in sorted(by_file.items()):
    msgs = {'': {'domain': 'messages', 'lang': 'vi', 'plural-forms': 'nplurals=1; plural=0;'}}
    for e in entries:
        key = (e.msgctxt + '\u0004' if e.msgctxt else '') + e.msgid
        msgs[key] = [e.msgstr_plural[0]] if e.msgid_plural else [e.msgstr]
    data = {'source': path, 'domain': 'messages', 'locale_data': {'messages': msgs}}
    name = DOMAIN + '-vi-' + hashlib.md5(path.encode()).hexdigest() + '.json'
    with open(os.path.join(LANG, name), 'w', encoding='utf-8') as fh:
        json.dump(data, fh, ensure_ascii=False)

print(f'{len(pot)} strings, {len(pot) - len(missing)} translated, {len(by_file)} JS files')
for m in missing:
    print('UNTRANSLATED:', m)
