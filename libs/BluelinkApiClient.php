<?php

declare(strict_types=1);

class BluelinkApiClient
{
    private const DEFAULT_BASE_URL = 'https://prd.eu-ccapi.hyundai.com:8080';
    private const REQUEST_TIMEOUT = 15;
    private const LONG_REQUEST_TIMEOUT = 35;
    private const MAX_RETRIES = 3;
    private const BACKOFF_BASE = 30; // seconds

    private string $baseUrl;
    private BluelinkAuthService $authService;
    /** @var callable|null */
    private $logger = null;

    // Rate limit tracking
    private int $lastRequestTime = 0;
    private int $minRequestInterval = 2; // seconds between requests
    private int $backoffUntil = 0;
    private int $ccs2Support = 0;

    public function __construct(string $baseUrl, BluelinkAuthService $authService)
    {
        $this->baseUrl = $baseUrl ?: self::DEFAULT_BASE_URL;
        $this->authService = $authService;
    }

    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    public function setCCS2Support(int $level): void
    {
        $this->ccs2Support = $level;
    }

    // ── Vehicle List ────────────────────────────────────────────────

    public function getVehicles(): array
    {
        $this->log('=== GetVehicles START ===');
        $response = $this->request('GET', '/api/v1/spa/vehicles');

        $this->log('GetVehicles raw response keys: ' . implode(', ', array_keys($response)));
        if (isset($response['retCode'])) {
            $this->log('GetVehicles retCode=' . $response['retCode']);
        }
        if (isset($response['resCode'])) {
            $this->log('GetVehicles resCode=' . $response['resCode']);
        }
        if (isset($response['resMsg'])) {
            $this->log('GetVehicles resMsg keys: ' . (is_array($response['resMsg']) ? implode(', ', array_keys($response['resMsg'])) : gettype($response['resMsg'])));
        } else {
            $this->log('GetVehicles WARNING: no resMsg in response. Full response: ' . substr(json_encode($response), 0, 1000));
        }

        $vehicles = $response['resMsg']['vehicles'] ?? [];
        $this->log('GetVehicles found ' . count($vehicles) . ' vehicles');
        foreach ($vehicles as $i => $v) {
            $this->log('  Vehicle[' . $i . ']: id=' . ($v['vehicleId'] ?? 'n/a')
                . ' vin=' . ($v['vin'] ?? 'n/a')
                . ' name=' . ($v['vehicleName'] ?? $v['nickname'] ?? 'n/a'));
        }
        $this->log('=== GetVehicles END ===');

        return $vehicles;
    }

    // ── Vehicle Status ──────────────────────────────────────────────

    public function getVehicleStatus(string $vehicleId): array
    {
        if ($this->ccs2Support > 0) {
            return $this->getVehicleStatusCCS2($vehicleId);
        }
        $response = $this->request('GET', '/api/v1/spa/vehicles/' . $vehicleId . '/status/latest');
        $resMsg = $response['resMsg'] ?? [];
        // /status/latest returns resMsg.vehicleStatusInfo containing vehicleStatus + odometer
        if (isset($resMsg['vehicleStatusInfo'])) {
            $this->log('Status response: using vehicleStatusInfo (keys: ' . implode(', ', array_keys($resMsg['vehicleStatusInfo'])) . ')');
            return $resMsg['vehicleStatusInfo'];
        }
        $this->log('Status response: no vehicleStatusInfo found, keys: ' . implode(', ', array_keys($resMsg)));
        return $resMsg;
    }

    public function getVehicleStatusCCS2(string $vehicleId): array
    {
        $this->log('Using CCS2 endpoint for status');
        $response = $this->request('GET', '/api/v1/spa/vehicles/' . $vehicleId . '/ccs2/carstatus/latest');
        $resMsg = $response['resMsg'] ?? [];
        if (isset($resMsg['state']['Vehicle'])) {
            $this->log('CCS2 Status response: using state.Vehicle');
            return ['_ccs2' => true, 'state' => $resMsg['state']['Vehicle']];
        }
        $this->log('CCS2 Status response: unexpected format, keys: ' . implode(', ', array_keys($resMsg)));
        return $resMsg;
    }

    public function getCachedVehicleStatus(string $vehicleId): array
    {
        $response = $this->request('GET', '/api/v1/spa/vehicles/' . $vehicleId . '/status');
        return $response['resMsg'] ?? [];
    }

    public function getVehicleLocation(string $vehicleId): array
    {
        $response = $this->request('GET', '/api/v1/spa/vehicles/' . $vehicleId . '/location');
        $resMsg = $response['resMsg'] ?? [];
        // /location returns resMsg.gpsDetail containing coord, head, speed, time
        if (isset($resMsg['gpsDetail'])) {
            $this->log('Location response: using gpsDetail');
            return $resMsg['gpsDetail'];
        }
        $this->log('Location response: no gpsDetail found, keys: ' . implode(', ', array_keys($resMsg)));
        return $resMsg;
    }

    public function getVehicleOdometer(string $vehicleId): array
    {
        $response = $this->request('GET', '/api/v2/spa/vehicles/' . $vehicleId . '/odometer');
        return $response['resMsg'] ?? [];
    }

    // ── Remote Actions ──────────────────────────────────────────────

    public function lock(string $vehicleId): array
    {
        return $this->sendCommand($vehicleId, '/api/v2/spa/vehicles/' . $vehicleId . '/control/door', [
            'action'   => 'close',
            'deviceId' => $this->authService->getDeviceId(),
        ]);
    }

    public function unlock(string $vehicleId): array
    {
        return $this->sendCommand($vehicleId, '/api/v2/spa/vehicles/' . $vehicleId . '/control/door', [
            'action'   => 'open',
            'deviceId' => $this->authService->getDeviceId(),
        ]);
    }

    public function startClimate(string $vehicleId, float $temperature, bool $defrost = false, array $seatHeat = []): array
    {
        $tempCode = self::celsiusToTempCode($temperature);
        $this->log('ClimateStart: ' . $temperature . '°C → tempCode=' . $tempCode);

        $payload = [
            'action'   => 'start',
            'hvacType' => 0,
            'options'  => [
                'defrost'  => $defrost,
                'heating1' => 0,
            ],
            'tempCode' => $tempCode,
            'unit'     => 'C',
        ];

        if (!empty($seatHeat)) {
            $payload['options']['seatHeaterVentInfo'] = $seatHeat;
        }

        return $this->sendCommand($vehicleId, '/api/v2/spa/vehicles/' . $vehicleId . '/control/temperature', $payload);
    }

    public function stopClimate(string $vehicleId): array
    {
        return $this->sendCommand($vehicleId, '/api/v2/spa/vehicles/' . $vehicleId . '/control/temperature', [
            'action'   => 'stop',
            'hvacType' => 0,
            'options'  => [
                'defrost'  => true,
                'heating1' => 1,
            ],
            'tempCode' => '10H',
            'unit'     => 'C',
        ]);
    }

    private static function celsiusToTempCode(float $celsius): string
    {
        $celsius = max(14.0, min(30.0, $celsius));
        $index = (int) round(($celsius - 14.0) / 0.5);
        return str_pad(strtoupper(dechex($index)), 2, '0', STR_PAD_LEFT) . 'H';
    }

    public function startCharge(string $vehicleId): array
    {
        return $this->sendCommand($vehicleId, '/api/v2/spa/vehicles/' . $vehicleId . '/control/charge', [
            'action'   => 'start',
            'deviceId' => $this->authService->getDeviceId(),
        ]);
    }

    public function stopCharge(string $vehicleId): array
    {
        return $this->sendCommand($vehicleId, '/api/v2/spa/vehicles/' . $vehicleId . '/control/charge', [
            'action'   => 'stop',
            'deviceId' => $this->authService->getDeviceId(),
        ]);
    }

    public function setChargeTargets(string $vehicleId, int $limitAC, int $limitDC): array
    {
        $controlToken = $this->obtainControlToken();

        $response = $this->request('POST', '/api/v2/spa/vehicles/' . $vehicleId . '/charge/target', [
            'targetSOClist' => [
                ['plugType' => 0, 'targetSOClevel' => $limitDC],
                ['plugType' => 1, 'targetSOClevel' => $limitAC],
            ],
        ], [
            'Authorization: Bearer ' . $controlToken,
        ]);

        return $response['resMsg'] ?? [];
    }

    private function obtainControlToken(): string
    {
        $pin = $this->authService->getPin();
        if (empty($pin)) {
            throw new Exception('PIN is required for this operation');
        }

        $response = $this->request('PUT', '/api/v1/user/pin', [
            'deviceId' => $this->authService->getDeviceId(),
            'pin'      => $pin,
        ]);

        $controlToken = $response['controlToken'] ?? '';
        if (empty($controlToken)) {
            throw new Exception('Failed to obtain control token. Response: ' . json_encode(array_keys($response)));
        }

        return $controlToken;
    }

    public function refreshVehicleStatus(string $vehicleId): array
    {
        $response = $this->request('GET', '/api/v1/spa/vehicles/' . $vehicleId . '/status', null, [], self::LONG_REQUEST_TIMEOUT, 1);
        return $response['resMsg'] ?? [];
    }

    public function refreshLocation(string $vehicleId): array
    {
        return $this->request('GET', '/api/v1/spa/vehicles/' . $vehicleId . '/location');
    }

    // ── Command Status ──────────────────────────────────────────────

    public function getCommandStatus(string $vehicleId, string $commandId): array
    {
        $response = $this->request('GET', '/api/v1/spa/vehicles/' . $vehicleId . '/control/' . $commandId);
        return $response['resMsg'] ?? [];
    }

    // ── HTTP Layer ──────────────────────────────────────────────────

    private function sendCommand(string $vehicleId, string $endpoint, array $payload): array
    {
        $controlToken = $this->obtainControlToken();

        $response = $this->request('POST', $endpoint, $payload, [
            'Authorization: Bearer ' . $controlToken,
        ]);

        return $response['resMsg'] ?? [];
    }

    private function request(string $method, string $endpoint, ?array $body = null, array $extraHeaders = [], int $timeout = 0, int $maxRetries = 0): array
    {
        // Check backoff
        if (time() < $this->backoffUntil) {
            throw new Exception('API rate limited. Retry after ' . ($this->backoffUntil - time()) . ' seconds.');
        }

        // Enforce minimum interval
        $elapsed = time() - $this->lastRequestTime;
        if ($elapsed < $this->minRequestInterval) {
            usleep(($this->minRequestInterval - $elapsed) * 1000000);
        }

        $url = $this->baseUrl . $endpoint;
        $authHeaders = $this->authService->getAuthHeaders($this->ccs2Support);
        if (!empty($extraHeaders)) {
            $extraNames = array_map(function ($h) {
                return strtolower(explode(':', $h, 2)[0]);
            }, $extraHeaders);
            $authHeaders = array_filter($authHeaders, function ($h) use ($extraNames) {
                return !in_array(strtolower(explode(':', $h, 2)[0]), $extraNames);
            });
        }
        $headers = array_merge(array_values($authHeaders), $extraHeaders);

        $effectiveTimeout = $timeout > 0 ? $timeout : self::REQUEST_TIMEOUT;
        $effectiveMaxRetries = $maxRetries > 0 ? $maxRetries : self::MAX_RETRIES;
        $attempt = 0;
        $lastException = null;

        while ($attempt < $effectiveMaxRetries) {
            $attempt++;
            $startTime = microtime(true);

            try {
                $response = $this->httpRequest($method, $url, $headers, $body, $effectiveTimeout);
                $duration = round((microtime(true) - $startTime) * 1000);
                $this->lastRequestTime = time();

                $statusCode = $response['statusCode'];
                $this->log(sprintf(
                    '%s %s -> %d (%dms) bodyLength=%d',
                    $method,
                    $endpoint,
                    $statusCode,
                    $duration,
                    strlen($response['body'])
                ));

                // Handle rate limiting
                if ($statusCode === 429) {
                    $retryAfter = self::BACKOFF_BASE * pow(2, $attempt - 1) + random_int(0, 15);
                    $this->backoffUntil = time() + $retryAfter;
                    $this->log('Rate limited. Backing off for ' . $retryAfter . 's');
                    if ($attempt < self::MAX_RETRIES) {
                        sleep(min($retryAfter, 60));
                        continue;
                    }
                    throw new Exception('API rate limit exceeded');
                }

                // Handle server errors with retry
                if ($statusCode >= 500 && $attempt < $effectiveMaxRetries) {
                    $retryAfter = self::BACKOFF_BASE * pow(2, $attempt - 1) + random_int(0, 15);
                    $this->log('Server error ' . $statusCode . '. Retrying in ' . $retryAfter . 's');
                    sleep(min($retryAfter, 60));
                    continue;
                }

                // Handle auth errors
                if ($statusCode === 401) {
                    throw new Exception('Authentication failed. Token may be expired.');
                }

                if ($statusCode >= 400) {
                    $errorBody = json_decode($response['body'], true);
                    $resCode = $errorBody['resCode'] ?? '';
                    $errorMsg = $errorBody['resMsg'] ?? $errorBody['errMsg'] ?? 'Unknown error';
                    if (is_array($errorMsg)) {
                        $errorMsg = $errorMsg['message'] ?? json_encode($errorMsg);
                    }
                    $this->log('API error response body: ' . substr($response['body'], 0, 500));
                    throw new Exception('API error ' . $statusCode . ' [' . $resCode . ']: ' . $errorMsg);
                }

                $decoded = json_decode($response['body'], true);
                if (!is_array($decoded)) {
                    $this->log('Invalid JSON. Raw body (first 300 chars): ' . substr($response['body'], 0, 300));
                    throw new Exception('Invalid JSON response from API');
                }

                // Log response structure for debugging
                $this->log('Response keys: ' . implode(', ', array_keys($decoded)));

                return $decoded;
            } catch (Exception $e) {
                $lastException = $e;
                if ($attempt >= $effectiveMaxRetries) {
                    break;
                }
                // Only retry on connection errors, not on auth/validation errors
                if (strpos($e->getMessage(), 'Authentication') !== false ||
                    strpos($e->getMessage(), 'PIN') !== false) {
                    break;
                }
            }
        }

        throw $lastException ?? new Exception('Request failed after ' . self::MAX_RETRIES . ' attempts');
    }

    private function httpRequest(string $method, string $url, array $headers, ?array $body = null, int $timeout = 0): array
    {
        $headerStr = implode("\r\n", $headers);

        // Log outgoing request details (mask auth header)
        $safeHeaders = array_map(function ($h) {
            if (stripos($h, 'Authorization:') === 0) {
                return 'Authorization: ' . substr($h, 15, 10) . '...[masked]';
            }
            if (stripos($h, 'pin:') === 0) {
                return 'pin: ****';
            }
            return $h;
        }, $headers);
        $this->log('>>> ' . $method . ' ' . $url);
        $this->log('>>> Headers: ' . implode(' | ', $safeHeaders));
        if ($body !== null) {
            $this->log('>>> Body: ' . substr(json_encode($body), 0, 300));
        }

        $options = [
            'http' => [
                'method'          => $method,
                'header'          => $headerStr,
                'timeout'         => $timeout > 0 ? $timeout : self::REQUEST_TIMEOUT,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
        ];

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $options['http']['content'] = json_encode($body);
        }

        $context = stream_context_create($options);
        $responseBody = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusCode = $this->parseStatusCode($responseHeaders);

        if ($responseBody === false) {
            $this->log('<<< NETWORK ERROR: file_get_contents returned false for ' . $url);
            $this->log('<<< Check: DNS resolution, firewall, SSL/TLS, port 8080 access');
        } else {
            $this->log('<<< HTTP ' . $statusCode . ' bodyLength=' . strlen($responseBody));
            // Log response headers for debugging
            foreach ($responseHeaders as $rh) {
                if (stripos($rh, 'HTTP/') === 0 || stripos($rh, 'Content-Type') === 0 || stripos($rh, 'X-') === 0) {
                    $this->log('<<< ' . $rh);
                }
            }
        }

        return [
            'body'       => $responseBody ?: '',
            'headers'    => $responseHeaders,
            'statusCode' => $statusCode,
        ];
    }

    private function parseStatusCode(array $headers): int
    {
        if (!empty($headers[0]) && preg_match('/HTTP\/\S+\s+(\d+)/', $headers[0], $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
