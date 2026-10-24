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
        'Overview', 'Statistics', 'Drive', 'Electronics', 'Battery care', 'Avg. speed', 'Close', 'Vehicle data: %s', 'Cloud queried %s', 'last on',
        'Last report from the car: %s. Climate and charging may have changed since then.', 'last reported', 'Older trips', 'Loading…',
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
    private function tileAction(string $Ident, $Value = null): bool
    {
        switch ($Ident) {
            case 'TileTrips':
                // One month of the stored log for the tile; the answer carries the request id of the
                // asking window (every open client receives every push)
                $request = json_decode((string) $Value, true);
                $month = (string) ($request['month'] ?? '');
                $rid = (string) ($request['rid'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}$/', $month) || !preg_match('/^[a-z0-9]{1,16}$/', $rid)) {
                    return true;
                }
                $days = array_filter($this->readTripLog(), static fn ($date): bool => strncmp((string) $date, $month, 7) === 0, ARRAY_FILTER_USE_KEY);
                $this->UpdateVisualizationValue((string) json_encode(['type' => 'trips', 'rid' => $rid, 'month' => $month, 'days' => (object) $days], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return true;
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

        $keepDays = max(0, $this->ReadPropertyInteger('TripLogDays'));
        $log = $this->readTripLog();
        $due = TripLog::due($log, $counts, $today, $keepDays);
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
        // Older days from the backfill with the calls left in this run
        $pending = json_decode($this->ReadAttributeString('TripBackfill'), true) ?: [];
        $older = TripLog::backfillDue($pending, $log, $today, $keepDays, TripLog::MAX_DAY_CALLS - count($due));
        foreach ($older as $date) {
            $result = $this->SendToParent('GetTripInfo', ['VehicleId' => $vehicleId, 'PeriodType' => 1, 'Date' => str_replace('-', '', $date)]);
            if (!($result['success'] ?? false)) {
                $this->SendDebug('Trips', 'Backfill day ' . $date . ' failed: ' . ($result['error'] ?? 'unknown'), 0);
                break;
            }
            $entry = TripLog::parseDay($result['info'] ?? []);
            if ($entry !== null) {
                $log[$date] = $entry;
            }
        }
        if ($pending !== []) {
            $this->WriteAttributeString('TripBackfill', json_encode((object) TripLog::backfillLeft($pending, $log, $today, $keepDays)));
        }
        $this->SendDebug('Trips', sprintf('%d days with trips, %d fetched, %d older fetched', count($counts), count($due), count($older)), 0);
        $this->writeTripLog(TripLog::prune($log, $today, $keepDays));
        $this->tileChanged();

        if (!$this->ReadAttributeBoolean('TripBackfillChecked')) {
            $this->BackfillTrips();
        }
    }

    /**
     * Looks for older trips in the cloud: walks back month by month (one call each) until three
     * months in a row have none and remembers the days with trips. Their details are fetched by
     * UpdateTrips bit by bit (at most MAX_DAY_CALLS per run), which spares the API quota.
     */
    public function BackfillTrips(): string
    {
        $vehicleId = $this->ReadPropertyString('VehicleId');
        if (empty($vehicleId) || !$this->tileEnabled()) {
            return $this->Translate('The trip log needs the dashboard tile.');
        }
        $pending = json_decode($this->ReadAttributeString('TripBackfill'), true) ?: [];
        $month = new DateTime('first day of last month');
        $checked = 0;
        $empty = 0;
        $oldest = '';
        for ($i = 0; $i < TripLog::BACKFILL_MONTHS && $empty < TripLog::BACKFILL_EMPTY_STOP; $i++) {
            $result = $this->SendToParent('GetTripInfo', ['VehicleId' => $vehicleId, 'PeriodType' => 0, 'Date' => $month->format('Ym')]);
            if (!($result['success'] ?? false)) {
                $this->SendDebug('Backfill', 'Month ' . $month->format('Ym') . ' failed: ' . ($result['error'] ?? 'unknown'), 0);
                break;
            }
            $checked++;
            $counts = TripLog::monthCounts($result['info'] ?? []);
            $empty = $counts === [] ? $empty + 1 : 0;
            foreach ($counts as $date => $count) {
                if ($count > 0) {
                    $pending[$date] = $count;
                    $oldest = $oldest === '' || $date < $oldest ? $date : $oldest;
                }
            }
            $month->modify('-1 month');
        }
        if ($checked === 0) {
            return $this->Translate('The cloud did not answer. Please try again later.');
        }
        $keepDays = max(0, $this->ReadPropertyInteger('TripLogDays'));
        $pending = TripLog::backfillLeft($pending, $this->readTripLog(), date('Y-m-d'), $keepDays);
        $this->WriteAttributeString('TripBackfill', json_encode((object) $pending));
        $this->WriteAttributeBoolean('TripBackfillChecked', true);
        $this->SendDebug('Backfill', sprintf('%d months checked, oldest trip %s, %d days to load', $checked, $oldest ?: '-', count($pending)), 0);
        if ($oldest === '') {
            return sprintf($this->Translate('%d months checked, the cloud has no older trips.'), $checked);
        }
        return sprintf($this->Translate('%d months checked, trips since %s. %d days are loaded bit by bit (up to %d per hour).'),
            $checked, date('d.m.Y', (int) strtotime($oldest)), count($pending), TripLog::MAX_DAY_CALLS);
    }

    /** Whole trip log as JSON (date => day with trips), newest first. */
    public function GetTripLog(): string
    {
        return (string) json_encode((object) $this->readTripLog());
    }

    /**
     * The trip log lives in a hidden document media object below the instance, not in an
     * attribute: unlimited retention would otherwise grow settings.json with every change.
     */
    private function tripLogMedia(bool $create): int
    {
        $id = @IPS_GetObjectIDByIdent('TripLogData', $this->InstanceID);
        if ($id !== false) {
            return (int) $id;
        }
        if (!$create) {
            return 0;
        }
        $id = IPS_CreateMedia(MEDIATYPE_DOCUMENT);
        IPS_SetParent($id, $this->InstanceID);
        IPS_SetIdent($id, 'TripLogData');
        IPS_SetName($id, $this->Translate('Trip log'));
        IPS_SetHidden($id, true);
        // File first, then content; otherwise Symcon does not take the content
        IPS_SetMediaFile($id, 'media/bluelink_trips_' . $this->InstanceID . '.json', false);
        IPS_SetMediaContent($id, base64_encode('{}'));
        return $id;
    }

    private function readTripLog(): array
    {
        $id = $this->tripLogMedia(false);
        return $id === 0 ? [] : (json_decode((string) base64_decode(IPS_GetMediaContent($id)), true) ?: []);
    }

    private function writeTripLog(array $log): void
    {
        IPS_SetMediaContent($this->tripLogMedia(true), base64_encode((string) json_encode((object) $log)));
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
        $log = $this->readTripLog();

        return [
            'vehicle'   => ['model' => (string) ($info['model'] ?? ''), 'year' => (string) ($info['year'] ?? ''), 'type' => $type],
            'caps'      => ['ev' => $ev, 'fuel' => $hasFuel, 'pin' => $this->HasPIN(), 'vehicleRefresh' => $this->ReadPropertyBoolean('AllowVehicleRefresh')],
            'online'    => (bool) $value('ApiOnline', false),
            'error'     => (string) $value('ErrorText', ''),
            'updated'   => $timestamp((string) $value('LastUpdateTimestamp', ''), 'd.m.Y H:i:s'),
            'carTime'   => $timestamp((string) $value('VehicleTimestamp', ''), 'd.m.Y H:i:s'),
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
            // Only the last days; the whole log can be large (unlimited retention). Older months on request.
            'trips'     => (object) TripLog::window($log, date('Y-m-d')),
            'tripOldest' => $log === [] ? '' : (string) min(array_map('strval', array_keys($log))),
        ];
    }
}
