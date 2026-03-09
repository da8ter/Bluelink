<?php

declare(strict_types=1);

class BluelinkAuthService
{
    private const TOKEN_EXPIRY_BUFFER = 60; // seconds before expiry to refresh
    private const APP_ID = '014d2225-8495-4735-812d-2616334fd15d';
    private const PUSH_TYPE = 'GCM';

    private string $baseUrl;
    private string $clientId;
    private string $basicToken;
    private BluelinkStampService $stampService;

    private string $accessToken = '';
    private string $refreshToken = '';
    private int $tokenExpiry = 0;
    private string $deviceId = '';
    private string $username = '';
    private string $password = '';
    private string $pin = '';

    /** @var callable|null */
    private $logger = null;

    public function __construct(
        string $baseUrl,
        string $clientId,
        string $basicToken,
        BluelinkStampService $stampService
    ) {
        $this->baseUrl = $baseUrl;
        $this->clientId = $clientId;
        $this->basicToken = $basicToken;
        $this->stampService = $stampService;
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

    public function setCredentials(string $username, string $password, string $pin): void
    {
        $this->username = $username;
        $this->password = $password;
        $this->pin = $pin;
    }

    public function setRefreshToken(string $refreshToken): void
    {
        $this->refreshToken = $refreshToken;
    }

    public function getAccessToken(): string
    {
        if ($this->isTokenValid()) {
            $this->log('Token valid, expires in ' . ($this->tokenExpiry - time()) . 's');
            return $this->accessToken;
        }

        $this->log('Token invalid/expired. tokenExpiry=' . $this->tokenExpiry . ' now=' . time()
            . ' hasRefreshToken=' . (!empty($this->refreshToken) ? 'yes(' . strlen($this->refreshToken) . ' chars)' : 'no')
            . ' hasCredentials=' . (!empty($this->username) ? 'yes' : 'no'));

        if (!empty($this->refreshToken)) {
            $this->log('Attempting token refresh...');
            $this->refreshAccessToken();
            $this->log('Token refresh successful. New expiry: ' . date('H:i:s', $this->tokenExpiry));
            return $this->accessToken;
        }

        if (!empty($this->username) && !empty($this->password)) {
            $this->log('Attempting credential login...');
            $this->loginWithCredentials();
            $this->log('Credential login successful. Expiry: ' . date('H:i:s', $this->tokenExpiry));
            return $this->accessToken;
        }

        throw new Exception('No valid authentication method available. Provide a refresh token or credentials.');
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
        $this->accessToken = $data['accessToken'] ?? '';
        $this->refreshToken = $data['refreshToken'] ?? $this->refreshToken;
        $this->tokenExpiry = $data['tokenExpiry'] ?? 0;
        $this->deviceId = $data['deviceId'] ?? '';
    }

    public function getTokenCacheData(): string
    {
        return json_encode([
            'accessToken'  => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'tokenExpiry'  => $this->tokenExpiry,
            'deviceId'     => $this->deviceId,
        ]);
    }

    public function getAuthHeaders(): array
    {
        $stamp = $this->stampService->getStamp();
        $deviceId = $this->ensureDeviceId();

        $this->log('Building auth headers: deviceId=' . $deviceId
            . ' stamp=' . substr($stamp, 0, 16) . '...'
            . ' clientId=' . $this->clientId);

        return [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'ccsp-device-id: ' . $deviceId,
            'ccsp-application-id: ' . self::APP_ID,
            'Stamp: ' . $stamp,
            'Content-Type: application/json',
            'Host: prd.eu-ccapi.hyundai.com:8080',
            'Connection: Keep-Alive',
            'Accept-Encoding: gzip',
            'User-Agent: okhttp/3.12.0',
        ];
    }

    public function testLogin(): array
    {
        try {
            $this->log('=== TestLogin START ===');
            $this->log('baseUrl=' . $this->baseUrl);
            $this->log('clientId=' . $this->clientId);
            $this->log('hasUsername=' . (!empty($this->username) ? 'yes' : 'no'));
            $this->log('hasPassword=' . (!empty($this->password) ? 'yes' : 'no'));
            $this->log('hasRefreshToken=' . (!empty($this->refreshToken) ? 'yes(' . strlen($this->refreshToken) . ' chars)' : 'no'));
            $this->log('cachedTokenExpiry=' . ($this->tokenExpiry > 0 ? date('Y-m-d H:i:s', $this->tokenExpiry) : 'none'));

            $token = $this->getAccessToken();

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
            'pushType'  => self::PUSH_TYPE,
            'uuid'      => $uuid,
        ]);

        $headers = [
            'ccsp-service-id: ' . $this->clientId,
            'ccsp-application-id: ' . self::APP_ID,
            'Stamp: ' . $stamp,
            'Content-Type: application/json;charset=UTF-8',
            'Host: prd.eu-ccapi.hyundai.com:8080',
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

    private function loginWithCredentials(): void
    {
        // Step 1: Get session cookie
        $sessionUrl = $this->baseUrl . '/api/v1/user/oauth2/authorize?response_type=code&state=test&client_id=' . $this->clientId . '&redirect_uri=' . $this->baseUrl . '/api/v1/user/oauth2/redirect';
        $this->log('[Login Step 1] GET session: ' . $sessionUrl);
        $sessionResponse = $this->httpGet($sessionUrl);
        $sessionStatus = $this->parseStatusCode($sessionResponse['headers'] ?? []);
        $this->log('[Login Step 1] HTTP ' . $sessionStatus . ' headers=' . count($sessionResponse['headers'] ?? []));

        // Extract cookies from response
        $cookies = $this->extractCookies($sessionResponse['headers'] ?? []);
        $this->log('[Login Step 1] Cookies: ' . (empty($cookies) ? 'NONE' : $cookies));

        // Step 2: Set language
        $langUrl = $this->baseUrl . '/api/v1/user/language';
        $this->log('[Login Step 2] POST language: ' . $langUrl);
        $langResponse = $this->httpPost($langUrl, json_encode(['lang' => 'en']), [
            'Content-Type: application/json',
            'Cookie: ' . $cookies,
        ]);
        $langStatus = $this->parseStatusCode($langResponse['headers'] ?? []);
        $this->log('[Login Step 2] HTTP ' . $langStatus);

        // Step 3: Sign in
        $loginUrl = $this->baseUrl . '/api/v1/user/signin';
        $this->log('[Login Step 3] POST signin: ' . $loginUrl);
        $loginPayload = json_encode([
            'email'    => $this->username,
            'password' => $this->password,
        ]);
        $loginResponse = $this->httpPost($loginUrl, $loginPayload, [
            'Content-Type: application/json',
            'Cookie: ' . $cookies,
        ]);
        $loginStatus = $this->parseStatusCode($loginResponse['headers'] ?? []);
        $this->log('[Login Step 3] HTTP ' . $loginStatus . ' bodyLength=' . strlen($loginResponse['body'] ?? ''));

        $loginData = json_decode($loginResponse['body'] ?? '', true);
        if (empty($loginData['redirectUrl'])) {
            $this->log('[Login Step 3] FAILED. Response: ' . substr($loginResponse['body'] ?? '', 0, 500));
            $errDetail = $loginData['error'] ?? $loginData['errMsg'] ?? $loginData['message'] ?? '';
            throw new Exception('Login failed: No redirect URL received. ' . ($errDetail ? 'Detail: ' . $errDetail . '. ' : '') . 'Check credentials or try refresh token method.');
        }
        $this->log('[Login Step 3] Got redirectUrl');

        // Step 4: Follow redirect to get auth code
        $redirectUrl = $loginData['redirectUrl'];
        $this->log('[Login Step 4] GET redirect (no follow)');
        $redirectResponse = $this->httpGet($redirectUrl, false);
        $redirectStatus = $this->parseStatusCode($redirectResponse['headers'] ?? []);
        $locationHeader = $this->extractLocationHeader($redirectResponse['headers'] ?? []);
        $this->log('[Login Step 4] HTTP ' . $redirectStatus . ' Location: ' . (empty($locationHeader) ? 'NONE' : 'present'));

        if (empty($locationHeader)) {
            $this->log('[Login Step 4] FAILED. All headers: ' . implode(' | ', $redirectResponse['headers'] ?? []));
            throw new Exception('Login failed: No authorization code received (HTTP ' . $redirectStatus . ')');
        }

        // Extract code from redirect
        $parsedUrl = parse_url($locationHeader);
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        $authCode = $queryParams['code'] ?? '';

        if (empty($authCode)) {
            $this->log('[Login Step 4] No code in Location. Query: ' . ($parsedUrl['query'] ?? 'empty'));
            throw new Exception('Login failed: Authorization code not found in redirect');
        }
        $this->log('[Login Step 4] Got auth code (' . strlen($authCode) . ' chars)');

        // Step 5: Exchange code for tokens
        $this->log('[Login Step 5] Exchanging code for tokens...');
        $this->exchangeCodeForTokens($authCode);
        $this->log('[Login Step 5] Token exchange complete');
    }

    private function refreshAccessToken(): void
    {
        $tokenUrl = $this->baseUrl . '/api/v1/user/oauth2/token';
        $payload = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'redirect_uri'  => $this->baseUrl . '/api/v1/user/oauth2/redirect',
        ]);

        $this->log('RefreshToken POST ' . $tokenUrl);

        $response = $this->httpPost($tokenUrl, $payload, [
            'Authorization: ' . $this->basicToken,
            'Content-Type: application/x-www-form-urlencoded',
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
        if (!empty($data['refresh_token'])) {
            $this->refreshToken = $data['refresh_token'];
            $this->log('RefreshToken: got new refresh token (' . strlen($this->refreshToken) . ' chars)');
        }
        $this->log('RefreshToken OK. expires_in=' . ($data['expires_in'] ?? 'n/a') . ' token_type=' . ($data['token_type'] ?? 'n/a'));
    }

    private function exchangeCodeForTokens(string $code): void
    {
        $tokenUrl = $this->baseUrl . '/api/v1/user/oauth2/token';
        $payload = http_build_query([
            'grant_type'   => 'authorization_code',
            'redirect_uri' => $this->baseUrl . '/api/v1/user/oauth2/redirect',
            'code'         => $code,
        ]);

        $this->log('TokenExchange POST ' . $tokenUrl);
        $response = $this->httpPost($tokenUrl, $payload, [
            'Authorization: ' . $this->basicToken,
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $statusCode = $this->parseStatusCode($response['headers'] ?? []);
        $this->log('TokenExchange HTTP ' . $statusCode . ' bodyLength=' . strlen($response['body'] ?? ''));

        $data = json_decode($response['body'] ?? '', true);
        if (empty($data['access_token'])) {
            $this->log('TokenExchange FAILED. Response: ' . substr($response['body'] ?? '', 0, 500));
            $errMsg = $data['error_description'] ?? $data['error'] ?? 'unknown';
            throw new Exception('Token exchange failed: ' . $errMsg);
        }

        $this->accessToken = $data['access_token'];
        $this->refreshToken = $data['refresh_token'] ?? $this->refreshToken;
        $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600);
        $this->log('TokenExchange OK. expires_in=' . ($data['expires_in'] ?? 'n/a'));
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

    private function httpGet(string $url, bool $followRedirects = true): array
    {
        $context = stream_context_create([
            'http' => [
                'method'           => 'GET',
                'timeout'          => 15,
                'follow_location'  => $followRedirects ? 1 : 0,
                'max_redirects'    => $followRedirects ? 5 : 0,
                'ignore_errors'    => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $this->log('HTTP GET FAILED (network error): ' . $url);
        }
        return [
            'body'    => $body ?: '',
            'headers' => $http_response_header ?? [],
        ];
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

    private function extractCookies(array $headers): string
    {
        $cookies = [];
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $cookiePart = trim(substr($header, 11));
                $cookieName = explode('=', explode(';', $cookiePart)[0]);
                if (count($cookieName) >= 2) {
                    $cookies[] = $cookieName[0] . '=' . $cookieName[1];
                }
            }
        }
        return implode('; ', $cookies);
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
