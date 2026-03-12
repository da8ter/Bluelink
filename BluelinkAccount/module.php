<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/BrandConfig.php';
require_once __DIR__ . '/../libs/BluelinkStampService.php';
require_once __DIR__ . '/../libs/BluelinkAuthService.php';
require_once __DIR__ . '/../libs/BluelinkApiClient.php';
require_once __DIR__ . '/../libs/VehicleStatusMapper.php';
require_once __DIR__ . '/../libs/CommandService.php';

class BluelinkAccount extends IPSModule
{

    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyString('Brand', BrandConfig::BRAND_HYUNDAI);
        $this->RegisterPropertyString('PIN', '');
        $this->RegisterPropertyString('RefreshToken', '');
        $this->RegisterPropertyString('Region', 'EU');
        $this->RegisterPropertyBoolean('DebugEnabled', false);
        $this->RegisterPropertyInteger('DebugLevel', 0);

        // Buffers for token cache
        $this->SetBuffer('TokenCache', '');
        $this->SetBuffer('VehicleList', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->SetStatus($this->hasValidConfig() ? IS_ACTIVE : IS_INACTIVE);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString, true);
        $command = $data['Command'] ?? '';

        $this->SendDebug('ForwardData', 'Command: ' . $command, 0);

        switch ($command) {
            case 'GetVehicles':
                return $this->GetVehicleListJSON();

            case 'GetBrand':
                return json_encode(['success' => true, 'brand' => $this->ReadPropertyString('Brand')]);

            case 'HasPIN':
                return json_encode(['success' => true, 'hasPIN' => !empty($this->ReadPropertyString('PIN'))]);

            case 'GetVehicleStatus':
                return $this->GetVehicleStatusJSON($data['VehicleId'] ?? '');

            case 'GetVehicleLocation':
                return $this->GetVehicleLocationJSON($data['VehicleId'] ?? '');

            case 'GetVehicleOdometer':
                return $this->GetVehicleOdometerJSON($data['VehicleId'] ?? '');

            case 'Lock':
                return $this->ExecuteCommandJSON($data['VehicleId'] ?? '', 'Lock');

            case 'Unlock':
                return $this->ExecuteCommandJSON($data['VehicleId'] ?? '', 'Unlock');

            case 'ClimateStart':
                return $this->ExecuteClimateStartJSON(
                    $data['VehicleId'] ?? '',
                    (float) ($data['Temperature'] ?? 22.0),
                    (bool) ($data['Defrost'] ?? false)
                );

            case 'ClimateStop':
                return $this->ExecuteCommandJSON($data['VehicleId'] ?? '', 'ClimateStop');

            case 'ChargeStart':
                return $this->ExecuteCommandJSON($data['VehicleId'] ?? '', 'ChargeStart');

            case 'ChargeStop':
                return $this->ExecuteCommandJSON($data['VehicleId'] ?? '', 'ChargeStop');

            case 'SetChargeTargets':
                return $this->ExecuteSetChargeTargetsJSON(
                    $data['VehicleId'] ?? '',
                    (int) ($data['LimitAC'] ?? 80),
                    (int) ($data['LimitDC'] ?? 80)
                );

            case 'RefreshVehicleStatus':
                return $this->ExecuteCommandJSON($data['VehicleId'] ?? '', 'RefreshVehicleStatus');

            case 'GetCommandStatus':
                return $this->GetCommandStatusJSON($data['VehicleId'] ?? '');

            default:
                $this->SendDebug('ForwardData', 'Unknown command: ' . $command, 0);
                return json_encode(['error' => 'Unknown command']);
        }
    }

    // ── Public actions (form.json buttons) ──────────────────────────

    public function TestLogin(): string
    {
        try {
            $this->SendDebug('TestLogin', '=== Starting login test ===', 0);
            $auth = $this->createAuthService();
            $result = $auth->testLogin();

            if ($result['success']) {
                $tokenCache = $auth->getTokenCacheData();
                $this->SetBuffer('TokenCache', $tokenCache);
                $this->SendDebug('TestLogin', 'Token cache saved (' . strlen($tokenCache) . ' bytes)', 0);

                $this->SetStatus(IS_ACTIVE);
                $this->SendDebug('TestLogin', 'Login successful. Token expires: ' . ($result['tokenExpiry'] ?? 'n/a'), 0);
            } else {
                $this->SendDebug('TestLogin', 'Login FAILED: ' . ($result['message'] ?? 'unknown'), 0);
            }
            return json_encode($result);
        } catch (Exception $e) {
            $this->SendDebug('TestLogin', 'EXCEPTION: ' . $e->getMessage(), 0);
            return json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function LoadVehicles(): string
    {
        try {
            $this->SendDebug('LoadVehicles', '=== Starting vehicle list fetch ===', 0);

            // Check token cache state
            $tokenCache = $this->GetBuffer('TokenCache');
            $this->SendDebug('LoadVehicles', 'TokenCache buffer: ' . (empty($tokenCache) ? 'EMPTY' : strlen($tokenCache) . ' bytes'), 0);
            if (!empty($tokenCache)) {
                $tc = json_decode($tokenCache, true);
                $this->SendDebug('LoadVehicles', 'Cached token expiry: '
                    . (isset($tc['tokenExpiry']) && $tc['tokenExpiry'] > 0 ? date('Y-m-d H:i:s', $tc['tokenExpiry']) : 'none')
                    . ' hasAccessToken=' . (!empty($tc['accessToken']) ? 'yes(' . strlen($tc['accessToken']) . ')' : 'no')
                    . ' hasRefreshToken=' . (!empty($tc['refreshToken']) ? 'yes(' . strlen($tc['refreshToken']) . ')' : 'no'), 0);
            }

            $services = $this->createApiClientWithAuth();
            $client = $services['client'];
            $auth = $services['auth'];

            $vehicles = $client->getVehicles();

            // Persist token cache IMMEDIATELY with the same auth instance
            $this->persistAuthCache($auth);

            $this->SendDebug('LoadVehicles', 'Raw vehicles count: ' . count($vehicles), 0);

            $mapped = [];
            foreach ($vehicles as $i => $v) {
                $info = VehicleStatusMapper::mapVehicleInfo($v);
                $mapped[] = $info;
                $this->SendDebug('LoadVehicles', 'Vehicle[' . $i . ']: VIN=' . ($info['VIN'] ?? 'n/a')
                    . ' ID=' . ($info['VehicleId'] ?? 'n/a')
                    . ' Name=' . ($info['VehicleName'] ?? 'n/a')
                    . ' Model=' . ($info['Model'] ?? 'n/a')
                    . ' CCS2=' . ($info['CCS2Support'] ?? 0), 0);
            }

            $this->SetBuffer('VehicleList', json_encode($mapped));
            $this->SendDebug('LoadVehicles', '=== Done. Found ' . count($mapped) . ' vehicles ===', 0);

            return json_encode(['success' => true, 'vehicles' => $mapped]);
        } catch (Exception $e) {
            $this->SendDebug('LoadVehicles', 'EXCEPTION: ' . $e->getMessage(), 0);
            $this->SendDebug('LoadVehicles', 'Trace: ' . $e->getTraceAsString(), 0);
            return json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ── Internal API methods ────────────────────────────────────────

    private function GetVehicleListJSON(): string
    {
        $cached = $this->GetBuffer('VehicleList');
        if (!empty($cached)) {
            $vehicles = json_decode($cached, true);
            if (!empty($vehicles)) {
                return json_encode(['success' => true, 'vehicles' => $vehicles]);
            }
        }
        return $this->LoadVehicles();
    }

    private function GetVehicleStatusJSON(string $vehicleId): string
    {
        $services = null;
        try {
            $services = $this->createApiClientWithAuth();
            $ccs2 = $this->getVehicleCCS2Support($vehicleId);
            $services['client']->setCCS2Support($ccs2);
            $this->SendDebug('GetVehicleStatus', 'VehicleId=' . $vehicleId . ' CCS2Support=' . $ccs2, 0);

            $status = $services['client']->getVehicleStatus($vehicleId);

            if (!empty($status['_ccs2'])) {
                $this->SendDebug('GetVehicleStatus', 'Using CCS2 mapper', 0);
                $mapped = VehicleStatusMapper::mapStatusCCS2($status);
            } else {
                $mapped = VehicleStatusMapper::mapStatus($status);
            }
            return json_encode(['success' => true, 'status' => $mapped]);
        } catch (Exception $e) {
            $this->SendDebug('GetVehicleStatus', 'Error: ' . $e->getMessage(), 0);
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        } finally {
            if ($services) { $this->persistAuthCache($services['auth']); }
        }
    }

    private function GetVehicleLocationJSON(string $vehicleId): string
    {
        $services = null;
        try {
            $services = $this->createApiClientWithAuth();
            $location = $services['client']->getVehicleLocation($vehicleId);
            $mapped = VehicleStatusMapper::mapLocation($location);
            return json_encode(['success' => true, 'location' => $mapped]);
        } catch (Exception $e) {
            $this->SendDebug('GetVehicleLocation', 'Error: ' . $e->getMessage(), 0);
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        } finally {
            if ($services) { $this->persistAuthCache($services['auth']); }
        }
    }

    private function GetVehicleOdometerJSON(string $vehicleId): string
    {
        $services = null;
        try {
            $services = $this->createApiClientWithAuth();
            $odometer = $services['client']->getVehicleOdometer($vehicleId);
            $value = VehicleStatusMapper::mapOdometer($odometer);
            return json_encode(['success' => true, 'odometer' => $value]);
        } catch (Exception $e) {
            $this->SendDebug('GetVehicleOdometer', 'Error: ' . $e->getMessage(), 0);
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        } finally {
            if ($services) { $this->persistAuthCache($services['auth']); }
        }
    }

    private function ExecuteCommandJSON(string $vehicleId, string $commandType): string
    {
        $services = null;
        try {
            $services = $this->createApiClientWithAuth();
            $client = $services['client'];

            switch ($commandType) {
                case 'Lock':
                    $result = $client->lock($vehicleId);
                    break;
                case 'Unlock':
                    $result = $client->unlock($vehicleId);
                    break;
                case 'ClimateStop':
                    $result = $client->stopClimate($vehicleId);
                    break;
                case 'ChargeStart':
                    $result = $client->startCharge($vehicleId);
                    break;
                case 'ChargeStop':
                    $result = $client->stopCharge($vehicleId);
                    break;
                case 'RefreshVehicleStatus':
                    $result = $client->refreshVehicleStatus($vehicleId);
                    break;
                default:
                    return json_encode(['success' => false, 'error' => 'Unknown command type']);
            }

            return json_encode(['success' => true, 'result' => $result, 'type' => $commandType]);
        } catch (Exception $e) {
            $this->SendDebug('ExecuteCommand', $commandType . ' Error: ' . $e->getMessage(), 0);
            return json_encode(['success' => false, 'error' => $e->getMessage(), 'type' => $commandType]);
        } finally {
            if ($services) { $this->persistAuthCache($services['auth']); }
        }
    }

    private function ExecuteClimateStartJSON(string $vehicleId, float $temperature, bool $defrost): string
    {
        $services = null;
        try {
            $services = $this->createApiClientWithAuth();
            $result = $services['client']->startClimate($vehicleId, $temperature, $defrost);
            return json_encode(['success' => true, 'result' => $result, 'type' => 'ClimateStart']);
        } catch (Exception $e) {
            $this->SendDebug('ClimateStart', 'Error: ' . $e->getMessage(), 0);
            return json_encode(['success' => false, 'error' => $e->getMessage(), 'type' => 'ClimateStart']);
        } finally {
            if ($services) { $this->persistAuthCache($services['auth']); }
        }
    }

    private function ExecuteSetChargeTargetsJSON(string $vehicleId, int $limitAC, int $limitDC): string
    {
        $services = null;
        try {
            $services = $this->createApiClientWithAuth();
            $result = $services['client']->setChargeTargets($vehicleId, $limitAC, $limitDC);
            return json_encode(['success' => true, 'result' => $result, 'type' => 'SetChargeTargets']);
        } catch (Exception $e) {
            $this->SendDebug('SetChargeTargets', 'Error: ' . $e->getMessage(), 0);
            return json_encode(['success' => false, 'error' => $e->getMessage(), 'type' => 'SetChargeTargets']);
        } finally {
            if ($services) { $this->persistAuthCache($services['auth']); }
        }
    }

    private function GetCommandStatusJSON(string $vehicleId): string
    {
        // Command status is tracked in vehicle instances
        return json_encode(['success' => true, 'status' => 'ok']);
    }

    // ── Service factories ───────────────────────────────────────────

    private function getBrandConfig(): array
    {
        $brand = $this->ReadPropertyString('Brand');
        return BrandConfig::get($brand);
    }

    private function createStampService(): BluelinkStampService
    {
        $config = $this->getBrandConfig();
        return new BluelinkStampService($config['cfbKey'], $config['appId']);
    }

    private function createAuthService(): BluelinkAuthService
    {
        $config = $this->getBrandConfig();
        $baseUrl = $config['baseUrl'];
        $clientId = $config['clientId'];
        $basicToken = $config['basicToken'];
        $stampService = $this->createStampService();

        $auth = new BluelinkAuthService($baseUrl, $clientId, $basicToken, $stampService, $config['appId'], $config['pushType']);

        // Wire up logging
        $auth->setLogger(function (string $message) {
            $this->SendDebug('Auth', $message, 0);
        });

        $auth->setPin($this->ReadPropertyString('PIN'));

        $refreshToken = $this->ReadPropertyString('RefreshToken');
        if (!empty($refreshToken)) {
            $auth->setRefreshToken($refreshToken);
        }

        // Load cached tokens
        $tokenCache = $this->GetBuffer('TokenCache');
        $auth->loadTokenCache($tokenCache);

        return $auth;
    }

    /**
     * @return array{client: BluelinkApiClient, auth: BluelinkAuthService}
     */
    private function createApiClientWithAuth(): array
    {
        $auth = $this->createAuthService();
        $config = $this->getBrandConfig();
        $baseUrl = $config['baseUrl'];
        $client = new BluelinkApiClient($baseUrl, $auth);
        $client->setLogger(function (string $message) {
            $this->SendDebug('API', $message, 0);
        });

        // Eagerly trigger token refresh + device registration and persist immediately
        $auth->getAuthHeaders();
        $this->persistAuthCache($auth);

        return ['client' => $client, 'auth' => $auth];
    }

    private function persistAuthCache(BluelinkAuthService $auth): void
    {
        $tokenCache = $auth->getTokenCacheData();
        $this->SetBuffer('TokenCache', $tokenCache);
        $this->SendDebug('TokenCache', 'Persisted (' . strlen($tokenCache) . ' bytes)', 0);
    }

    private function getVehicleCCS2Support(string $vehicleId): int
    {
        $cached = $this->GetBuffer('VehicleList');
        if (!empty($cached)) {
            $vehicles = json_decode($cached, true);
            if (is_array($vehicles)) {
                foreach ($vehicles as $v) {
                    if (($v['VehicleId'] ?? '') === $vehicleId) {
                        return (int) ($v['CCS2Support'] ?? 0);
                    }
                }
            }
        }
        return 0;
    }

    private function hasValidConfig(): bool
    {
        $refreshToken = $this->ReadPropertyString('RefreshToken');
        $brand = $this->ReadPropertyString('Brand');
        return !empty($refreshToken) && in_array($brand, BrandConfig::getBrands());
    }

    // ── Debug with secret masking ───────────────────────────────────

    protected function SendDebug($Message, $Data, $Format)
    {
        $debugEnabled = $this->ReadPropertyBoolean('DebugEnabled');
        if (!$debugEnabled) {
            // Legacy-Compat: falls DebugEnabled noch nicht gespeichert ist
            $legacyLevel = 0;
            try {
                $legacyLevel = (int) $this->ReadPropertyInteger('DebugLevel');
            } catch (Throwable $e) {
                $legacyLevel = 0;
            }
            if ($legacyLevel <= 0) {
                return;
            }
        }

        // Mask secrets in debug output
        $maskedData = $Data;
        if (is_string($maskedData)) {
            $pin = $this->ReadPropertyString('PIN');
            $refreshToken = $this->ReadPropertyString('RefreshToken');
            if (!empty($pin)) {
                $maskedData = str_replace($pin, '****', $maskedData);
            }
            if (!empty($refreshToken)) {
                $maskedData = str_replace($refreshToken, BluelinkAuthService::maskSecret($refreshToken, 8), $maskedData);
            }
        }
        parent::SendDebug($Message, $maskedData, $Format);
    }
}
