<?php

namespace Steam;

use Curl\Curl;
use Adbar\Dot;
use phpseclib\Crypt\RSA;
use phpseclib\Math\BigInteger;
use Container\Container;
use League\CLImate\CLImate;

class Client extends Container
{
    private $config = [];

    /**
     * @var Curl
     */
    private $curl;

    private $username;
    private $password;
    private $sessionDir;

    private $steamId;
    private $sessionId;

    private $requiresCaptcha = false;
    private $captchaGID;
    private $captchaText;

    private $requiresEmail = false;
    private $emailCode;

    private $requires2FA = false;
    private $twoFactorCode;

    public const CANT_TRADE = 99;
    public const CAN_TRADE = 100;
    public const GUARD_7_DAYS_BAN = 101;

    /**
     * @var CLImate
     */
    private $cli;

    public function __construct($config = ['2'])
    {
        $this->config = new Dot($this->config);
        $this->config->merge($config);

        $this->username = $this->config->get('username');
        $this->password = $this->config->get('password');
        $this->steamId = $this->config->get('steamId');
        $this->sessionDir = $this->config->get('sessionDir');

        $this->cli = new CLImate;
    }

    public function request()
    {
        $curl = new Curl();
        $curl->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.88 Safari/537.36');
        $curl->setReferrer('https://steamcommunity.com/');
        $curl->setOpt(CURLOPT_FOLLOWLOCATION, true);
        $curl->setCookieFile($this->getCookieFile());
        $curl->setCookieJar($this->getCookieFile());
        $curl->setJsonDecoder(function ($response) {
            return new Dot(json_decode($response, true));
        });

        return $curl;
    }

    public function config()
    {
        return $this->config;
    }

    public function cli()
    {
        return $this->cli;
    }

    public function auth()
    {
        $response = $this->request()->post('https://steamcommunity.com/login/getrsakey', ['username' => $this->username]);

        if ($response == null) {
            return ['code' => Auth::FAIL, 'response' => $response];
        }

        if (!$response->get('success')) {
            return ['code' => Auth::BAD_RSA, 'response' => $response];
        }

        $rsa = new RSA();
        $rsa->setEncryptionMode(RSA::ENCRYPTION_PKCS1);
        $key = [
            'modulus' => new BigInteger($response->get('publickey_mod'), 16),
            'publicExponent' => new BigInteger($response->get('publickey_exp'), 16)
        ];
        $rsa->loadKey($key, RSA::PUBLIC_FORMAT_RAW);
        $encryptedPassword = base64_encode($rsa->encrypt($this->password));

        $params = [
            'username' => $this->username,
            'password' => $encryptedPassword,
            'twofactorcode' => is_null($this->twoFactorCode) ? '' : $this->twoFactorCode,
            'captchagid' => $this->requiresCaptcha ? $this->captchaGID : '-1',
            'captcha_text' => $this->requiresCaptcha ? $this->captchaText : '',
            'emailsteamid' => ($this->requires2FA || $this->requiresEmail) ? (string) $this->steamId : '',
            'emailauth' => $this->requiresEmail ? $this->emailCode : '',
            'rsatimestamp' => $response->get('timestamp'),
            'remember_login' => 'false',
            'l' => 'english',
        ];

        $response = $this->request()->post('https://steamcommunity.com/login/dologin/', $params);

        if ($response == null) {
            return ['code' => Auth::FAIL, 'response' => $response];
        }

        if ($response->has('captcha_needed')) {
            $this->requiresCaptcha = true;
            $this->captchaGID = $response->get('captcha_gid');
            return ['code' => Auth::CAPTCHA, 'response' => $response];
        }

        if ($response->has('emailauth_needed')) {
            $this->requiresEmail = true;
            $this->steamId = $response->get('emailsteamid');
            return ['code' => Auth::EMAIL, 'response' => $response];
        }

        if ($response->has('requires_twofactor') && !$response->get('success')) {
            $this->requires2FA = true;
            return ['code' => Auth::TWO_FACTOR, 'response' => $response];
        }

        if ($response->has('login_complete') && !$response->get('login_complete')) {
            return ['code' => Auth::BAD_CREDENTIALS, 'response' => $response];
        }

        if ($response->has('message') && stripos($response->get('message'), 'account name or password that you have entered is incorrect') !== false) {
            return ['code' => Auth::BAD_CREDENTIALS, 'response' => $response];
        }

        if ($response->get('success')) {
            if ($response->has('oauth')) {
                file_put_contents($this->getAuthFile(), $response->get('oauth'));
            }
        }

        $this->initSession();

        return ['code' => Auth::SUCCESS, 'response' => $response];
    }

    public function getBalance()
    {
        $response = $this->request()->get('https://steamcommunity.com/market/', ['l' => 'english']);
        
        $pattern = '/<span id="marketWalletBalanceAmount">(.+?)<\/span>/';
        preg_match_all($pattern, $response, $matches);

        if (empty($matches[1]) || sizeof($matches[1]) == 0) {
            return false;
        }

        $rawBalance = trim($matches[1][0]);
        $rawBalance = str_ireplace(',', '.', $rawBalance);
        $cleanBalance = (float) filter_var($rawBalance, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

        return [
            'raw' => $rawBalance,
            'balance' => $cleanBalance,
        ];
    }

    public function isLoggedIn()
    {
        $response = $this->request()->get('https://steamcommunity.com/market/', ['l' => 'english']);
        return stripos($response, 'wallet balance') !== false;
    }

    private function getAuthFile()
    {
        return rtrim($this->sessionDir, '\/') . "/{$this->username}.auth";
    }

    private function getCookieFile()
    {
        return rtrim($this->sessionDir, '\/') . "/{$this->username}.cookie";
    }

    /**
     * Use this to get the captcha image.
     * @return string
     */
    public function getCaptchaLink()
    {
        return 'https://steamcommunity.com/public/captcha.php?gid=' . $this->captchaGID;
    }

    private function initSession()
    {
        $response = $this->request()->get('https://steamcommunity.com/', ['l' => 'english']);
   
        $pattern = '/g_steamID = (.*);/';
        preg_match($pattern, $response, $matches);
        if (!isset($matches[1])) {
            throw new \Exception('Unexpected response from Steam #1.');
        }

        // set steamId
        $steamId = str_replace('"', '', $matches[1]);
        if ($steamId == 'false') {
            $steamId = 0;
        }
        $this->setSteamId($steamId);

        // set sessionId
        $pattern = '/g_sessionID = (.*);/';
        preg_match($pattern, $response, $matches);
        if (!isset($matches[1])) {
            throw new \Exception('Unexpected response from Steam #2.');
        }
        $sessionId = str_replace('"', '', $matches[1]);
        $this->setSessionId($sessionId);
    }

    /**
     * Set this after a captcha is encountered when logging in or creating an account.
     * @param string $captchaText
     */
    public function setCaptchaText($captchaText)
    {
        $this->captchaText = $captchaText;
    }

    /**
     * Set this after email auth is required when logging in.
     * @param string $emailCode
     */
    public function setEmailCode($emailCode)
    {
        $this->emailCode = $emailCode;
    }

    /**
     * Set this after 2FA is required when logging in.
     * @param string $twoFactorCode
     */
    public function setTwoFactorCode($twoFactorCode)
    {
        $this->twoFactorCode = $twoFactorCode;
    }

    public function setUsername($username)
    {
        $this->username = $username;
    }

    public function setPassword($password)
    {
        $this->password = $password;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function getSessionId()
    {
        return $this->sessionId;
    }

    public function setSessionId($sessionId)
    {
        return $this->sessionId = $sessionId;
    }

    public function getSteamId()
    {
        return $this->steamId;
    }

    public function setSteamId($steamId)
    {
        return $this->steamId = $steamId;
    }
}
