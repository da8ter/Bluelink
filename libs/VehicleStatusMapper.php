<?php

declare(strict_types=1);

class VehicleStatusMapper
{
    /**
     * Map raw API vehicle status to normalized structure
     */
    public static function mapStatus(array $rawStatus): array
    {
        $vehicleStatus = $rawStatus['vehicleStatus'] ?? $rawStatus;
        $evStatus = $rawStatus['evStatus'] ?? $vehicleStatus['evStatus'] ?? [];

        return [
            // Doors & Security
            'DoorsLocked'       => self::getBool($vehicleStatus, 'doorLock'),
            'DoorOpenDriver'    => self::getDoorState($vehicleStatus, 'doorOpen', 'frontLeft'),
            'DoorOpenPassenger' => self::getDoorState($vehicleStatus, 'doorOpen', 'frontRight'),
            'DoorOpenRearLeft'  => self::getDoorState($vehicleStatus, 'doorOpen', 'backLeft'),
            'DoorOpenRearRight' => self::getDoorState($vehicleStatus, 'doorOpen', 'backRight'),
            'TrunkOpen'         => self::getBool($vehicleStatus, 'trunkOpen'),
            'HoodOpen'          => self::getBool($vehicleStatus, 'hoodOpen'),

            // Windows
            'WindowOpenDriver'    => self::getWindowState($vehicleStatus, 'windowOpen', 'frontLeft'),
            'WindowOpenPassenger' => self::getWindowState($vehicleStatus, 'windowOpen', 'frontRight'),
            'WindowOpenRearLeft'  => self::getWindowState($vehicleStatus, 'windowOpen', 'backLeft'),
            'WindowOpenRearRight' => self::getWindowState($vehicleStatus, 'windowOpen', 'backRight'),

            // EV & Charging
            'SOC'                    => self::getInt($evStatus, 'batteryStatus', 0),
            'RangeKm'                => self::extractRange($evStatus),
            'PluggedIn'              => self::getBool($evStatus, 'batteryPlugin'),
            'ChargingState'          => self::getChargingState($evStatus),
            'ChargingPowerKw'        => self::extractChargingPower($evStatus),
            'RemainingChargeTimeMin' => self::getInt($evStatus, 'remainTime2', 0, 'atc', 'value'),
            'ChargeLimitAC'          => self::extractChargeLimit($evStatus, 1),
            'ChargeLimitDC'          => self::extractChargeLimit($evStatus, 0),

            // Climate
            'ClimateOn'    => self::getBool($vehicleStatus, 'airCtrlOn'),
            'TargetTempC'  => self::getBool($vehicleStatus, 'airCtrlOn')
                ? self::getTemperature($vehicleStatus, 'airTemp', 'value')
                : null,
            'Defrost'      => self::getBool($vehicleStatus, 'defrost'),
            'SteeringHeat' => (($vehicleStatus['steerWheelHeat'] ?? 0) === 1),

            // Drive data
            'OdometerKm'        => self::extractOdometer($rawStatus),
            'FuelLevelPercent'  => self::getInt($vehicleStatus, 'fuelLevel', -1),
            'Battery12VPercent' => self::getInt($vehicleStatus, 'battery', -1, 'batSoc'),
            'Battery12VState'   => self::getInt($vehicleStatus, 'battery', -1, 'batState'),

            // Timestamps
            'LastUpdateTimestamp' => self::getTimestamp($vehicleStatus),
        ];
    }

    /**
     * Map raw location data
     */
    public static function mapLocation(array $rawLocation): array
    {
        $coord = $rawLocation['coord'] ?? $rawLocation;
        return [
            'Latitude'          => self::getFloat($coord, 'lat', 0.0),
            'Longitude'         => self::getFloat($coord, 'lon', 0.0),
            'Speed'             => self::getInt($coord, 'speed', 0),
            'Heading'           => self::getInt($coord, 'heading', 0),
            'PositionTimestamp' => $rawLocation['time'] ?? date('c'),
        ];
    }

    /**
     * Map vehicle info from vehicle list
     */
    public static function mapVehicleInfo(array $vehicle): array
    {
        return [
            'VehicleId'   => $vehicle['vehicleId'] ?? '',
            'VIN'         => $vehicle['vin'] ?? '',
            'VehicleName' => $vehicle['vehicleName'] ?? $vehicle['nickname'] ?? '',
            'Model'       => $vehicle['vehicleName'] ?? '',
            'ModelYear'   => $vehicle['modelYear'] ?? '',
            'Type'        => $vehicle['type'] ?? '',
            'CCS2Support' => (int) ($vehicle['ccuCCS2ProtocolSupport'] ?? 0),
        ];
    }

    /**
     * Map odometer data
     */
    public static function mapOdometer(array $rawOdometer): float
    {
        $value = $rawOdometer['value'] ?? $rawOdometer['odometer'] ?? 0;
        $unit = $rawOdometer['unit'] ?? 1; // 1 = km
        if ($unit === 0) { // miles
            return round((float) $value * 1.60934, 1);
        }
        return round((float) $value, 1);
    }

    /**
     * Map CCS2 API response (state.Vehicle) to normalized structure
     */
    public static function mapStatusCCS2(array $ccs2Response): array
    {
        $state = $ccs2Response['state'] ?? $ccs2Response;

        $chargingDoor = self::nested($state, 'Green.ChargingDoor.State');
        $chargePortOpen = ($chargingDoor === 1);

        $connectorState = self::nested($state, 'Green.ChargingInformation.ConnectorFastening.State');
        $pluggedIn = ($connectorState !== null && $connectorState > 0);

        $remainTime = self::nested($state, 'Green.ChargingInformation.Charging.RemainTime');
        $chargingRemainingMin = ($remainTime !== null) ? (int) $remainTime : 0;
        $isCharging = ($chargingRemainingMin > 0);

        $chargingState = 0;
        if ($isCharging) {
            $chargingState = 2;
        } elseif ($pluggedIn || $chargePortOpen) {
            $chargingState = 1;
        }

        $soc = self::nested($state, 'Green.BatteryManagement.BatteryRemain.Ratio');
        $rangeKm = self::nested($state, 'Drivetrain.FuelSystem.DTE.Total');
        $realTimePower = self::nested($state, 'Green.Electric.SmartGrid.RealTimePower');
        $odometer = self::nested($state, 'Drivetrain.Odometer');

        $targetSocAC = self::nested($state, 'Green.ChargingInformation.TargetSoC.Standard');
        $targetSocDC = self::nested($state, 'Green.ChargingInformation.TargetSoC.Quick');

        $climateOn = (self::nested($state, 'Cabin.HVAC.Row1.Driver.Blower.SpeedLevel') ?? 0) > 0;
        $airTemp = self::nested($state, 'Cabin.HVAC.Row1.Driver.Temperature.Value');
        $defrostState = self::nested($state, 'Body.Windshield.Front.Defog.State');
        $defrost = ($defrostState === 1);
        $steerHeat = self::nested($state, 'Cabin.SteeringWheel.Heat.State');

        $battery12v = self::nested($state, 'Electronics.Battery.Level');

        return [
            // Doors & Security
            'DoorsLocked'       => self::ccs2AllDoorsLocked($state),
            'DoorOpenDriver'    => (bool) self::nested($state, 'Cabin.Door.Row1.Driver.Open'),
            'DoorOpenPassenger' => (bool) self::nested($state, 'Cabin.Door.Row1.Passenger.Open'),
            'DoorOpenRearLeft'  => (bool) self::nested($state, 'Cabin.Door.Row2.Left.Open'),
            'DoorOpenRearRight' => (bool) self::nested($state, 'Cabin.Door.Row2.Right.Open'),
            'TrunkOpen'         => (bool) self::nested($state, 'Body.Trunk.Open'),
            'HoodOpen'          => (bool) self::nested($state, 'Body.Hood.Open'),

            // Windows
            'WindowOpenDriver'    => (bool) self::nested($state, 'Cabin.Window.Row1.Driver.Open'),
            'WindowOpenPassenger' => (bool) self::nested($state, 'Cabin.Window.Row1.Passenger.Open'),
            'WindowOpenRearLeft'  => (bool) self::nested($state, 'Cabin.Window.Row2.Left.Open'),
            'WindowOpenRearRight' => (bool) self::nested($state, 'Cabin.Window.Row2.Right.Open'),

            // EV & Charging
            'SOC'                    => ($soc !== null) ? (int) $soc : 0,
            'RangeKm'                => ($rangeKm !== null) ? round((float) $rangeKm, 1) : 0.0,
            'PluggedIn'              => $pluggedIn,
            'ChargingState'          => $chargingState,
            'ChargingPowerKw'        => ($realTimePower !== null) ? round((float) $realTimePower, 1) : 0.0,
            'RemainingChargeTimeMin' => $chargingRemainingMin,
            'ChargeLimitAC'          => ($targetSocAC !== null) ? (int) $targetSocAC : 0,
            'ChargeLimitDC'          => ($targetSocDC !== null) ? (int) $targetSocDC : 0,

            // Climate
            'ClimateOn'    => $climateOn,
            'TargetTempC'  => ($airTemp !== null && $airTemp !== 'OFF') ? (float) $airTemp : null,
            'Defrost'      => $defrost,
            'SteeringHeat' => ($steerHeat === 1),

            // Drive data
            'OdometerKm'        => ($odometer !== null) ? round((float) $odometer, 1) : 0.0,
            'FuelLevelPercent'  => -1,
            'Battery12VPercent' => ($battery12v !== null) ? (int) $battery12v : -1,
            'Battery12VState'   => -1,

            // Timestamps
            'LastUpdateTimestamp' => date('c'),
        ];
    }

    /**
     * Navigate nested CCS2 state array using dot-notation path
     */
    private static function nested(array $data, string $path)
    {
        $keys = explode('.', $path);
        $current = $data;
        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }

    private static function ccs2AllDoorsLocked(array $state): bool
    {
        // CCS2: Lock=0 means locked, Lock=1 means unlocked (inverted)
        $doors = [
            'Cabin.Door.Row1.Driver.Lock',
            'Cabin.Door.Row1.Passenger.Lock',
            'Cabin.Door.Row2.Left.Lock',
            'Cabin.Door.Row2.Right.Lock',
        ];
        foreach ($doors as $path) {
            $val = self::nested($state, $path);
            if ($val === null) {
                continue;
            }
            if ((int) $val !== 0) {
                return false;
            }
        }
        return true;
    }

    // ── Helper methods ──────────────────────────────────────────────

    private static function getBool(array $data, string $key): bool
    {
        if (!isset($data[$key])) {
            return false;
        }
        $val = $data[$key];
        if (is_bool($val)) {
            return $val;
        }
        if (is_int($val)) {
            return $val > 0;
        }
        return in_array(strtolower((string) $val), ['true', '1', 'yes', 'on'], true);
    }

    private static function getInt(array $data, string $key, int $default = 0, ?string ...$subKeys): int
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        foreach ($subKeys as $subKey) {
            if ($subKey === null) {
                continue;
            }
            if (!is_array($value) || !isset($value[$subKey])) {
                return $default;
            }
            $value = $value[$subKey];
        }
        return (int) $value;
    }

    private static function getFloat(array $data, string $key, float $default = 0.0, ?string ...$subKeys): float
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        foreach ($subKeys as $subKey) {
            if ($subKey === null) {
                continue;
            }
            if (!is_array($value) || !isset($value[$subKey])) {
                return $default;
            }
            $value = $value[$subKey];
        }
        return (float) $value;
    }

    private static function getDoorState(array $data, string $key, string $position): bool
    {
        $doors = $data[$key] ?? [];
        if (!is_array($doors)) {
            return false;
        }
        return ((int) ($doors[$position] ?? 0)) > 0;
    }

    private static function getWindowState(array $data, string $key, string $position): bool
    {
        $windows = $data[$key] ?? [];
        if (!is_array($windows)) {
            return false;
        }
        return ((int) ($windows[$position] ?? 0)) > 0;
    }

    private static function getChargingState(array $evStatus): int
    {
        if (empty($evStatus)) {
            return 0; // Unknown
        }
        $charging = $evStatus['batteryCharge'] ?? false;
        $pluggedIn = $evStatus['batteryPlugin'] ?? 0;

        if ($charging === true || $charging === 1) {
            return 2; // Charging
        }
        if ($pluggedIn > 0) {
            return 1; // Plugged in, not charging
        }
        return 0; // Not plugged in
    }

    private static function getTemperature(array $data, string $key, string $subKey): float
    {
        $temp = $data[$key] ?? [];
        if (!is_array($temp)) {
            return 20.0;
        }
        $value = $temp[$subKey] ?? null;
        if ($value === null) {
            return 20.0;
        }
        return self::tempCodeToCelsius($value);
    }

    public static function tempCodeToCelsius($value): float
    {
        // API returns hex-encoded index like "14H" → strip "H", parse hex
        // EU temperature_range = [14.0, 14.5, 15.0, ..., 30.0] (index 0..32)
        if (is_string($value) && preg_match('/^[0-9A-Fa-f]{1,2}H$/', $value)) {
            $hex = str_replace('H', '', $value);
            $index = intval($hex, 16);
            $celsius = 14.0 + $index * 0.5;
            return max(14.0, min(30.0, $celsius));
        }
        // Fallback: direct numeric value (already in Celsius)
        $numeric = (float) $value;
        if ($numeric >= 14.0 && $numeric <= 30.0) {
            return $numeric;
        }
        return 20.0;
    }

    private static function getTimestamp(array $data): string
    {
        return $data['time'] ?? $data['lastStatusDate'] ?? date('c');
    }

    private static function extractChargeLimit(array $evStatus, int $plugType): int
    {
        $targetSOClist = $evStatus['reservChargeInfos']['targetSOClist'] ?? [];
        if (!is_array($targetSOClist)) {
            return 0;
        }
        foreach (array_reverse($targetSOClist) as $entry) {
            if (isset($entry['plugType']) && (int) $entry['plugType'] === $plugType) {
                return (int) ($entry['targetSOClevel'] ?? 0);
            }
        }
        return 0;
    }

    private static function extractRange(array $evStatus): float
    {
        $drvDistance = $evStatus['drvDistance'] ?? [];
        if (!is_array($drvDistance) || empty($drvDistance)) {
            return 0.0;
        }
        // drvDistance is an indexed array, take first entry
        $entry = $drvDistance[0] ?? [];
        $totalRange = $entry['rangeByFuel']['totalAvailableRange'] ?? null;
        if (is_array($totalRange) && isset($totalRange['value'])) {
            return round((float) $totalRange['value'], 1);
        }
        // Fallback: try evModeRange for pure EV
        $evRange = $entry['rangeByFuel']['evModeRange'] ?? null;
        if (is_array($evRange) && isset($evRange['value'])) {
            return round((float) $evRange['value'], 1);
        }
        return 0.0;
    }

    private static function extractChargingPower(array $evStatus): float
    {
        $bp = $evStatus['batteryPower'] ?? null;
        if ($bp === null) {
            return 0.0;
        }
        // Direct numeric value (most common in EU API)
        if (is_numeric($bp)) {
            return round((float) $bp, 1);
        }
        // Nested object: try standard and fast charging power
        if (is_array($bp)) {
            $std = $bp['batteryStndChrgPower'] ?? 0;
            $fst = $bp['batteryFstChrgPower'] ?? 0;
            $power = max((float) $std, (float) $fst);
            return round($power, 1);
        }
        return 0.0;
    }

    private static function extractOdometer(array $rawStatus): float
    {
        $odometer = $rawStatus['odometer'] ?? null;
        if (is_array($odometer) && isset($odometer['value'])) {
            $value = (float) $odometer['value'];
            $unit = $odometer['unit'] ?? 1;
            if ($unit === 0) {
                return round($value * 1.60934, 1);
            }
            return round($value, 1);
        }
        return 0.0;
    }
}
