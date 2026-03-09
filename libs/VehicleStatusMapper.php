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
            'ChargingPowerKw'        => self::getFloat($evStatus, 'batteryPower', 0.0, 'batteryStndChrgPower'),
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
