<?php

declare(strict_types=1);

class BluelinkVehicle extends IPSModule
{
    private const DEFAULT_POLL_INTERVAL = 300;
    private const DEFAULT_FORCE_REFRESH_INTERVAL = 0;
    private const DEFAULT_CHARGING_POLL_INTERVAL = 900; // 15 minutes
    private const MIN_VEHICLE_REFRESH_INTERVAL = 600; // 10 minutes
    private const COMMAND_POLL_INTERVAL = 5;
    private const COMMAND_TIMEOUT = 120;

    public function Create()
    {
        parent::Create();

        $this->ConnectParent('{A1B2C3D4-5678-9ABC-DEF0-123456789ABC}');

        // Properties
        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyString('VehicleId', '');
        $this->RegisterPropertyInteger('PollInterval', self::DEFAULT_POLL_INTERVAL);
        $this->RegisterPropertyInteger('ForceRefreshInterval', self::DEFAULT_FORCE_REFRESH_INTERVAL);
        $this->RegisterPropertyBoolean('AllowVehicleRefresh', false);
        $this->RegisterPropertyBoolean('RefreshOnAction', false);
        $this->RegisterPropertyBoolean('ChargingPollEnabled', false);
        $this->RegisterPropertyInteger('ChargingPollInterval', self::DEFAULT_CHARGING_POLL_INTERVAL);
        $this->RegisterPropertyInteger('DebugLevel', 0);

        // Buffers
        $this->SetBuffer('StatusCache', '');
        $this->SetBuffer('LocationCache', '');
        $this->SetBuffer('CommandState', '');
        $this->SetBuffer('LastVehicleRefresh', '0');

        // Timers
        $this->RegisterTimer('PollStatus', 0, 'BL_UpdateStatus($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ForceRefreshStatus', 0, 'BL_ForceRefreshStatus($_IPS[\'TARGET\']);');
        $this->RegisterTimer('PollCommand', 0, 'BL_PollCommand($_IPS[\'TARGET\']);');

        $createValuePresentationEntry = static function ($value, string $caption): array {
            return [
                'Value'              => $value,
                'Caption'            => $caption,
                'IconActive'         => false,
                'IconValue'          => '',
                'ColorActive'        => false,
                'ColorValue'         => -1,
                'ContentColorActive' => false,
                'ContentColorValue'  => -1,
            ];
        };
        $openClosedPresentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([
                $createValuePresentationEntry(false, $this->Translate('Closed')),
                $createValuePresentationEntry(true, $this->Translate('Open')),
            ], JSON_UNESCAPED_UNICODE),
        ];
        $yesNoPresentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([
                $createValuePresentationEntry(false, $this->Translate('No')),
                $createValuePresentationEntry(true, $this->Translate('Yes')),
            ], JSON_UNESCAPED_UNICODE),
        ];
        $doorsLockedPresentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode([
                ['Value' => false, 'Caption' => $this->Translate('Unlocked'), 'Icon' => 'LockOpen', 'Color' => 0xFF0000, 'IconActive' => false],
                ['Value' => true, 'Caption' => $this->Translate('Locked'), 'Icon' => 'Lock', 'Color' => 0x00FF00, 'IconActive' => false],
            ], JSON_UNESCAPED_UNICODE),
        ];
        $createInterval = static function (int $min, int $max, string $text, int $color): array {
            return [
                'IntervalMinValue'   => $min,
                'IntervalMaxValue'   => $max,
                'ConstantActive'     => true,
                'ConstantValue'      => $text,
                'ConversionFactor'   => 1,
                'PrefixActive'       => false,
                'PrefixValue'        => '',
                'SuffixActive'       => false,
                'SuffixValue'        => '',
                'DigitsActive'       => false,
                'DigitsValue'        => 0,
                'IconActive'         => false,
                'IconValue'          => '',
                'ColorActive'        => true,
                'ColorValue'         => $color,
                'ContentColorActive' => false,
                'ContentColorValue'  => -1,
            ];
        };
        $chargingStatePresentation = [
            'PRESENTATION'    => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'            => 'Power',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'       => json_encode([
                $createInterval(0, 0, $this->Translate('Disconnected'), 0x808080),
                $createInterval(1, 1, $this->Translate('Plugged In'), 0xFFAA00),
                $createInterval(2, 2, $this->Translate('Charging'), 0x00FF00),
            ], JSON_UNESCAPED_UNICODE),
        ];

        // ── Variables: Status ───────────────────────────────────────
        $this->RegisterVariableBoolean('DoorsLocked', $this->Translate('Doors Locked'), $doorsLockedPresentation, 10);
        $this->EnableAction('DoorsLocked');

        $this->RegisterVariableBoolean('DoorOpenDriver', $this->Translate('Door Driver'), $openClosedPresentation, 11);
        $this->RegisterVariableBoolean('DoorOpenPassenger', $this->Translate('Door Passenger'), $openClosedPresentation, 12);
        $this->RegisterVariableBoolean('DoorOpenRearLeft', $this->Translate('Door Rear Left'), $openClosedPresentation, 13);
        $this->RegisterVariableBoolean('DoorOpenRearRight', $this->Translate('Door Rear Right'), $openClosedPresentation, 14);
        $this->RegisterVariableBoolean('TrunkOpen', $this->Translate('Trunk'), $openClosedPresentation, 15);
        $this->RegisterVariableBoolean('HoodOpen', $this->Translate('Hood'), $openClosedPresentation, 16);

        // ── Variables: Windows ──────────────────────────────────────
        $this->RegisterVariableBoolean('WindowOpenDriver', $this->Translate('Window Driver'), $openClosedPresentation, 20);
        $this->RegisterVariableBoolean('WindowOpenPassenger', $this->Translate('Window Passenger'), $openClosedPresentation, 21);
        $this->RegisterVariableBoolean('WindowOpenRearLeft', $this->Translate('Window Rear Left'), $openClosedPresentation, 22);
        $this->RegisterVariableBoolean('WindowOpenRearRight', $this->Translate('Window Rear Right'), $openClosedPresentation, 23);

        // ── Variables: EV & Charging ────────────────────────────────
        $this->RegisterVariableInteger('SOC', $this->Translate('Battery SOC'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Battery', 'SUFFIX' => ' %',
        ], 30);
        $this->RegisterVariableFloat('RangeKm', $this->Translate('Range'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Distance', 'SUFFIX' => ' km', 'DIGITS' => 1,
        ], 31);
        $this->RegisterVariableBoolean('PluggedIn', $this->Translate('Plugged In'), $yesNoPresentation, 32);
        $this->RegisterVariableInteger('ChargingState', $this->Translate('Charging State'), $chargingStatePresentation, 33);
        $this->RegisterVariableFloat('ChargingPowerKw', $this->Translate('Charging Power'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Electricity', 'SUFFIX' => ' kW', 'DIGITS' => 1,
        ], 34);
        $this->RegisterVariableInteger('RemainingChargeTimeMin', $this->Translate('Remaining Charge Time'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Clock', 'SUFFIX' => ' min',
        ], 35);
        $this->RegisterVariableInteger('ChargeLimitAC', $this->Translate('Charge Limit AC'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'ICON' => 'Battery', 'SUFFIX' => ' %',
            'MIN' => 50, 'MAX' => 100, 'STEP' => 10,
        ], 36);
        $this->EnableAction('ChargeLimitAC');
        $this->RegisterVariableInteger('ChargeLimitDC', $this->Translate('Charge Limit DC'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'ICON' => 'Battery', 'SUFFIX' => ' %',
            'MIN' => 50, 'MAX' => 100, 'STEP' => 10,
        ], 37);
        $this->EnableAction('ChargeLimitDC');

        // ── Variables: Climate ──────────────────────────────────────
        $this->RegisterVariableBoolean('ClimateOn', $this->Translate('Climate'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE' => 'Climate', 'ICON_FALSE' => 'Climate',
            'CAPTION_TRUE' => $this->Translate('On'), 'CAPTION_FALSE' => $this->Translate('Off'),
            'COLOR_TRUE' => 0x00FF00, 'COLOR_FALSE' => 0x808080,
        ], 40);
        $this->EnableAction('ClimateOn');
        $this->RegisterVariableFloat('TargetTempC', $this->Translate('Target Temperature'), '', 41);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('TargetTempC'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON' => 'Temperature', 'SUFFIX' => ' °C',
            'MIN' => 14.0, 'MAX' => 30.0, 'STEP' => 0.5, 'DIGITS' => 1,
        ]);
        $this->EnableAction('TargetTempC');
        $this->RegisterVariableBoolean('Defrost', $this->Translate('Defrost'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'CAPTION_TRUE' => $this->Translate('On'), 'CAPTION_FALSE' => $this->Translate('Off'),
            'COLOR_TRUE' => 0x00FF00, 'COLOR_FALSE' => 0x808080,
        ], 42);
        $this->RegisterVariableBoolean('SteeringHeat', $this->Translate('Steering Wheel Heating'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'CAPTION_TRUE' => $this->Translate('On'), 'CAPTION_FALSE' => $this->Translate('Off'),
            'COLOR_TRUE' => 0x00FF00, 'COLOR_FALSE' => 0x808080,
        ], 43);

        // ── Variables: Charging Action ──────────────────────────────
        $this->RegisterVariableBoolean('ChargeAction', $this->Translate('Charging'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE' => 'Battery', 'ICON_FALSE' => 'Battery',
            'CAPTION_TRUE' => $this->Translate('Start'), 'CAPTION_FALSE' => $this->Translate('Stop'),
            'COLOR_TRUE' => 0x00FF00, 'COLOR_FALSE' => 0xFF0000,
        ], 44);
        $this->EnableAction('ChargeAction');

        // ── Variables: Location ─────────────────────────────────────
        $this->RegisterVariableFloat('Latitude', $this->Translate('Latitude'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => '°', 'DIGITS' => 6,
        ], 50);
        $this->RegisterVariableFloat('Longitude', $this->Translate('Longitude'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => '°', 'DIGITS' => 6,
        ], 51);
        $this->RegisterVariableString('PositionTimestamp', $this->Translate('Position Timestamp'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Clock',
        ], 52);

        // ── Variables: Drive Data ───────────────────────────────────
        $this->RegisterVariableFloat('OdometerKm', $this->Translate('Odometer'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Distance', 'SUFFIX' => ' km', 'DIGITS' => 1,
        ], 60);
        $this->RegisterVariableInteger('FuelLevelPercent', $this->Translate('Fuel Level'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Gauge', 'SUFFIX' => ' %',
        ], 61);
        $this->RegisterVariableInteger('Battery12VPercent', $this->Translate('12V Battery'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Battery', 'SUFFIX' => ' %',
        ], 62);

        // ── Variables: Meta ─────────────────────────────────────────
        $this->RegisterVariableString('LastUpdateTimestamp', $this->Translate('Last Update'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Clock',
        ], 70);
        $this->RegisterVariableString('LastCommandTimestamp', $this->Translate('Last Command'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Clock',
        ], 71);
        $this->RegisterVariableBoolean('ApiOnline', $this->Translate('API Online'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE' => 'Ok', 'ICON_FALSE' => 'Warning',
            'CAPTION_TRUE' => $this->Translate('Online'), 'CAPTION_FALSE' => $this->Translate('Offline'),
            'COLOR_TRUE' => 0x00FF00, 'COLOR_FALSE' => 0xFF0000,
        ], 72);
        $this->RegisterVariableString('ErrorText', $this->Translate('Last Error'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Warning',
        ], 73);
        $this->RegisterVariableInteger('CloudRefreshCounter', $this->Translate('Cloud Refresh Counter'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Database',
        ], 74);
        $this->RegisterVariableInteger('VehicleRefreshCounter', $this->Translate('Vehicle Refresh Counter'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Car',
        ], 75);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // Set data filter on VIN
        $vin = $this->ReadPropertyString('VIN');
        if (!empty($vin)) {
            $this->SetReceiveDataFilter('.*"VIN":"' . preg_quote($vin) . '".*');
        }

        // Configure poll timer
        $interval = $this->ReadPropertyInteger('PollInterval');
        if ($interval === 0) {
            $this->SetTimerInterval('PollStatus', 0);
        } else {
            if ($interval < 60) {
                $interval = self::DEFAULT_POLL_INTERVAL;
            }
            $this->SetTimerInterval('PollStatus', $interval * 1000);
        }

        // Configure force refresh timer
        $forceInterval = $this->ReadPropertyInteger('ForceRefreshInterval');
        if ($forceInterval > 0 && $this->ReadPropertyBoolean('AllowVehicleRefresh')) {
            $this->SetTimerInterval('ForceRefreshStatus', $forceInterval * 1000);
        } else {
            $this->SetTimerInterval('ForceRefreshStatus', 0);
        }

        // Set status
        if (empty($vin) || empty($this->ReadPropertyString('VehicleId'))) {
            $this->SetStatus(IS_INACTIVE);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    // ── RequestAction ───────────────────────────────────────────────

    public function RequestAction($Ident, $Value)
    {
        $controlActions = ['DoorsLocked', 'ClimateOn', 'TargetTempC', 'ChargeAction', 'ChargeLimitAC', 'ChargeLimitDC'];
        if (in_array($Ident, $controlActions) && !$this->HasPIN()) {
            $this->SetValue('ErrorText', $this->Translate('PIN not configured. Remote actions require a PIN in the account settings.'));
            return;
        }

        switch ($Ident) {
            case 'DoorsLocked':
                $this->SetValue('DoorsLocked', (bool) $Value);
                $this->SetBuffer('SwitchCooldown', (string) time());
                if ($Value) {
                    $this->Lock();
                } else {
                    $this->Unlock();
                }
                break;

            case 'ClimateOn':
                $this->SetValue('ClimateOn', (bool) $Value);
                $this->SetBuffer('SwitchCooldown', (string) time());
                if ($Value) {
                    $temp = $this->GetValue('TargetTempC');
                    $this->ClimateStart($temp >= 16.0 ? $temp : 22.0);
                } else {
                    $this->ClimateStop();
                }
                break;

            case 'TargetTempC':
                $this->SetValue('TargetTempC', (float) $Value);
                if ($this->GetValue('ClimateOn')) {
                    $this->SetBuffer('SwitchCooldown', (string) time());
                    $this->ClimateStart((float) $Value);
                }
                break;

            case 'ChargeAction':
                $this->SetValue('ChargeAction', (bool) $Value);
                $this->SetBuffer('SwitchCooldown', (string) time());
                if ($Value) {
                    $this->ChargeStart();
                } else {
                    $this->ChargeStop();
                }
                break;

            case 'ChargeLimitAC':
                $this->SetValue('ChargeLimitAC', (int) $Value);
                $this->SetBuffer('ChargeLimitCooldown', (string) time());
                $this->SetChargeTargets((int) $Value, (int) $this->GetValue('ChargeLimitDC'));
                break;

            case 'ChargeLimitDC':
                $this->SetValue('ChargeLimitDC', (int) $Value);
                $this->SetBuffer('ChargeLimitCooldown', (string) time());
                $this->SetChargeTargets((int) $this->GetValue('ChargeLimitAC'), (int) $Value);
                break;

            default:
                $this->SendDebug('RequestAction', 'Unknown ident: ' . $Ident, 0);
                break;
        }
    }

    // ── Public Functions (PHP commands) ──────────────────────────────

    public function UpdateStatus(): void
    {
        $vehicleId = $this->ReadPropertyString('VehicleId');
        if (empty($vehicleId)) {
            return;
        }

        $this->SendDebug('UpdateStatus', 'Starting status poll', 0);

        // Get cached/cloud status (odometer is included in status response)
        $this->FetchAndApplyStatus($vehicleId);
        $this->FetchAndApplyLocation($vehicleId);
    }

    public function RefreshVehicleStatus(): void
    {
        $vehicleId = $this->ReadPropertyString('VehicleId');
        if (empty($vehicleId)) {
            return;
        }

        if (!$this->ReadPropertyBoolean('AllowVehicleRefresh')) {
            $this->SendDebug('RefreshVehicle', 'Vehicle refresh not allowed. Enable in settings.', 0);
            $this->SetValue('ErrorText', $this->Translate('Vehicle refresh not enabled'));
            return;
        }

        // Enforce minimum interval
        $lastRefresh = (int) $this->GetBuffer('LastVehicleRefresh');
        if ((time() - $lastRefresh) < self::MIN_VEHICLE_REFRESH_INTERVAL) {
            $remaining = self::MIN_VEHICLE_REFRESH_INTERVAL - (time() - $lastRefresh);
            $this->SendDebug('RefreshVehicle', 'Too soon. Wait ' . $remaining . 's', 0);
            $this->SetValue('ErrorText', sprintf($this->Translate('Vehicle refresh: wait %d minutes'), intval($remaining / 60)));
            return;
        }

        $this->ExecuteRemoteCommand('RefreshVehicleStatus', function () use ($vehicleId) {
            return $this->SendToParent('RefreshVehicleStatus', ['VehicleId' => $vehicleId]);
        });

        $this->SetBuffer('LastVehicleRefresh', (string) time());
        $this->SetValue('VehicleRefreshCounter', $this->GetValue('VehicleRefreshCounter') + 1);
    }

    public function ForceRefreshStatus(): void
    {
        $vehicleId = $this->ReadPropertyString('VehicleId');
        if (empty($vehicleId)) {
            return;
        }

        if (!$this->ReadPropertyBoolean('AllowVehicleRefresh')) {
            $this->SendDebug('ForceRefresh', 'Vehicle refresh not allowed', 0);
            return;
        }

        // Enforce minimum interval
        $lastRefresh = (int) $this->GetBuffer('LastVehicleRefresh');
        if ((time() - $lastRefresh) < self::MIN_VEHICLE_REFRESH_INTERVAL) {
            $this->SendDebug('ForceRefresh', 'Skipped, too soon since last refresh', 0);
            return;
        }

        $this->SendDebug('ForceRefresh', 'Waking vehicle for fresh data', 0);

        $this->ExecuteRemoteCommand('RefreshVehicleStatus', function () use ($vehicleId) {
            return $this->SendToParent('RefreshVehicleStatus', ['VehicleId' => $vehicleId]);
        });

        $this->SetBuffer('LastVehicleRefresh', (string) time());
        $this->SetValue('VehicleRefreshCounter', $this->GetValue('VehicleRefreshCounter') + 1);
    }

    public function RefreshLocation(): void
    {
        $vehicleId = $this->ReadPropertyString('VehicleId');
        if (empty($vehicleId)) {
            return;
        }
        $this->FetchAndApplyLocation($vehicleId);
    }

    public function Lock(): void
    {
        $vehicleId = $this->ReadPropertyString('VehicleId');
        $this->ExecuteRemoteCommand('Lock', function () use ($vehicleId) {
            return $this->SendToParent('Lock', ['VehicleId' => $vehicleId]);
        });
    }

    public function Unlock(): void
    {
        $vehicleId = $this->ReadPropertyString('VehicleId');
        $this->ExecuteRemoteCommand('Unlock', function () use ($vehicleId) {
            return $this->SendToParent('Unlock', ['VehicleId' => $vehicleId]);
        });
    }

    public function ClimateStart(float $temperature = 22.0): void
    {
        $vehicleId = $this->ReadPropertyString('VehicleId');
        $defrost = $this->GetValue('Defrost');
        $this->ExecuteRemoteCommand('ClimateStart', function () use ($vehicleId, $temperature, $defrost) {
            return $this->SendToParent('ClimateStart', [
                'VehicleId'   => $vehicleId,
                'Temperature' => $temperature,
                'Defrost'     => $defrost,
            ]);
        });
    }

    public function ClimateStop(): void
    {
        $vehicleId = $this->ReadPropertyString('VehicleId');
        $this->ExecuteRemoteCommand('ClimateStop', function () use ($vehicleId) {
            return $this->SendToParent('ClimateStop', ['VehicleId' => $vehicleId]);
        });
    }

    public function ChargeStart(): void
    {
        $vehicleId = $this->ReadPropertyString('VehicleId');
        $this->ExecuteRemoteCommand('ChargeStart', function () use ($vehicleId) {
            return $this->SendToParent('ChargeStart', ['VehicleId' => $vehicleId]);
        });
    }

    public function ChargeStop(): void
    {
        $vehicleId = $this->ReadPropertyString('VehicleId');
        $this->ExecuteRemoteCommand('ChargeStop', function () use ($vehicleId) {
            return $this->SendToParent('ChargeStop', ['VehicleId' => $vehicleId]);
        });
    }

    public function SetChargeTargets(int $limitAC, int $limitDC): void
    {
        $vehicleId = $this->ReadPropertyString('VehicleId');
        if (empty($vehicleId)) {
            return;
        }

        $this->SendDebug('SetChargeTargets', 'AC=' . $limitAC . '% DC=' . $limitDC . '%', 0);

        try {
            $result = $this->SendToParent('SetChargeTargets', [
                'VehicleId' => $vehicleId,
                'LimitAC'   => $limitAC,
                'LimitDC'   => $limitDC,
            ]);

            if (!($result['success'] ?? false)) {
                $error = $result['error'] ?? $this->Translate('Command failed');
                $this->SetValue('ErrorText', $error);
                $this->SendDebug('SetChargeTargets', 'Error: ' . $error, 0);
                return;
            }

            $this->SendDebug('SetChargeTargets', 'Success', 0);
            $this->SetValue('LastCommandTimestamp', date('d.m.Y H:i:s'));
            $this->SetValue('ErrorText', '');
        } catch (Exception $e) {
            $this->SetValue('ErrorText', $e->getMessage());
            $this->SendDebug('SetChargeTargets', 'Exception: ' . $e->getMessage(), 0);
        }
    }

    public function PollCommand(): void
    {
        $commandState = json_decode($this->GetBuffer('CommandState'), true);
        if (empty($commandState) || empty($commandState['active'])) {
            $this->SetTimerInterval('PollCommand', 0);
            return;
        }

        // Check timeout
        $elapsed = time() - ($commandState['startTime'] ?? 0);
        if ($elapsed > self::COMMAND_TIMEOUT) {
            $this->SetTimerInterval('PollCommand', 0);
            $this->SetBuffer('CommandState', '');
            $this->SetValue('ErrorText', $this->Translate('Command timeout') . ': ' . ($commandState['type'] ?? ''));
            $this->SendDebug('PollCommand', 'Command timed out after ' . $elapsed . 's', 0);

            if ($this->ReadPropertyBoolean('RefreshOnAction')) {
                $this->UpdateStatus();
            }
            return;
        }

        // Poll parent for command status
        $vehicleId = $this->ReadPropertyString('VehicleId');
        $result = $this->SendToParent('GetCommandStatus', ['VehicleId' => $vehicleId]);

        if (isset($result['success']) && $result['success']) {
            $this->SetTimerInterval('PollCommand', 0);
            $this->SetBuffer('CommandState', '');
            $this->SetValue('LastCommandTimestamp', date('d.m.Y H:i:s'));
            $this->SetValue('ErrorText', '');
            $this->SendDebug('PollCommand', 'Command completed: ' . ($commandState['type'] ?? ''), 0);

            if ($this->ReadPropertyBoolean('RefreshOnAction')) {
                // Wait briefly then refresh status
                IPS_Sleep(2000);
                $this->UpdateStatus();
            }
        }
    }

    // ── Private: Data Fetching ──────────────────────────────────────

    private function FetchAndApplyStatus(string $vehicleId): void
    {
        $result = $this->SendToParent('GetVehicleStatus', ['VehicleId' => $vehicleId]);

        if (!($result['success'] ?? false)) {
            $this->SetValue('ApiOnline', false);
            $this->SetValue('ErrorText', $result['error'] ?? $this->Translate('Status fetch failed'));
            return;
        }

        $status = $result['status'] ?? [];
        $this->SetBuffer('StatusCache', json_encode($status));
        $this->ApplyStatusToVariables($status);
        $this->SetValue('ApiOnline', true);
        $this->SetValue('ErrorText', '');
        $this->SetValue('LastUpdateTimestamp', date('d.m.Y H:i:s'));
        $this->SetValue('CloudRefreshCounter', $this->GetValue('CloudRefreshCounter') + 1);
    }

    private function FetchAndApplyLocation(string $vehicleId): void
    {
        $result = $this->SendToParent('GetVehicleLocation', ['VehicleId' => $vehicleId]);

        if (!($result['success'] ?? false)) {
            $this->SendDebug('Location', 'Failed: ' . ($result['error'] ?? 'unknown'), 0);
            return;
        }

        $location = $result['location'] ?? [];
        $this->SetBuffer('LocationCache', json_encode($location));

        if (isset($location['Latitude'])) {
            $this->SetValue('Latitude', (float) $location['Latitude']);
        }
        if (isset($location['Longitude'])) {
            $this->SetValue('Longitude', (float) $location['Longitude']);
        }
        if (isset($location['PositionTimestamp'])) {
            $this->SetValue('PositionTimestamp', (string) $location['PositionTimestamp']);
        }
    }

    private function FetchAndApplyOdometer(string $vehicleId): void
    {
        $result = $this->SendToParent('GetVehicleOdometer', ['VehicleId' => $vehicleId]);

        if (($result['success'] ?? false) && isset($result['odometer'])) {
            $this->SetValue('OdometerKm', (float) $result['odometer']);
        }
    }

    private function ApplyStatusToVariables(array $status): void
    {
        $map = [
            'DoorsLocked'            => ['type' => 'bool'],
            'DoorOpenDriver'         => ['type' => 'bool'],
            'DoorOpenPassenger'      => ['type' => 'bool'],
            'DoorOpenRearLeft'       => ['type' => 'bool'],
            'DoorOpenRearRight'      => ['type' => 'bool'],
            'TrunkOpen'              => ['type' => 'bool'],
            'HoodOpen'               => ['type' => 'bool'],
            'WindowOpenDriver'       => ['type' => 'bool'],
            'WindowOpenPassenger'    => ['type' => 'bool'],
            'WindowOpenRearLeft'     => ['type' => 'bool'],
            'WindowOpenRearRight'    => ['type' => 'bool'],
            'SOC'                    => ['type' => 'int'],
            'RangeKm'               => ['type' => 'float'],
            'PluggedIn'              => ['type' => 'bool'],
            'ChargingState'          => ['type' => 'int'],
            'ChargingPowerKw'        => ['type' => 'float'],
            'RemainingChargeTimeMin' => ['type' => 'int'],
            'ChargeLimitAC'          => ['type' => 'int'],
            'ChargeLimitDC'          => ['type' => 'int'],
            'ClimateOn'              => ['type' => 'bool'],
            'TargetTempC'            => ['type' => 'float'],
            'Defrost'                => ['type' => 'bool'],
            'SteeringHeat'           => ['type' => 'bool'],
            'OdometerKm'             => ['type' => 'float', 'skip_zero' => true],
            'FuelLevelPercent'       => ['type' => 'int', 'skip_negative' => true],
            'Battery12VPercent'      => ['type' => 'int', 'skip_negative' => true],
        ];

        // Adjust force refresh timer based on charging state
        $this->AdjustChargingPollTimer($status);

        $chargeLimitCooldown = (int) $this->GetBuffer('ChargeLimitCooldown');
        $skipChargeLimit = $chargeLimitCooldown > 0 && (time() - $chargeLimitCooldown) < 120;

        $switchCooldown = (int) $this->GetBuffer('SwitchCooldown');
        $skipSwitch = $switchCooldown > 0 && (time() - $switchCooldown) < 120;
        $switchIdents = ['DoorsLocked', 'ClimateOn', 'ChargeAction'];

        foreach ($map as $ident => $config) {
            if (!array_key_exists($ident, $status)) {
                continue;
            }

            if ($skipChargeLimit && ($ident === 'ChargeLimitAC' || $ident === 'ChargeLimitDC')) {
                continue;
            }

            if ($skipSwitch && in_array($ident, $switchIdents)) {
                continue;
            }

            $value = $status[$ident];

            // Skip null values (e.g. TargetTempC when climate is off)
            if ($value === null) {
                continue;
            }

            // Skip unavailable values
            if (($config['skip_negative'] ?? false) && $value < 0) {
                continue;
            }
            if (($config['skip_zero'] ?? false) && (float) $value == 0.0) {
                continue;
            }

            switch ($config['type']) {
                case 'bool':
                    $this->SetValue($ident, (bool) $value);
                    break;
                case 'int':
                    $this->SetValue($ident, (int) $value);
                    break;
                case 'float':
                    $this->SetValue($ident, (float) $value);
                    break;
            }
        }
    }

    private function AdjustChargingPollTimer(array $status): void
    {
        if (!$this->ReadPropertyBoolean('ChargingPollEnabled') || !$this->ReadPropertyBoolean('AllowVehicleRefresh')) {
            return;
        }

        $isCharging = ($status['ChargingState'] ?? 0) == 2;
        $wasCharging = $this->GetBuffer('WasCharging') === '1';
        $this->SetBuffer('WasCharging', $isCharging ? '1' : '0');

        if ($isCharging && !$wasCharging) {
            $chargingInterval = $this->ReadPropertyInteger('ChargingPollInterval');
            $this->SetTimerInterval('ForceRefreshStatus', $chargingInterval * 1000);
            $this->SendDebug('ChargingPoll', 'Charging detected, force refresh every ' . $chargingInterval . 's', 0);
        } elseif (!$isCharging && $wasCharging) {
            $normalInterval = $this->ReadPropertyInteger('ForceRefreshInterval');
            if ($normalInterval > 0) {
                $this->SetTimerInterval('ForceRefreshStatus', $normalInterval * 1000);
            } else {
                $this->SetTimerInterval('ForceRefreshStatus', 0);
            }
            $this->SendDebug('ChargingPoll', 'Charging stopped, restored normal force refresh interval', 0);
        }
    }

    // ── Private: Command Execution ──────────────────────────────────

    private function ExecuteRemoteCommand(string $type, callable $apiCall): void
    {
        // Check if a command is already active
        $commandState = json_decode($this->GetBuffer('CommandState'), true);
        if (!empty($commandState['active'])) {
            $elapsed = time() - ($commandState['startTime'] ?? 0);
            if ($elapsed < self::COMMAND_TIMEOUT) {
                $this->SendDebug('Command', 'Blocked: ' . $type . '. Active: ' . ($commandState['type'] ?? ''), 0);
                $this->SetValue('ErrorText', $this->Translate('Another command is still active'));
                return;
            }
        }

        $this->SendDebug('Command', 'Executing: ' . $type, 0);

        try {
            $result = $apiCall();

            if (($result['success'] ?? false)) {
                $this->SetBuffer('CommandState', json_encode([
                    'active'    => true,
                    'type'      => $type,
                    'startTime' => time(),
                ]));
                $this->SetTimerInterval('PollCommand', self::COMMAND_POLL_INTERVAL * 1000);
                $this->SetValue('ErrorText', '');
            } else {
                $this->SetValue('ErrorText', $result['error'] ?? $this->Translate('Command failed'));
            }
        } catch (Exception $e) {
            $this->SetValue('ErrorText', $e->getMessage());
            $this->SendDebug('Command', 'Error: ' . $e->getMessage(), 0);
        }
    }

    // ── Private: Data Flow ──────────────────────────────────────────

    private function HasPIN(): bool
    {
        $result = $this->SendToParent('HasPIN');
        return ($result['hasPIN'] ?? false);
    }

    private function SendToParent(string $command, array $data = []): array
    {
        $payload = array_merge($data, [
            'DataID'  => '{D7F8E9A0-B1C2-3D4E-5F6A-7B8C9D0E1F2A}',
            'Command' => $command,
            'VIN'     => $this->ReadPropertyString('VIN'),
        ]);

        try {
            $response = $this->SendDataToParent(json_encode($payload));
            return json_decode($response, true) ?: [];
        } catch (Exception $e) {
            $this->SendDebug('SendToParent', 'Error: ' . $e->getMessage(), 0);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

}
