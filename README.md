# GoFileIo — Plugin Download Station pour Synology DSM 7

Permet à la Download Station de télécharger des liens **gofile.io** (`https://gofile.io/d/{id}`) directement, sans passer par un navigateur.

---

## Installation

1. Dans DSM, ouvrir **Download Station**
2. Paramètres › **Hébergement de fichiers** › **Ajouter**
3. Sélectionner `GoFileIo(1.0.3).host`
4. GoFile.io apparaît dans la liste — activé par défaut

---

## Utilisation

Coller un lien gofile.io dans la zone d'ajout de téléchargement :

```
https://gofile.io/d/K5YYlf
```

**Sans configuration** : fonctionne pour tous les fichiers publics (token invité automatique).

### Lien de test

```
https://gofile.io/d/QcG4Vj
```

Petit fichier texte (`test_plugin_gofileio.txt`, quelques octets) — permet de vérifier rapidement que le plugin est opérationnel sans lancer un gros téléchargement.

---

## Clé API optionnelle

gofile.io distribue les fichiers publics via un CDN qui impose un token d'authentification,
même pour les téléchargements anonymes. Sans clé API, le plugin obtient automatiquement
un **token invité** temporaire — gratuit, sans compte.

Si vous avez un compte gofile.io **Premium**, vous pouvez fournir votre clé API pour bénéficier
de vitesses supérieures et accéder à vos fichiers privés.

### Où trouver votre clé API

Sur [gofile.io](https://gofile.io), connectez-vous puis allez dans **Profile › API Token**.

### Comment la configurer

Dans DSM › Download Station › **Paramètres › Hébergement de fichiers** :

1. Cliquer sur **GoFile.io** dans la liste
2. Cliquer sur **Modifier**
3. Laisser le champ **Nom d'utilisateur** vide
4. Coller votre clé API dans le champ **Mot de passe**
5. Valider

---

## Fonctionnement interne

### Résolution du lien

Quand vous ajoutez `https://gofile.io/d/K5YYlf`, le plugin :

1. Obtient un token (invité ou votre clé API)
2. Appelle `api.gofile.io/contents/{id}` pour récupérer la liste des fichiers du dossier
3. Retourne l'URL CDN directe du premier fichier à la Download Station

### X-Website-Token

L'API gofile.io exige un en-tête `X-Website-Token` sur les appels `/contents/`. C'est une
signature anti-scraping calculée côté client :

```
sha256("{user_agent}::{lang}::{token}::{floor(time/14400)}::{salt}")
```

Le **sel** (`salt`) est une constante extraite du JavaScript de gofile.io (`wt.obf.js`).
Il change rarement mais peut évoluer lors de mises à jour du site. Valeur actuelle : `9844d94d963d30`.

> Si l'API retourne des erreurs après une mise à jour de gofile.io, mettre à jour
> la constante `GOFILE_SALT` dans `GoFileIo.php`.

### Cookie CDN

Le CDN gofile.io exige un cookie `accountToken` pour servir les fichiers — même publics.
Le plugin écrit un fichier cookie au format Netscape dans `/tmp/` et le transmet à `synodlwget`
via `DOWNLOAD_COOKIE`, le mécanisme standard de la Download Station pour l'authentification CDN.

---

## Reconstruction du .host

```bash
cd /path/to/host/
python3 build.py
# → GoFileIo(1.0.3).host
```

Le script `build.py` produit une archive tar.gz avec les permissions `0o755` — indispensable
pour que l'API DSM puisse lire les fichiers extraits lors de l'installation via l'UI.

---

## Fichiers

| Fichier | Rôle |
|---|---|
| `GoFileIo.php` | Module PHP — logique de résolution et téléchargement |
| `INFO` | Métadonnées du plugin (nom, version, classe PHP) |
| `build.py` | Script de packaging en `.host` |
| `upload_test.py` | Upload un fichier de test sur gofile.io (voir lien de test ci-dessus) |
| `GoFileIo(1.0.3).host` | Archive installable dans DSM |
