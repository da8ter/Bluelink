<?php

declare(strict_types=1);

/**
 * Dashboard tile of the vehicle instance: page from module.html + assets, state built from the
 * vehicle variables, trip log and driving statistics, pushed only on change (bundled by the
 * one-shot timer TilePush). Also fetches the trip log and the vehicle type for the tile.
 * Used by HyundaiBluelinkKiaConnectVehicle as a trait.
 */
trait VehicleTileTrait
{
    private const TILE_PUSH_MS = 300;

    /** Tile texts (English; translated through locale.json). */
    private const TILE_TEXTS = [
        'Never', 'just now', '%d min ago', '%d h ago', '%d h', '%d min', 'Today', 'Yesterday', 'Charging now', '%s left',
        'Plugged in', 'Not plugged in', 'Battery', 'Fuel', 'Charge limit', 'AC', 'DC', 'Tap again to unlock',
        'Locked', 'Unlocked', 'Lock', 'Climate', 'On', 'Off', 'Stop charging', 'Start charging',
        'Remote actions need the PIN in the account settings', 'Command running…', 'Driver', 'Passenger',
        'Rear left', 'Rear right', 'Doors', 'Open', 'All closed', 'Windows', 'Trunk', 'Closed', 'Hood', 'Defrost',
        'Steering wheel heating', '12V battery', 'Odometer', 'Vehicle', 'Position', 'Open map', 'Last trip',
        'Trip log', 'kWh/100 km', 'No trips yet', 'Trips', 'Distance', 'Driving time', 'Last 30 days', '%d trip',
        '%d trips', '%s idle', 'No data yet', 'Consumption', 'per driving day', 'Regeneration', 'Updated %s',
        'Online', 'Offline', 'Refresh', 'Tap again to wake the vehicle', 'Wake vehicle (drains the 12V battery)',
        'Overview', 'Statistics', 'Drive', 'Electronics', 'Battery care', 'Avg. speed',
    ];

    private function tileEnabled(): bool
    {
        return $this->ReadPropertyBoolean('Tile');
    }

    /** In ApplyChanges(): watch the own variables while the tile is on. */
    private function registerTileMessages(): void
    {
        foreach ($this->GetMessageList() as $senderID => $messages) {
            if (in_array(VM_UPDATE, $messages, true)) {
                $this->UnregisterMessage($senderID, VM_UPDATE);
            }
        }
        $type = defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1;
        $this->SetVisualizationType($this->tileEnabled() ? $type : 0);
        if (!$this->tileEnabled()) {
            return;
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            if (IPS_VariableExists($child)) {
                $this->RegisterMessage($child, VM_UPDATE);
            }
        }
        $this->SetBuffer('TileHash', '');
        $this->tileChanged();
    }

    /** Schedule a push; several changes in a row result in one push. */
    private function tileChanged(): void
    {
        if ($this->tileEnabled()) {
            $this->SetTimerInterval('TilePush', self::TILE_PUSH_MS);
        }
    }

    public function TilePush(): void
    {
        $this->SetTimerInterval('TilePush', 0);
        if (!$this->tileEnabled()) {
            return;
        }
        $state = $this->tileState();
        $hash = md5((string) json_encode($state));
        if ($hash === $this->GetBuffer('TileHash')) {
            return;
        }
        $this->SetBuffer('TileHash', $hash);
        $this->UpdateVisualizationValue((string) json_encode(['type' => 'state', 'now' => time()] + $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function GetVisualizationTile(): string
    {
        $texts = [];
        foreach (self::TILE_TEXTS as $text) {
            $texts[$text] = $this->Translate($text);
        }
        $config = ['texts' => $texts, 'state' => ['type' => 'state', 'now' => time()] + $this->tileState()];
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $asset = static fn (string $file): string => (string) file_get_contents(__DIR__ . '/../HyundaiBluelinkKiaConnectVehicle/assets/' . $file);
        return strtr((string) file_get_contents(__DIR__ . '/../HyundaiBluelinkKiaConnectVehicle/module.html'), [
            '/*STYLE*/'      => $asset('tile.css'),
            '/*CONFIG*/null' => (string) json_encode($config, $flags),
            '/*ICONS*/'      => $asset('icons.js'),
            '/*SCROLL*/'     => $asset('scroll.js'),
            '/*TILE*/'       => $asset('tile.js'),
        ]);
    }

    /** Actions only the tile triggers; false = ident does not belong here. */
    private function tileAction(string $Ident): bool
    {
        switch ($Ident) {
            case 'TileRefresh':
                $this->UpdateStatus();
                return true;
            case 'TileVehicleRefresh':
                $this->RefreshVehicleStatus();
                return true;
        }
        return false;
    }

    /**
     * Trip log of the last 31 days (cloud only, does not wake the car). The month list tells which
     * days changed; only those are fetched in detail.
     */
    public function UpdateTrips(): void
    {
        $vehicleId = $this->ReadPropertyString('VehicleId');
        if (empty($vehicleId) || !$this->tileEnabled()) {
            return;
        }
        $today = date('Y-m-d');
        $months = [date('Ym')];
        if ($this->GetBuffer('TripsPreviousMonth') !== $today) {
            // The window reaches into the previous month; its list rarely changes, once a day is enough
            $months[] = date('Ym', (int) strtotime('first day of last month'));
            $this->SetBuffer('TripsPreviousMonth', $today);
        }
        $counts = [];
        foreach ($months as $month) {
            $result = $this->SendToParent('GetTripInfo', ['VehicleId' => $vehicleId, 'PeriodType' => 0, 'Date' => $month]);
            if (!($result['success'] ?? false)) {
                $this->SendDebug('Trips', 'Month ' . $month . ' failed: ' . ($result['error'] ?? 'unknown'), 0);
                return;
            }
            $counts += TripLog::monthCounts($result['info'] ?? []);
        }

        $log = json_decode($this->ReadAttributeString('TripLog'), true) ?: [];
        $due = TripLog::due($log, $counts, $today);
        foreach ($due as $date) {
            $result = $this->SendToParent('GetTripInfo', ['VehicleId' => $vehicleId, 'PeriodType' => 1, 'Date' => str_replace('-', '', $date)]);
            if (!($result['success'] ?? false)) {
                $this->SendDebug('Trips', 'Day ' . $date . ' failed: ' . ($result['error'] ?? 'unknown'), 0);
                break;
            }
            $entry = TripLog::parseDay($result['info'] ?? []);
            if ($entry !== null) {
                $log[$date] = $entry;
            }
        }
        $this->SendDebug('Trips', sprintf('%d days with trips, %d fetched', count($counts), count($due)), 0);
        $this->WriteAttributeString('TripLog', json_encode(TripLog::prune($log, $today)));
        $this->tileChanged();
    }

    /** Model, year and drive type (EV, PHEV, HEV, …) from the vehicle list of the account. */
    private function refreshVehicleInfo(): void
    {
        $result = $this->SendToParent('GetVehicles');
        foreach ($result['vehicles'] ?? [] as $vehicle) {
            if (($vehicle['VIN'] ?? '') !== '' && $vehicle['VIN'] === $this->ReadPropertyString('VIN')) {
                $this->WriteAttributeString('VehicleInfo', json_encode([
                    'model' => (string) ($vehicle['Model'] ?? ''),
                    'year'  => (string) ($vehicle['ModelYear'] ?? ''),
                    'type'  => strtoupper((string) ($vehicle['Type'] ?? '')),
                ]));
                $this->tileChanged();
                return;
            }
        }
    }

    /** @return array<string,mixed> */
    private function tileState(): array
    {
        $value = fn (string $ident, $default) => @$this->GetIDForIdent($ident) ? $this->GetValue($ident) : $default;
        $info = json_decode($this->ReadAttributeString('VehicleInfo'), true) ?: [];
        $type = (string) ($info['type'] ?? '');
        $soc = (int) $value('SOC', 0);
        $plugged = (bool) $value('PluggedIn', false);
        $charging = (int) $value('ChargingState', 0);
        $fuel = (int) $value('FuelLevelPercent', 0);
        // Drive type from the vehicle list; without it from the data the car reports
        $ev = in_array($type, ['EV', 'PHEV'], true) || ($type === '' && ($soc > 0 || $plugged || $charging > 0));
        $hasFuel = $type !== 'EV' && ($type === 'PHEV' || $fuel > 0 || !$ev);

        $timestamp = static function (string $text, string $format): int {
            $date = DateTime::createFromFormat($format, $text);
            return $date === false ? 0 : $date->getTimestamp();
        };
        $lat = (float) $value('Latitude', 0.0);
        $lon = (float) $value('Longitude', 0.0);
        $command = json_decode($this->GetBuffer('CommandState'), true);

        return [
            'vehicle'   => ['model' => (string) ($info['model'] ?? ''), 'year' => (string) ($info['year'] ?? ''), 'type' => $type],
            'caps'      => ['ev' => $ev, 'fuel' => $hasFuel, 'pin' => $this->HasPIN(), 'vehicleRefresh' => $this->ReadPropertyBoolean('AllowVehicleRefresh')],
            'online'    => (bool) $value('ApiOnline', false),
            'error'     => (string) $value('ErrorText', ''),
            'updated'   => $timestamp((string) $value('LastUpdateTimestamp', ''), 'd.m.Y H:i:s'),
            'command'   => !empty($command['active']) ? (string) ($command['type'] ?? '') : '',
            'soc'       => $soc,
            'range'     => (float) $value('RangeKm', 0.0),
            'plugged'   => $plugged,
            'charging'  => $charging,
            'power'     => (float) $value('ChargingPowerKw', 0.0),
            'remaining' => (int) $value('RemainingChargeTimeMin', 0),
            'limitAC'   => (int) $value('ChargeLimitAC', 0),
            'limitDC'   => (int) $value('ChargeLimitDC', 0),
            'fuel'      => $fuel,
            'locked'    => (bool) $value('DoorsLocked', false),
            'doors'     => [(bool) $value('DoorOpenDriver', false), (bool) $value('DoorOpenPassenger', false), (bool) $value('DoorOpenRearLeft', false), (bool) $value('DoorOpenRearRight', false)],
            'windows'   => [(bool) $value('WindowOpenDriver', false), (bool) $value('WindowOpenPassenger', false), (bool) $value('WindowOpenRearLeft', false), (bool) $value('WindowOpenRearRight', false)],
            'trunk'     => (bool) $value('TrunkOpen', false),
            'hood'      => (bool) $value('HoodOpen', false),
            'climate'   => (bool) $value('ClimateOn', false),
            'target'    => (float) $value('TargetTempC', 22.0),
            'defrost'   => (bool) $value('Defrost', false),
            'steering'  => (bool) $value('SteeringHeat', false),
            'odometer'  => (float) $value('OdometerKm', 0.0),
            'battery12v' => (int) $value('Battery12VPercent', 0),
            'pos'       => ($lat != 0.0 || $lon != 0.0)
                ? ['lat' => $lat, 'lon' => $lon, 'ts' => $timestamp((string) $value('PositionTimestamp', ''), 'YmdHis')] : null,
            'days'      => (object) (json_decode($this->ReadAttributeString('DrivingDays'), true) ?: []),
            'trips'     => (object) (json_decode($this->ReadAttributeString('TripLog'), true) ?: []),
        ];
    }
}
