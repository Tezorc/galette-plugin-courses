#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Fabrique un PDF imprimable a partir d'une page HTML du plugin.

Usage :
    python scripts/build-pdf.py doc/tuto-adherent.html
    python scripts/build-pdf.py doc/tuto-adherent.html -o /tmp/guide.pdf
    python scripts/build-pdf.py doc/tuto-adherent.html --keep-html

Ce que fait le script, et pourquoi :

1. **Il embarque les polices en base64** avant d'imprimer. Une page qui
   charge ses polices depuis fonts.googleapis.com dépend d'une requête
   réseau au moment exact du rendu ; en les inlinant, le PDF est
   reproductible, y compris hors ligne. Seuls les sous-ensembles latin et
   latin-ext sont retenus : le français n'a pas besoin du cyrillique, et
   cela divise le poids par cinq.

2. **Il imprime via Chrome en mode headless**, qui applique la feuille de
   style `@media print` de la page (fond blanc, couleurs des figures
   conservées, figures jamais coupées entre deux pages).

3. **Il vérifie le résultat**, et c'est le point important. Un PDF dont les
   polices ne se sont pas embarquées s'ouvre normalement et paraît correct
   à l'écran : rien ne signale le problème. Le script relit donc le PDF
   produit et affiche les familles réellement embarquées, le nombre de
   pages et le poids. Si une famille attendue manque, il le dit et sort en
   erreur.

Prérequis : Google Chrome (ou Chromium/Edge) installé, et un accès réseau
au premier lancement pour télécharger les polices — ensuite elles sont mises
en cache dans `.fonts-cache/` a cote du script.

Aucune dependance Python externe.
"""

from __future__ import annotations

import argparse
import base64
import os
import re
import shutil
import subprocess
import sys
import tempfile
import urllib.request
import zlib

HERE = os.path.dirname(os.path.abspath(__file__))
CACHE = os.path.join(HERE, '.fonts-cache')

# Sous-ensembles Google Fonts conserves. Ajouter 'greek', 'cyrillic'... si
# une traduction en a besoin.
KEEP_SUBSETS = ('latin', 'latin-ext')

CHROME_CANDIDATES = [
    r'C:\Program Files\Google\Chrome\Application\chrome.exe',
    r'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
    r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
]

UA = ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
      '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')


def find_chrome(explicit: str | None) -> str:
    if explicit:
        if not os.path.exists(explicit):
            sys.exit('Chrome introuvable a : %s' % explicit)
        return explicit
    for path in CHROME_CANDIDATES:
        if os.path.exists(path):
            return path
    found = shutil.which('google-chrome') or shutil.which('chromium') or shutil.which('chrome')
    if found:
        return found
    sys.exit('Chrome introuvable. Passer son chemin avec --chrome.')


def fetch(url: str) -> bytes:
    req = urllib.request.Request(url, headers={'User-Agent': UA})
    with urllib.request.urlopen(req, timeout=60) as resp:
        return resp.read()


def inline_fonts(html: str) -> tuple[str, int]:
    """Remplace le <link> Google Fonts par des @font-face en base64."""
    link = re.search(r'<link[^>]+href="(https://fonts\.googleapis\.com/css2[^"]+)"[^>]*>', html)
    if not link:
        return html, 0

    css = fetch(link.group(1)).decode('utf-8')
    if not os.path.isdir(CACHE):
        os.makedirs(CACHE)

    blocks = re.findall(r'(?:/\*\s*([a-z-]+)\s*\*/\s*)?@font-face\s*\{(.*?)\}', css, re.S)
    faces = []
    count = 0
    for subset, body in blocks:
        if subset and subset not in KEEP_SUBSETS:
            continue
        url_match = re.search(r'url\((https://fonts\.gstatic\.com/[^)]+)\)', body)
        if not url_match:
            continue
        name = url_match.group(1).rsplit('/', 1)[-1]
        path = os.path.join(CACHE, name)
        if not os.path.exists(path):
            with open(path, 'wb') as fh:
                fh.write(fetch(url_match.group(1)))
        with open(path, 'rb') as fh:
            raw = fh.read()
        b64 = base64.b64encode(raw).decode('ascii')
        body = body.replace(
            url_match.group(0),
            "url(data:font/woff2;base64,%s) format('woff2')" % b64)
        body = re.sub(r"\s*format\('woff2'\)\s*format\('woff2'\)", " format('woff2')", body)
        faces.append('@font-face {%s}' % body)
        count += 1

    html = re.sub(r'<link rel="preconnect"[^>]*>\s*', '', html)
    html = re.sub(r'<link[^>]+href="https://fonts\.googleapis\.com[^"]*"[^>]*>\s*', '', html)
    html = html.replace(
        '<style>',
        '<style>\n/* Polices embarquees par scripts/build-pdf.py : aucune requete au rendu. */\n'
        + '\n'.join(faces) + '\n',
        1)
    return html, count


def wrap_if_fragment(html: str) -> str:
    """Les pages d'artefact sont publiees sans <html>/<head>/<body> : ce
    squelette est ajoute au moment de la publication. Pour une impression
    locale il faut le remettre, charset compris — sans quoi les accents
    sortent en mojibake.

    Le fragment commence par ses <title>/<style> puis bascule sur le
    contenu : on coupe au premier <svg ou <div de premier niveau pour
    placer proprement l'un dans <head> et l'autre dans <body>."""
    if re.search(r'<html[\s>]', html, re.I):
        return html

    split = re.search(r'^\s*<(?:svg|div|header|main|section|article)[\s>]', html, re.M)
    head, body = (html[:split.start()], html[split.start():]) if split else ('', html)
    if '<title' not in head:
        head = '<title>Document</title>\n' + head

    return (
        '<!doctype html>\n<html lang="fr">\n<head>\n'
        '<meta charset="utf-8">\n'
        '<meta name="viewport" content="width=device-width, initial-scale=1">\n'
        '<style>body{margin:0}img{max-width:100%}[hidden]{display:none!important}</style>\n'
        + head.rstrip() + '\n</head>\n<body>\n'
        + body.rstrip() + '\n</body>\n</html>\n'
    )


def pdf_report(path: str) -> dict:
    """Relit le PDF produit. Les descripteurs de police vivent dans des flux
    compresses : les chercher dans le fichier brut ne montre qu'une partie
    de la verite, et fait conclure a tort que des polices manquent."""
    with open(path, 'rb') as fh:
        data = fh.read()
    chunks = [data]
    for match in re.finditer(rb'stream\r?\n', data):
        start = match.end()
        end = data.find(b'endstream', start)
        if end == -1:
            continue
        try:
            chunks.append(zlib.decompress(data[start:end]))
        except zlib.error:
            pass
    blob = b'\n'.join(chunks)

    names = set(re.findall(rb'/FontName\s*/([A-Za-z0-9+\-]+)', blob))
    families = set()
    for name in names:
        text = name.decode('latin-1')
        families.add(text.split('+')[-1].split('-')[0])
    return {
        'pages': len(re.findall(rb'/Type\s*/Page[^s]', blob)),
        'fonts': len(names),
        'families': sorted(families),
        'bytes': len(data),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('source', help='page HTML a convertir')
    parser.add_argument('-o', '--output', help='PDF de sortie (defaut : meme nom, extension .pdf)')
    parser.add_argument('--chrome', help='chemin de Chrome, si la detection echoue')
    parser.add_argument('--keep-html', action='store_true',
                        help='conserver le HTML intermediaire (polices inlinees) a cote du PDF')
    parser.add_argument('--expect', default='',
                        help='familles de polices attendues, separees par des virgules '
                             '(sortie en erreur si l\'une manque)')
    args = parser.parse_args()

    if not os.path.exists(args.source):
        sys.exit('Fichier introuvable : %s' % args.source)

    output = args.output or os.path.splitext(args.source)[0] + '.pdf'
    output = os.path.abspath(output)
    chrome = find_chrome(args.chrome)

    with open(args.source, encoding='utf-8') as fh:
        html = fh.read()

    html, n_faces = inline_fonts(html)
    html = wrap_if_fragment(html)
    print('polices embarquees dans le HTML : %d' % n_faces)

    if args.keep_html:
        tmp_path = os.path.splitext(output)[0] + '.print.html'
    else:
        handle, tmp_path = tempfile.mkstemp(suffix='.html')
        os.close(handle)
    with open(tmp_path, 'w', encoding='utf-8') as fh:
        fh.write(html)

    cmd = [
        chrome,
        '--headless=new',
        '--disable-gpu',
        '--no-pdf-header-footer',
        '--print-to-pdf=' + output,
        '--virtual-time-budget=20000',
        'file:///' + tmp_path.replace('\\', '/'),
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    if not args.keep_html:
        os.unlink(tmp_path)
    if not os.path.exists(output):
        sys.stderr.write(result.stderr or '')
        sys.exit('Chrome n\'a produit aucun PDF.')

    report = pdf_report(output)
    print('PDF     : %s' % output)
    print('pages   : %d' % report['pages'])
    print('poids   : %d octets' % report['bytes'])
    print('polices : %d sous-ensembles — %s' % (report['fonts'], ', '.join(report['families'])))

    if args.expect:
        expected = [x.strip() for x in args.expect.split(',') if x.strip()]
        missing = [x for x in expected if x not in report['families']]
        if missing:
            sys.exit('POLICES MANQUANTES : %s — le PDF sortirait dans une police de repli.'
                     % ', '.join(missing))
        print('controle: les %d familles attendues sont embarquees.' % len(expected))

    return 0


if __name__ == '__main__':
    sys.exit(main())
