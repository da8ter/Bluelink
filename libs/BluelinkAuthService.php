<?php

declare(strict_types=1);

class BluelinkAuthService
{
    private const TOKEN_EXPIRY_BUFFER = 60; // seconds before expiry to refresh
    private const MOBILE_USER_AGENT = 'Mozilla/5.0 (Linux; Android 4.1.1; Galaxy Nexus Build/JRO03C) AppleWebKit/535.19 (KHTML, like Gecko) Chrome/18.0.1025.166 Mobile Safari/535.19_CCS_APP_AOS';

    private string $baseUrl;
    private string $clientId;
    private string $basicToken;
    private BluelinkStampService $stampService;
    private string $appId;
    private string $pushType;
    private string $host;
    private array $authConfig;

    private string $accessToken = '';
    private string $refreshToken = '';
    private int $tokenExpiry = 0;
    private string $deviceId = '';
    private string $pin = '';
    private string $username = '';
    private string $password = '';
    private string $authMode = '';
    private string $cciAccessToken = '';
    private string $exchangeableToken = '';
    private string $exchangeableRefreshToken = '';
    private string $nonCcsToken = '';
    private string $nonCcsRefreshToken = '';
    private string $idToken = '';

    /** @var callable|null */
    private $logger = null;

    public function __construct(
        string $baseUrl,
        string $clientId,
        string $basicToken,
        BluelinkStampService $stampService,
        string $appId = '014d2225-8495-4735-812d-2616334fd15d',
        string $pushType = 'GCM',
        array $authConfig = []
    ) {
        $this->baseUrl = $baseUrl;
        $this->clientId = $clientId;
        $this->basicToken = $basicToken;
        $this->stampService = $stampService;
        $this->appId = $appId;
        $this->pushType = $pushType;
        $this->authConfig = $authConfig;
        // Derive host from baseUrl (strip scheme)
        $parsed = parse_url($baseUrl);
        $this->host = ($parsed['host'] ?? 'prd.eu-ccapi.hyundai.com') . ':' . ($parsed['port'] ?? 8080);
    }

    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }

    public function setPin(string $pin): void
    {
        $this->pin = $pin;
    }

    public function setRefreshToken(string $refreshToken): void
    {
        $this->refreshToken = trim($refreshToken);
    }

    public function setCredentials(string $username, string $password): void
    {
        $this->username = trim($username);
        $this->password = $password;
    }

    public function getAccessToken(): string
    {
        if ($this->isTokenValid()) {
            $this->log('Token valid, expires in ' . ($this->tokenExpiry - time()) . 's');
            return $this->accessToken;
        }

        $this->log('Token invalid/expired. tokenExpiry=' . $this->tokenExpiry . ' now=' . time()
            . ' hasRefreshToken=' . (!empty($this->refreshToken) ? 'yes(' . strlen($this->refreshToken) . ' chars)' : 'no')
        );

        if ($this->hasCompleteCciTokenSet()) {
            $this->log('Attempting OneApp/CCI token refresh...');
            try {
                $this->refreshCciAccessToken();
            } catch (Exception $e) {
                if (!$this->hasCredentials()) {
                    throw $e;
                }
                $this->log('CCI refresh failed; starting a new OneApp login: ' . $e->getMessage());
                $this->loginWithCredentials();
            }
            $this->log('Token refresh successful. New expiry: ' . date('H:i:s', $this->tokenExpiry));
            return $this->accessToken;
        }

        if ($this->hasCredentials()) {
            $this->log('No reusable CCI session; starting OneApp login...');
            $this->loginWithCredentials();
            $this->log('Token refresh successful. New expiry: ' . date('H:i:s', $this->tokenExpiry));
            return $this->accessToken;
        }

        if (!empty($this->refreshToken)) {
            if (!$this->isLegacyRefreshToken($this->refreshToken)) {
                throw new Exception(
                    'This is a OneApp/CCI refresh token and cannot be used alone. '
                    . 'Configure the Hyundai/Kia account email and password so the complete token set can be obtained.'
                );
            }
            $this->log('Attempting legacy OAuth token refresh...');
            $this->refreshLegacyAccessToken();
            $this->log('Token refresh successful. New expiry: ' . date('H:i:s', $this->tokenExpiry));
            return $this->accessToken;
        }

        throw new Exception('No valid authentication method available. Configure account email and password.');
    }

    public function getPin(): string
    {
        return $this->pin;
    }

    public function getDeviceId(): string
    {
        return $this->ensureDeviceId();
    }

    public function isAuthenticated(): bool
    {
        return !empty($this->accessToken) && $this->isTokenValid();
    }

    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    public function loadTokenCache(string $json): void
    {
        if (empty($json)) {
            return;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return;
        }
        $storedContext = $data['cacheContext'] ?? '';
        if ($storedContext !== '' && !hash_equals($this->currentCacheContext(), $storedContext)) {
            $this->log('Ignoring token cache because the brand or account has changed.');
            return;
        }
        $this->accessToken = $data['accessToken'] ?? '';
        $this->refreshToken = $data['refreshToken'] ?? $this->refreshToken;
        $this->tokenExpiry = $data['tokenExpiry'] ?? 0;
        $this->deviceId = $data['deviceId'] ?? '';
        $this->authMode = $data['authMode'] ?? '';
        $this->cciAccessToken = $data['cciAccessToken'] ?? '';
        $this->exchangeableToken = $data['exchangeableToken'] ?? '';
        $this->exchangeableRefreshToken = $data['exchangeableRefreshToken'] ?? '';
        $this->nonCcsToken = $data['nonCcsToken'] ?? '';
        $this->nonCcsRefreshToken = $data['nonCcsRefreshToken'] ?? '';
        $this->idToken = $data['idToken'] ?? '';
    }

    public function getTokenCacheData(): string
    {
        return json_encode([
            'cacheContext' => $this->currentCacheContext(),
            'accessToken'  => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'tokenExpiry'  => $this->tokenExpiry,
            'deviceId'     => $this->deviceId,
            'authMode'     => $this->authMode,
            'cciAccessToken' => $this->cciAccessToken,
            'exchangeableToken' => $this->exchangeableToken,
            'exchangeableRefreshToken' => $this->exchangeableRefreshToken,
            'nonCcsToken' => $this->nonCcsToken,
            'nonCcsRefreshToken' => $this->nonCcsRefreshToken,
            'idToken' => $this->idToken,
        ]);
    }

    public function getAuthHeaders(int $ccs2Support = 0): array
    {
        $stamp = $this->stampService->getStamp();
        $deviceId = $this->ensureDeviceId();

        $this->log('Building auth headers: deviceId=' . $deviceId
            . ' stamp=' . substr($stamp, 0, 16) . '...'
            . ' clientId=' . $this->clientId);

        $headers = [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'ccsp-device-id: ' . $deviceId,
            'ccsp-application-id: ' . $this->appId,
            'Stamp: ' . $stamp,
            'Content-Type: application/json',
            'Host: ' . $this->host,
            'Connection: Keep-Alive',
            'Accept-Encoding: gzip',
            'Ccuccs2protocolsupport: ' . $ccs2Support,
            'User-Agent: okhttp/3.12.0',
        ];
        return $headers;
    }

    public function testLogin(): array
    {
        try {
            $this->log('=== TestLogin START ===');
            $this->log('baseUrl=' . $this->baseUrl);
            $this->log('clientId=' . $this->clientId);
            $this->log('hasCredentials=' . ($this->hasCredentials() ? 'yes' : 'no'));
            $this->log('hasRefreshToken=' . (!empty($this->refreshToken) ? 'yes(' . strlen($this->refreshToken) . ' chars)' : 'no'));
            $this->log('authMode=' . ($this->authMode !== '' ? $this->authMode : 'none'));
            $this->log('cachedTokenExpiry=' . ($this->tokenExpiry > 0 ? date('Y-m-d H:i:s', $this->tokenExpiry) : 'none'));

            $token = $this->getAccessToken();
            $this->log('Access token obtained (length=' . strlen($token) . ')');

            // Also register device ID so it's cached for subsequent API calls
            $deviceId = $this->ensureDeviceId();

            $this->log('=== TestLogin SUCCESS === tokenLength=' . strlen($token) . ' deviceId=' . $deviceId);
            return [
                'success' => true,
                'message' => 'Login successful',
                'hasToken' => !empty($token),
                'tokenExpiry' => date('Y-m-d H:i:s', $this->tokenExpiry),
                'hasRefreshToken' => !empty($this->refreshToken),
                'deviceId' => $deviceId,
            ];
        } catch (Exception $e) {
            $this->log('=== TestLogin FAILED === ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'hasToken' => false,
            ];
        }
    }

    /**
     * Mask sensitive data for logging
     */
    public static function maskSecret(string $value, int $visibleChars = 4): string
    {
        if (strlen($value) <= $visibleChars) {
            return str_repeat('*', strlen($value));
        }
        return substr($value, 0, $visibleChars) . str_repeat('*', strlen($value) - $visibleChars);
    }

    // ── Private methods ─────────────────────────────────────────────

    private function isTokenValid(): bool
    {
        return !empty($this->accessToken) && (time() + self::TOKEN_EXPIRY_BUFFER) < $this->tokenExpiry;
    }

    private function ensureDeviceId(): string
    {
        if (!empty($this->deviceId)) {
            $this->log('Using cached deviceId: ' . $this->deviceId);
            return $this->deviceId;
        }

        $this->log('No cached deviceId, registering new device...');
        $this->deviceId = $this->registerDevice();
        $this->log('Registered new deviceId: ' . $this->deviceId);
        return $this->deviceId;
    }

    private function registerDevice(): string
    {
        $url = $this->baseUrl . '/api/v1/spa/notifications/register';
        $stamp = $this->stampService->getStamp();

        // Generate random registration ID (64 hex chars)
        $registrationId = bin2hex(random_bytes(32));
        $uuid = $this->generateUUID();

        $payload = json_encode([
            'pushRegId' => $registrationId,
            'pushType'  => $this->pushType,
            'uuid'      => $uuid,
        ]);

        $headers = [
            'ccsp-service-id: ' . $this->clientId,
            'ccsp-application-id: ' . $this->appId,
            'Stamp: ' . $stamp,
            'Content-Type: application/json;charset=UTF-8',
            'Host: ' . $this->host,
            'Connection: Keep-Alive',
            'Accept-Encoding: gzip',
            'User-Agent: okhttp/3.12.0',
        ];

        $this->log('RegisterDevice POST ' . $url);
        $this->log('RegisterDevice payload: pushRegId=' . substr($registrationId, 0, 8) . '... uuid=' . $uuid);

        $response = $this->httpPost($url, $payload, $headers);
        $statusCode = $this->parseStatusCode($response['headers'] ?? []);
        $this->log('RegisterDevice HTTP ' . $statusCode . ' bodyLength=' . strlen($response['body'] ?? ''));

        $data = json_decode($response['body'] ?? '', true);
        if (empty($data['resMsg']['deviceId'])) {
            $this->log('RegisterDevice FAILED. Response: ' . substr($response['body'] ?? '', 0, 500));
            throw new Exception('Device registration failed: ' . ($data['resMsg'] ?? 'unknown error'));
        }

        $deviceId = $data['resMsg']['deviceId'];
        $this->log('RegisterDevice OK. deviceId=' . $deviceId);
        return $deviceId;
    }

    private function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function hasCredentials(): bool
    {
        return $this->username !== '' && $this->password !== '';
    }

    private function currentCacheContext(): string
    {
        return hash('sha256', $this->clientId . '|' . strtolower($this->username));
    }

    private function isLegacyRefreshToken(string $token): bool
    {
        // The old ccapi OAuth refresh tokens are 48 characters. Current
        // OneApp/CCI refresh tokens (usually 87 characters) are not standalone.
        return strlen($token) === 48;
    }

    private function hasCompleteCciTokenSet(): bool
    {
        return $this->authMode === 'cci'
            && $this->refreshToken !== ''
            && $this->cciAccessToken !== ''
            && $this->exchangeableToken !== ''
            && $this->nonCcsToken !== '';
    }

    private function refreshLegacyAccessToken(): void
    {
        $tokenUrl = $this->baseUrl . '/api/v1/user/oauth2/token';
        $payload = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'redirect_uri'  => $this->authConfig['oauthRedirectUri']
                ?? ($this->baseUrl . '/api/v1/user/oauth2/redirect'),
        ]);

        $stamp = $this->stampService->getStamp();
        $this->log('RefreshToken POST ' . $tokenUrl);

        $response = $this->httpPost($tokenUrl, $payload, [
            'Authorization: ' . $this->basicToken,
            'Content-Type: application/x-www-form-urlencoded',
            'Host: ' . $this->host,
            'Stamp: ' . $stamp,
            'ccsp-service-id: ' . $this->clientId,
            'ccsp-application-id: ' . $this->appId,
            'Connection: Keep-Alive',
            'Accept-Encoding: gzip',
            'User-Agent: okhttp/3.12.0',
        ]);

        $statusCode = $this->parseStatusCode($response['headers'] ?? []);
        $this->log('RefreshToken response: HTTP ' . $statusCode . ' bodyLength=' . strlen($response['body'] ?? ''));

        $data = json_decode($response['body'] ?? '', true);
        if (empty($data['access_token'])) {
            $this->log('RefreshToken FAILED. Response body: ' . substr($response['body'] ?? '', 0, 500));
            $errMsg = $data['error_description'] ?? $data['error'] ?? $data['errMsg'] ?? 'unknown';
            $this->log('RefreshToken error detail: ' . $errMsg);
            $this->accessToken = '';
            $this->tokenExpiry = 0;
            throw new Exception('Token refresh failed (' . $errMsg . '). Please provide a new refresh token.');
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600);
        $this->authMode = 'legacy';
        if (!empty($data['refresh_token'])) {
            $this->refreshToken = $data['refresh_token'];
            $this->log('RefreshToken: got new refresh token (' . strlen($this->refreshToken) . ' chars)');
        }
        $this->log('RefreshToken OK. expires_in=' . ($data['expires_in'] ?? 'n/a') . ' token_type=' . ($data['token_type'] ?? 'n/a'));
    }

    private function loginWithCredentials(): void
    {
        $requiredConfig = [
            'loginFormHost', 'oneAppClientId', 'oneAppRedirectUri', 'cciApiUrl',
            'cciPackageId', 'cciClientName', 'cciClientOsVersion', 'cciNotificationProvider',
        ];
        foreach ($requiredConfig as $key) {
            if (empty($this->authConfig[$key])) {
                throw new Exception('OneApp authentication is not configured (' . $key . ' missing).');
            }
        }
        if (!function_exists('curl_init')) {
            throw new Exception('The PHP cURL extension is required for OneApp authentication.');
        }
        if (!function_exists('openssl_public_encrypt')) {
            throw new Exception('The PHP OpenSSL extension is required for OneApp authentication.');
        }

        $passwordLength = strlen($this->password);
        if ($passwordLength < 8 || $passwordLength > 20) {
            throw new Exception('The Hyundai/Kia account password must contain 8 to 20 characters.');
        }

        $deviceId = $this->ensureDeviceId();
        $loginHost = rtrim($this->authConfig['loginFormHost'], '/');
        $redirectUri = $this->authConfig['oneAppRedirectUri'];
        $clientId = $this->authConfig['oneAppClientId'];

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'lang' => 'de',
            'state' => 'ccsp',
            'country' => 'de',
        ], '', '&', PHP_QUERY_RFC3986);

        $curl = $this->createLoginCurlHandle();
        try {
            $this->log('OneApp authorize: loading login session...');
            $authorize = $this->curlSessionRequest(
                $curl,
                'GET',
                $loginHost . '/auth/api/v2/user/oauth2/authorize?' . $query,
                '',
                ['User-Agent: ' . self::MOBILE_USER_AGENT],
                true
            );
            if (stripos($authorize['body'], 'abusing') !== false
                || stripos($authorize['effectiveUrl'], '/error?status=400') !== false) {
                throw new Exception('Hyundai/Kia rejected the authorization request as abusive.');
            }
            if ($authorize['status'] < 200 || $authorize['status'] >= 400) {
                throw new Exception('OneApp authorize failed with HTTP ' . $authorize['status'] . '.');
            }

            $this->log('OneApp authorize: fetching RSA certificate...');
            $certificate = $this->curlSessionRequest(
                $curl,
                'GET',
                $loginHost . '/auth/api/v1/accounts/certs',
                '',
                ['User-Agent: ' . self::MOBILE_USER_AGENT],
                false
            );
            $certificateData = json_decode($certificate['body'], true);
            $jwk = $certificateData['retValue'] ?? null;
            if ($certificate['status'] !== 200 || !is_array($jwk)
                || empty($jwk['kid']) || empty($jwk['n']) || empty($jwk['e'])) {
                throw new Exception('Could not obtain the Hyundai/Kia login certificate (HTTP '
                    . $certificate['status'] . ').');
            }

            $publicKey = openssl_pkey_get_public($this->jwkToPublicKeyPem($jwk['n'], $jwk['e']));
            if ($publicKey === false) {
                throw new Exception('The Hyundai/Kia login certificate could not be decoded.');
            }
            $encryptedPassword = '';
            if (!openssl_public_encrypt($this->password, $encryptedPassword, $publicKey, OPENSSL_PKCS1_PADDING)) {
                throw new Exception('The Hyundai/Kia account password could not be encrypted.');
            }

            $signinPayload = http_build_query([
                'client_id' => $clientId,
                'encryptedPassword' => 'true',
                'password' => bin2hex($encryptedPassword),
                'redirect_uri' => $redirectUri,
                'scope' => '',
                'nonce' => '',
                'state' => 'ccsp',
                'username' => $this->username,
                'connector_session_key' => '',
                'kid' => $jwk['kid'],
                '_csrf' => '',
            ], '', '&', PHP_QUERY_RFC3986);

            $this->log('OneApp signin: submitting encrypted credentials...');
            $signin = $this->curlSessionRequest(
                $curl,
                'POST',
                $loginHost . '/auth/account/signin',
                $signinPayload,
                [
                    'User-Agent: ' . self::MOBILE_USER_AGENT,
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                false
            );
            $location = $this->extractLocationHeader($signin['headers']);
            if ($signin['status'] < 300 || $signin['status'] >= 400 || $location === '') {
                throw new Exception('OneApp signin failed with HTTP ' . $signin['status']
                    . '. Check the account email and password.');
            }

            $locationQuery = parse_url($location, PHP_URL_QUERY);
            $locationParams = [];
            if (is_string($locationQuery)) {
                parse_str($locationQuery, $locationParams);
            }
            $code = $locationParams['code'] ?? '';
            if ($code === '') {
                if (str_contains($location, '/web/v1/user/authorization')) {
                    throw new Exception('Account consent is required. Log in once with the official app and accept the terms.');
                }
                $detail = $locationParams['error_description'] ?? $locationParams['error'] ?? 'authorization code missing';
                throw new Exception('OneApp signin was rejected (' . $detail . ').');
            }
        } finally {
            // Releasing the handle closes the in-memory cookie session.
            unset($curl);
        }

        $this->log('OneApp signin successful; exchanging authorization code for CCI tokens...');
        $tokenResponse = $this->httpPost(
            rtrim($this->authConfig['cciApiUrl'], '/') . '/domain/api/v1/auth/token?'
                . http_build_query(['code' => $code], '', '&', PHP_QUERY_RFC3986),
            '',
            $this->buildCciHeaders($deviceId, '', '', '', false)
        );
        $statusCode = $this->parseStatusCode($tokenResponse['headers'] ?? []);
        $tokens = json_decode($tokenResponse['body'] ?? '', true);
        if ($statusCode !== 200 || !is_array($tokens) || empty($tokens['accessToken'])
            || empty($tokens['refreshToken']) || empty($tokens['nonCcsToken'])
            || empty($tokens['exchangeableAccessToken'])) {
            throw new Exception('CCI token exchange failed (HTTP ' . $statusCode . '): '
                . $this->extractApiError($tokens, $tokenResponse['body'] ?? ''));
        }

        $this->cciAccessToken = $tokens['accessToken'];
        $this->refreshToken = $tokens['refreshToken'];
        $this->nonCcsToken = $tokens['nonCcsToken'];
        $this->exchangeableToken = $tokens['exchangeableAccessToken'];
        $this->exchangeableRefreshToken = $tokens['exchangeableRefreshToken'] ?? '';
        $this->nonCcsRefreshToken = $tokens['nonCcsRefreshToken'] ?? '';
        $this->idToken = $tokens['idToken'] ?? '';
        $this->authMode = 'cci';

        $this->exchangeCciForCcsAccessToken();
        $this->log('OneApp/CCI login completed successfully.');
    }

    private function refreshCciAccessToken(): void
    {
        $url = rtrim($this->authConfig['cciApiUrl'] ?? '', '/') . '/domain/api/v2/auth/token-refresh';
        if ($url === '/domain/api/v2/auth/token-refresh') {
            throw new Exception('CCI refresh endpoint is not configured.');
        }

        $body = json_encode([
            'accessToken' => $this->stripBearerPrefix($this->cciAccessToken),
            'refreshToken' => $this->refreshToken,
            'exchangeableAccessToken' => $this->exchangeableToken,
            'exchangeableRefreshToken' => $this->exchangeableRefreshToken,
            'nonCcsToken' => $this->nonCcsToken,
            'nonCcsRefreshToken' => $this->nonCcsRefreshToken,
            'idToken' => $this->idToken,
        ], JSON_UNESCAPED_SLASHES);

        $response = $this->httpPost(
            $url,
            $body,
            $this->buildCciHeaders(
                $this->deviceId,
                $this->cciAccessToken,
                $this->nonCcsToken,
                $this->exchangeableToken,
                true
            )
        );
        $statusCode = $this->parseStatusCode($response['headers'] ?? []);
        $data = json_decode($response['body'] ?? '', true);
        if ($statusCode !== 200 || !is_array($data)) {
            throw new Exception('CCI token refresh failed (HTTP ' . $statusCode . '): '
                . $this->extractApiError($data, $response['body'] ?? ''));
        }

        $this->cciAccessToken = $data['accessToken'] ?? $this->cciAccessToken;
        $this->refreshToken = $data['refreshToken'] ?? $this->refreshToken;
        $this->nonCcsToken = $data['nonCcsToken'] ?? $this->nonCcsToken;
        $this->exchangeableToken = $data['exchangeableAccessToken'] ?? $this->exchangeableToken;
        $this->exchangeableRefreshToken = $data['exchangeableRefreshToken'] ?? $this->exchangeableRefreshToken;
        $this->nonCcsRefreshToken = $data['nonCcsRefreshToken'] ?? $this->nonCcsRefreshToken;
        $this->idToken = $data['idToken'] ?? $this->idToken;

        $cookieToken = $this->extractCookieValue($response['headers'] ?? [], 't');
        if ($cookieToken !== '') {
            $this->exchangeableToken = $cookieToken;
        }

        $this->exchangeCciForCcsAccessToken();
    }

    private function exchangeCciForCcsAccessToken(): void
    {
        $url = rtrim($this->authConfig['cciApiUrl'] ?? '', '/')
            . '/domain/api/v1/auth/token-exchange?serviceType=CCS';
        $response = $this->httpPost(
            $url,
            '',
            $this->buildCciHeaders(
                $this->deviceId,
                $this->cciAccessToken,
                $this->nonCcsToken,
                $this->exchangeableToken,
                false
            )
        );
        $statusCode = $this->parseStatusCode($response['headers'] ?? []);
        $data = json_decode($response['body'] ?? '', true);
        $ccsToken = is_array($data) ? ($data['accessToken'] ?? $data['ccsAccessToken'] ?? '') : '';
        if ($statusCode !== 200 || $ccsToken === '') {
            throw new Exception('CCS token exchange failed (HTTP ' . $statusCode . '): '
                . $this->extractApiError($data, $response['body'] ?? ''));
        }

        $this->accessToken = $this->stripBearerPrefix($ccsToken);
        $expiresIn = (int) ($data['expiresTime'] ?? $data['expires_in'] ?? 3600);
        $this->tokenExpiry = time() + max(60, $expiresIn);
        $this->authMode = 'cci';
        $this->log('CCS token exchange OK. expires_in=' . $expiresIn);
    }

    private function buildCciHeaders(
        string $deviceId,
        string $cciAccessToken,
        string $nonCcsToken,
        string $exchangeableToken,
        bool $jsonContent
    ): array {
        $headers = [
            'client-id: ' . ($this->authConfig['cciPackageId'] ?? ''),
            'client-name: ' . ($this->authConfig['cciClientName'] ?? ''),
            'client-version: 1.3.3',
            'client-os-code: ios',
            'client-os-version: ' . ($this->authConfig['cciClientOsVersion'] ?? ''),
            'client-device-id: ' . $deviceId,
            'client-device-model: iPhone',
            'client-notification-provider-type: ' . ($this->authConfig['cciNotificationProvider'] ?? ''),
            'locale: DE',
            'timezone: ' . date('P'),
            'Accept: application/json',
            'Accept-Language: de',
            'User-Agent: ' . self::MOBILE_USER_AGENT,
        ];
        if ($nonCcsToken !== '') {
            $headers[] = 'Authentication: ' . $nonCcsToken;
        }
        if ($cciAccessToken !== '') {
            $headers[] = 'authorization: Bearer ' . $this->stripBearerPrefix($cciAccessToken);
        }
        if ($exchangeableToken !== '') {
            $headers[] = 'exchangeable-token: ' . $exchangeableToken;
            $headers[] = 'non-ccs-token: ' . $nonCcsToken;
        }
        $headers[] = $jsonContent ? 'Content-Type: application/json' : 'Content-Length: 0';
        return $headers;
    }

    private function createLoginCurlHandle()
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new Exception('Could not initialize the HTTP login session.');
        }
        curl_setopt($curl, CURLOPT_COOKIEFILE, '');
        return $curl;
    }

    private function curlSessionRequest(
        $curl,
        string $method,
        string $url,
        string $body,
        array $headers,
        bool $followRedirects
    ): array {
        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $responseHeaders[] = $trimmed;
                }
                return strlen($line);
            },
        ]);

        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        } else {
            curl_setopt($curl, CURLOPT_POST, false);
            curl_setopt($curl, CURLOPT_POSTFIELDS, null);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($curl, CURLOPT_HTTPGET, true);
        }

        $responseBody = curl_exec($curl);
        if ($responseBody === false) {
            throw new Exception('HTTP request failed: ' . curl_error($curl));
        }
        return [
            'body' => $responseBody,
            'headers' => $responseHeaders,
            'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'effectiveUrl' => (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
        ];
    }

    private function jwkToPublicKeyPem(string $modulus, string $exponent): string
    {
        $n = $this->base64UrlDecode($modulus);
        $e = $this->base64UrlDecode($exponent);
        $rsaKey = $this->asn1Sequence($this->asn1Integer($n) . $this->asn1Integer($e));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $subjectPublicKeyInfo = $this->asn1Sequence($algorithm . "\x03" . $this->asn1Length(strlen($rsaKey) + 1)
            . "\x00" . $rsaKey);
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new Exception('Invalid RSA certificate encoding.');
        }
        return $decoded;
    }

    private function asn1Integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        } elseif ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }
        return "\x02" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Sequence(string $value): string
    {
        return "\x30" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }
        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    private function stripBearerPrefix(string $token): string
    {
        return trim(preg_replace('/^Bearer\s+/i', '', $token) ?? $token);
    }

    private function extractCookieValue(array $headers, string $name): string
    {
        foreach (array_reverse($headers) as $header) {
            if (preg_match('/^Set-Cookie:\s*' . preg_quote($name, '/') . '=([^;]*)/i', $header, $matches)) {
                return $matches[1];
            }
        }
        return '';
    }

    private function extractApiError($data, string $body): string
    {
        if (is_array($data)) {
            foreach (['error_description', 'error', 'errMsg', 'message'] as $key) {
                if (isset($data[$key]) && is_scalar($data[$key])) {
                    return (string) $data[$key];
                }
            }
        }
        $body = trim($body);
        return $body !== '' ? substr($body, 0, 200) : 'unknown error';
    }

    private function parseStatusCode(array $headers): int
    {
        foreach (array_reverse($headers) as $header) {
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                return (int) $matches[1];
            }
        }
        return 0;
    }

    private function httpPost(string $url, string $body, array $headers = []): array
    {
        $headerStr = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => [
                'method'          => 'POST',
                'header'          => $headerStr,
                'content'         => $body,
                'timeout'         => 15,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            $this->log('HTTP POST FAILED (network error): ' . $url);
        }
        return [
            'body'    => $responseBody ?: '',
            'headers' => $http_response_header ?? [],
        ];
    }

    private function extractLocationHeader(array $headers): string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Location:') === 0) {
                return trim(substr($header, 9));
            }
        }
        return '';
    }
}
