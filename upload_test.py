#!/usr/bin/env python3
"""
upload_test.py — Crée un fichier texte de test et l'uploade sur gofile.io.
Le dossier est immédiatement mis en privé (non public) pour tester
l'authentification du plugin GoFileIo avec une clé API.

Usage :
    python3 upload_test.py                      # token invité (fichier accessible mais privé)
    python3 upload_test.py --apikey <VOTRE_CLE> # compte premium / accès privé

Le lien retourné peut être utilisé directement dans Download Station.
Pour les dossiers privés, configurer la clé API dans DS > Paramètres > Hébergement de fichiers > GoFile.io
"""

import sys, os, json, hashlib, time, ssl, urllib.request, urllib.parse, argparse

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode    = ssl.CERT_NONE

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'


def api(url, token=None, data=None, json_body=None, multipart=None):
    headers = {'User-Agent': UA}
    if token:
        headers['Authorization'] = f'Bearer {token}'
    if json_body is not None:
        headers['Content-Type'] = 'application/json'
        data = json.dumps(json_body).encode()
    req = urllib.request.Request(url, data=data, headers=headers)
    if multipart:
        boundary, body = multipart
        headers['Content-Type'] = f'multipart/form-data; boundary={boundary}'
        req = urllib.request.Request(url, data=body, headers=headers)
    with urllib.request.urlopen(req, context=ctx, timeout=30) as r:
        return json.loads(r.read())


def upload(token, filepath):
    # Serveur d'upload
    srv = api('https://api.gofile.io/servers', token=token)
    server = srv['data']['servers'][0]['name']

    filename = os.path.basename(filepath)
    with open(filepath, 'rb') as f:
        content = f.read()

    boundary = '----GoFilePy7MA4YWxkTrZu0gW'
    body = (
        f'--{boundary}\r\n'
        f'Content-Disposition: form-data; name="token"\r\n\r\n{token}\r\n'
        f'--{boundary}\r\n'
        f'Content-Disposition: form-data; name="file"; filename="{filename}"\r\n'
        f'Content-Type: text/plain\r\n\r\n'
    ).encode() + content + f'\r\n--{boundary}--\r\n'.encode()

    print(f'Upload vers {server}.gofile.io ...')
    res = api(
        f'https://{server}.gofile.io/contents/uploadfile',
        token=token,
        multipart=(boundary, body)
    )
    if res.get('status') != 'ok':
        raise RuntimeError(f'Upload failed: {res}')
    return res['data']


def set_private(token, folder_id):
    """Rend le dossier privé (non accessible sans token)."""
    url = f'https://api.gofile.io/contents/{folder_id}/update'
    boundary = '----GoFilePyUpdate'
    body = (
        f'--{boundary}\r\n'
        f'Content-Disposition: form-data; name="attribute"\r\n\r\npublic\r\n'
        f'--{boundary}\r\n'
        f'Content-Disposition: form-data; name="attributeValue"\r\n\r\nfalse\r\n'
        f'--{boundary}--\r\n'
    ).encode()
    headers = {'User-Agent': UA, 'Authorization': f'Bearer {token}',
               'Content-Type': f'multipart/form-data; boundary={boundary}'}
    req = urllib.request.Request(url, data=body, headers=headers, method='PUT')
    try:
        with urllib.request.urlopen(req, context=ctx, timeout=30) as r:
            res = json.loads(r.read())
        return res.get('status') == 'ok'
    except Exception as e:
        print(f'  (set_private: {e})')
        return False


def get_folder_code(token, folder_id):
    """Récupère le code gofile (utilisé dans gofile.io/d/{code})."""
    import hashlib
    salt = '9844d94d963d30'
    tick = int(time.time() // 14400)
    wt = hashlib.sha256(f'{UA}::en-US::{token}::{tick}::{salt}'.encode()).hexdigest()
    url = f'https://api.gofile.io/contents/{folder_id}?page=1'
    headers = {'User-Agent': UA, 'Authorization': f'Bearer {token}',
               'X-BL': 'en-US', 'X-Website-Token': wt,
               'Origin': 'https://gofile.io', 'Referer': 'https://gofile.io/'}
    req = urllib.request.Request(url, headers=headers)
    try:
        with urllib.request.urlopen(req, context=ctx, timeout=30) as r:
            res = json.loads(r.read())
        return res['data'].get('code') or res['data'].get('id')
    except Exception as e:
        print(f'  (get_folder_code: {e})')
        return folder_id


def main():
    parser = argparse.ArgumentParser(description='Upload un fichier de test sur gofile.io')
    parser.add_argument('--apikey', help='Clé API gofile.io (optionnel)')
    parser.add_argument('--file',   help='Fichier à uploader (défaut : crée test_plugin.txt)')
    args = parser.parse_args()

    # --- Token ---
    if args.apikey:
        token = args.apikey.strip()
        print(f'Utilisation de la clé API fournie.')
    else:
        print('Obtention d\'un token invité ...')
        res = api('https://api.gofile.io/accounts', data=b'{}',
                  token=None)
        token = res['data']['token']
        print(f'Token invité : {token[:20]}...')

    # --- Fichier de test ---
    if args.file:
        filepath = args.file
    else:
        filepath = '/tmp/test_plugin_gofileio.txt'
        with open(filepath, 'w') as f:
            f.write(f"Fichier de test — GoFileIo plugin v1.0.3\n")
            f.write(f"Date     : {time.strftime('%Y-%m-%d %H:%M:%S')}\n")
            f.write(f"Plugin   : GoFileIo pour Synology Download Station\n")
            f.write(f"Objectif : Vérifier que le téléchargement s'effectue correctement\n")
            f.write(f"          via le mécanisme DOWNLOAD_COOKIE (accountToken).\n")
        print(f'Fichier de test créé : {filepath}')

    # --- Upload ---
    data = upload(token, filepath)
    folder_id   = data.get('parentFolder') or data.get('folderId')
    content_id  = data.get('id')
    parent_code = data.get('parentFolderCode') or folder_id

    print(f'\nUpload OK')
    print(f'  Dossier ID : {folder_id}')
    print(f'  Fichier ID : {content_id}')

    # --- Rendre privé ---
    if folder_id:
        ok = set_private(token, folder_id)
        if ok:
            print(f'  Dossier mis en privé ✓')
        else:
            print(f'  Impossible de rendre privé (token invité sans compte ?)')

    # --- Lien ---
    code = data.get('parentFolderCode') or get_folder_code(token, folder_id)
    link = f'https://gofile.io/d/{code}'

    print(f'\n{"="*55}')
    print(f'Lien de téléchargement : {link}')
    print(f'Token (à configurer dans DS si dossier privé) : {token}')
    print(f'{"="*55}')
    print(f'\nDans Download Station :')
    print(f'  1. Paramètres › Hébergement de fichiers › GoFile.io › Modifier')
    print(f'     Mot de passe = {token}')
    print(f'  2. Ajouter le lien : {link}')


if __name__ == '__main__':
    main()
