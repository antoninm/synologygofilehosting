<?php
/*
    @author : Generated for Synology Download Station
    @Version : 1.0.3
    @description : Support de gofile.io (fichiers publics, token invité automatique)

    Installation :
        tar -czvf "GoFileIo(1.0.3).host" INFO GoFileIo.php
    Puis déposer le .host dans :
        /var/packages/DownloadStation/etc/download/userhosts

    Configuration :
    - Laisser username et password vides pour les fichiers publics (token invité auto)
    - Optionnel : entrer votre clé API gofile.io comme mot de passe

    Note sur le X-Website-Token :
    - Sel courant : "9844d94d963d30" (extrait de gofile.io/dist/js/wt.obf.js)
    - Si l'API retourne des erreurs 401, mettre à jour la constante GOFILE_SALT

    Note sur le téléchargement :
    - gofile.io requiert le cookie "accountToken" côté CDN
    - GetDownloadInfo() écrit un fichier cookie Netscape dans /tmp/ et retourne
      son chemin via DOWNLOAD_COOKIE pour que synodlwget l'utilise automatiquement
*/

class GoFileIoHosting {

    private $Url;
    private $apikey;
    private $token;

    public function __construct($Url, $Username, $password, $HostInfo) {
        $this->Url    = $Url;
        $this->token  = null;
        // Accepte la clé API en mot de passe ou en username
        if (!empty($password) && strlen(trim($password)) > 8) {
            $this->apikey = trim($password);
        } elseif (!empty($Username) && strlen(trim($Username)) > 8) {
            $this->apikey = trim($Username);
        } else {
            $this->apikey = null;
        }
    }

    // -------------------------------------------------------------------------
    // API publique (appelée par Download Station)
    // -------------------------------------------------------------------------

    public function GetDownloadInfo() {
        $contentId = $this->extractContentId($this->Url);
        if (!$contentId) {
            $ret = array();
            $ret[DOWNLOAD_ERROR] = ERR_BROKEN_LINK;
            return $ret;
        }

        $token = $this->resolveToken();
        if (!$token) {
            $ret = array();
            $ret[DOWNLOAD_ERROR] = ERR_BROKEN_LINK;
            return $ret;
        }

        $file = $this->fetchFirstFile($token, $contentId);
        if (!$file) {
            $ret = array();
            $ret[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
            return $ret;
        }

        $link = $this->resolveLink($file);
        if (!$link) {
            $ret = array();
            $ret[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
            return $ret;
        }

        $filename = isset($file['name']) ? $file['name'] : 'download';

        // Écrire le fichier cookie Netscape pour que synodlwget s'authentifie
        // auprès du CDN gofile.io (Cookie: accountToken=...)
        $cookiePath = $this->writeCookieFile($token);

        $ret = array();
        $ret[INFO_NAME]                   = $filename;
        $ret[DOWNLOAD_FILENAME]           = $filename;
        $ret[DOWNLOAD_ISPARALLELDOWNLOAD] = true;
        $ret[DOWNLOAD_URL]                = $link;
        if ($cookiePath) {
            $ret[DOWNLOAD_COOKIE] = $cookiePath;
        }
        return $ret;
    }

    public function Verify($ClearCookie) {
        if (!empty($this->apikey)) {
            $token = $this->resolveToken();
            return $token ? USER_IS_PREMIUM : LOGIN_FAIL;
        }
        return USER_IS_FREE;
    }

    // -------------------------------------------------------------------------
    // Méthodes privées
    // -------------------------------------------------------------------------

    private function extractContentId($url) {
        if (preg_match('#gofile\.io/d/([a-zA-Z0-9]+)#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    private function resolveToken() {
        if ($this->token) {
            return $this->token;
        }
        if ($this->apikey) {
            $this->token = $this->apikey;
            return $this->token;
        }
        // Obtenir un token invité via POST /accounts
        $headers = array(
            'Content-Type: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
        );
        $response = $this->httpPost('https://api.gofile.io/accounts', '{}', $headers);
        if (!$response) {
            return null;
        }
        $data = json_decode($response, true);
        if (!$data || $data['status'] !== 'ok' || empty($data['data']['token'])) {
            return null;
        }
        $this->token = $data['data']['token'];
        return $this->token;
    }

    private function createWebToken($token) {
        // Algorithme depuis gofile.io/dist/js/wt.obf.js
        // sha256("{user_agent}::{lang}::{token}::{floor(time/14400)}::{salt}")
        $ua        = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        $lang      = 'en-US';
        $salt      = '9844d94d963d30';
        $tick      = intval(time() / 14400);
        $raw       = $ua . '::' . $lang . '::' . $token . '::' . $tick . '::' . $salt;
        return hash('sha256', $raw);
    }

    private function fetchFirstFile($token, $contentId) {
        $ua  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        $url = 'https://api.gofile.io/contents/' . $contentId
             . '?contentFilter=&sortField=name&sortDirection=1&pageSize=1000&page=1';

        $headers = array(
            'Authorization: Bearer ' . $token,
            'X-BL: en-US',
            'X-Website-Token: ' . $this->createWebToken($token),
            'User-Agent: ' . $ua,
            'Origin: https://gofile.io',
            'Referer: https://gofile.io/'
        );

        $response = $this->httpGet($url, $headers);
        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || $data['status'] !== 'ok') {
            return null;
        }

        $node = isset($data['data']) ? $data['data'] : null;
        if (!$node || empty($node['canAccess'])) {
            return null;
        }

        if ($node['type'] === 'file') {
            return $node;
        }

        if ($node['type'] === 'folder' && isset($node['children'])) {
            foreach ($node['children'] as $child) {
                if (isset($child['type']) && $child['type'] === 'file' && !empty($child['canAccess'])) {
                    return $child;
                }
            }
        }

        return null;
    }

    private function writeCookieFile($token) {
        // Format Netscape cookie file requis par wget/synodlwget (--load-cookies)
        $cookieFile = '/tmp/gofile_' . substr(md5($token), 0, 12) . '.txt';
        $content  = "# Netscape HTTP Cookie File\n";
        $content .= "# gofile.io CDN auth\n";
        $content .= ".gofile.io\tTRUE\t/\tFALSE\t0\taccountToken\t" . $token . "\n";
        if (@file_put_contents($cookieFile, $content) !== false) {
            return $cookieFile;
        }
        return null;
    }

    private function resolveLink($file) {
        $link = isset($file['link']) ? $file['link'] : '';
        if (empty($link) || $link === 'overloaded') {
            if (!empty($file['directLink'])) {
                return $file['directLink'];
            }
            return null;
        }
        return $link;
    }

    // -------------------------------------------------------------------------
    // Helpers HTTP (syntaxe compatible PHP 5.3+)
    // -------------------------------------------------------------------------

    private function httpGet($url, $headers) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response ? $response : null;
    }

    private function httpPost($url, $body, $headers) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response ? $response : null;
    }
}
