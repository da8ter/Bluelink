<?php

declare(strict_types=1);

/**
 * Additional vehicle values (VehicleExtras) as variables of the vehicle instance and as rows of the tile.
 * A variable is created the first time the car reports its value, so cars without a sensor get no empty
 * variables. Used by HyundaiBluelinkKiaConnectVehicle as a trait.
 */
trait VehicleExtrasTrait
{
    /** Variables: ident => [type, caption, presentation key, position]. */
    private const EXTRA_VARIABLES = [
        'TirePressureFL' => ['float', 'Tire pressure front left', 'bar', 60], 'TirePressureFR' => ['float', 'Tire pressure front right', 'bar', 61],
        'TirePressureRL' => ['float', 'Tire pressure rear left', 'bar', 62], 'TirePressureRR' => ['float', 'Tire pressure rear right', 'bar', 63],
        'TireWarnAll' => ['bool', 'Tire pressure warning', 'warn', 64], 'TireWarnFL' => ['bool', 'Tire pressure warning front left', 'warn', 65],
        'TireWarnFR' => ['bool', 'Tire pressure warning front right', 'warn', 66], 'TireWarnRL' => ['bool', 'Tire pressure warning rear left', 'warn', 67],
        'TireWarnRR' => ['bool', 'Tire pressure warning rear right', 'warn', 68],
        'WasherFluidLow' => ['bool', 'Washer fluid low', 'warn', 70], 'BrakeFluidWarning' => ['bool', 'Brake fluid warning', 'warn', 71],
        'SmartKeyBatteryLow' => ['bool', 'Key battery low', 'warn', 72], 'OilLevelWarning' => ['bool', 'Oil level warning', 'warn', 73],
        'Battery12VFail' => ['bool', '12V battery fault', 'warn', 74], 'LampWarning' => ['bool', 'Lamp fault', 'warn', 75],
        'FuelLow' => ['bool', 'Fuel level low', 'warn', 76],
        'OutsideTempC' => ['float', 'Outside temperature', 'celsius', 80], 'RearWindowHeat' => ['bool', 'Rear window heating', 'onoff', 81],
        'MirrorHeat' => ['bool', 'Mirror heating', 'onoff', 82], 'SeatFL' => ['string', 'Seat front left', 'text', 83],
        'SeatFR' => ['string', 'Seat front right', 'text', 84], 'SeatRL' => ['string', 'Seat rear left', 'text', 85],
        'SeatRR' => ['string', 'Seat rear right', 'text', 86],
        'SunroofOpen' => ['bool', 'Sunroof', 'open', 90], 'DoorLockedFL' => ['bool', 'Lock driver door', 'locked', 91],
        'DoorLockedFR' => ['bool', 'Lock passenger door', 'locked', 92], 'DoorLockedRL' => ['bool', 'Lock rear left door', 'locked', 93],
        'DoorLockedRR' => ['bool', 'Lock rear right door', 'locked', 94], 'EngineRunning' => ['bool', 'Ready to drive', 'onoff', 95],
        'DriveMode' => ['string', 'Drive mode', 'text', 96],
        'BatterySoH' => ['float', 'Battery health (SoH)', 'percent', 100], 'BatteryRemainKwh' => ['float', 'Battery energy', 'kwh', 101],
        'BatteryCapacityKwh' => ['float', 'Battery capacity', 'kwh', 102], 'EstChargeFastMin' => ['int', 'Charging time fast charger', 'minutes', 103],
        'EstChargePortableMin' => ['int', 'Charging time portable charger', 'minutes', 104],
        'EstChargeStationMin' => ['int', 'Charging time wall box', 'minutes', 105],
        'RangeTargetAC' => ['float', 'Range at charge limit AC', 'km', 106], 'RangeTargetDC' => ['float', 'Range at charge limit DC', 'km', 107],
        'Departure1' => ['string', 'Departure 1', 'text', 110], 'Departure2' => ['string', 'Departure 2', 'text', 111],
        'OffPeak' => ['string', 'Off-peak charging', 'text', 112], 'ScheduledCharging' => ['bool', 'Scheduled charging', 'onoff', 113],
        'TotalConsumedKwh' => ['float', 'Total energy consumed', 'kwh', 120], 'TotalRegenKwh' => ['float', 'Total energy recovered', 'kwh', 121],
        'Consumption30d' => ['float', 'Consumption last 30 days', 'consumption', 122],
    ];

    /** Seat climate states 0–8 (hyundai_kia_connect_api SEAT_STATUS). */
    private const SEAT_STATES = ['Off', 'On', 'Off', 'Cooling low', 'Cooling medium', 'Cooling high', 'Heating low', 'Heating medium', 'Heating high'];
    private const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /** Rows of the tile card "Vehicle" built from these variables: key => [label, shown by default]. */
    private const EXTRA_ROWS = [
        'tires' => ['Tire pressure', true], 'tireWarn' => ['Tire pressure warning', true], 'outside' => ['Outside temperature', true],
        'soh' => ['Battery health (SoH)', false], 'energy' => ['Battery energy', false], 'estCharge' => ['Charging time', false],
        'rangeTarget' => ['Range at charge limit', false], 'washer' => ['Washer fluid low', false], 'brake' => ['Brake fluid warning', false],
        'keyBattery' => ['Key battery low', false], 'oil' => ['Oil level warning', false], 'aux12v' => ['12V battery fault', false],
        'lamps' => ['Lamp fault', false], 'fuelLow' => ['Fuel level low', false], 'seats' => ['Seats', false],
        'rearHeat' => ['Rear window heating', false], 'mirrorHeat' => ['Mirror heating', false], 'sunroof' => ['Sunroof', false],
        'doorLocks' => ['Door locks', false], 'engine' => ['Ready to drive', false], 'driveMode' => ['Drive mode', false],
        'departure1' => ['Departure 1', false], 'departure2' => ['Departure 2', false], 'offPeak' => ['Off-peak charging', false],
        'totals' => ['Total energy consumed', false], 'consumption30' => ['Consumption last 30 days', false],
    ];

    /** Sets the reported values; creates the variable on the first report. */
    private function applyExtras(array $values): void
    {
        foreach (self::EXTRA_VARIABLES as $ident => [$type, $caption, $presentation, $position]) {
            $value = $values[$ident] ?? null;
            if ($value === null) {
                continue;
            }
            $value = match ($ident) {
                'SeatFL', 'SeatFR', 'SeatRL', 'SeatRR' => $this->Translate(self::SEAT_STATES[(int) $value] ?? 'Off'),
                'Departure1', 'Departure2'             => $this->departureText($value),
                'OffPeak'                              => $this->offPeakText($value),
                default                                => $value,
            };
            if (!@$this->GetIDForIdent($ident)) {
                $varType = ['bool' => VARIABLETYPE_BOOLEAN, 'int' => VARIABLETYPE_INTEGER, 'float' => VARIABLETYPE_FLOAT, 'string' => VARIABLETYPE_STRING][$type];
                $this->MaintainVariable($ident, $this->Translate($caption), $varType, $this->extraPresentation($presentation), $position, true);
            }
            $this->SetValue($ident, match ($type) { 'bool' => (bool) $value, 'int' => (int) $value, 'float' => (float) $value, default => (string) $value });
        }
    }

    private function extraPresentation(string $key): array
    {
        $value = static fn (string $suffix, int $digits, string $icon): array => ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => $suffix, 'DIGITS' => $digits, 'ICON' => $icon];
        $options = function (array $false, array $true): array {
            $entry = fn (bool $v, array $o): array => ['Value' => $v, 'Caption' => $this->Translate($o[0]), 'IconActive' => false, 'IconValue' => '',
                'ColorActive' => $o[1] >= 0, 'ColorValue' => $o[1], 'ContentColorActive' => false, 'ContentColorValue' => -1];
            return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'OPTIONS' => json_encode([$entry(false, $false), $entry(true, $true)])];
        };
        return match ($key) {
            'bar'         => $value(' bar', 2, 'tire-pressure-warning'),
            'celsius'     => $value(' °C', 1, 'temperature-half'),
            'percent'     => $value(' %', 1, 'battery-half'),
            'kwh'         => $value(' kWh', 1, 'bolt'),
            'minutes'     => $value(' min', 0, 'clock'),
            'km'          => $value(' km', 0, 'route'),
            'consumption' => $value(' kWh/100 km', 1, 'gauge'),
            'warn'        => $options(['OK', 0x30D158], ['Warning', 0xFF453A]),
            'onoff'       => $options(['Off', -1], ['On', 0x30D158]),
            'open'        => $options(['Closed', -1], ['Open', 0xFF9F0A]),
            'locked'      => $options(['Unlocked', 0xFF9F0A], ['Locked', -1]),
            default       => ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
        };
    }

    private function departureText(array $d): string
    {
        if (empty($d['enabled'])) {
            return $this->Translate('Off');
        }
        $parts = [];
        $days = array_map(fn (int $i): string => $this->Translate(self::WEEKDAYS[$i]), $d['days'] ?? []);
        $parts[] = trim(implode(', ', $days) . ' ' . ($d['time'] ?? ''));
        if (!empty($d['climate'])) {
            $parts[] = $this->Translate('Climate') . ($d['temp'] !== null ? ' ' . number_format((float) $d['temp'], 1, ',', '') . ' °C' : '');
        }
        if (!empty($d['defrost'])) {
            $parts[] = $this->Translate('Defrost');
        }
        return implode(' · ', array_filter($parts));
    }

    private function offPeakText(array $p): string
    {
        if ($p['start'] === null || $p['end'] === null || $p['start'] === $p['end']) { // gleiche Zeiten = nicht eingerichtet
            return $this->Translate('Off');
        }
        return $p['start'] . '–' . $p['end'] . ($p['only'] ? ' · ' . $this->Translate('only off-peak') : '');
    }

    /** Tile rows of the additional values: key => [icon, label, text, warn]; rows without data are left out. */
    private function extraRows(): array
    {
        $has = fn (string $ident): bool => (bool) @$this->GetIDForIdent($ident);
        $text = fn (string $ident): string => GetValueFormatted($this->GetIDForIdent($ident));
        $bool = fn (string $ident): bool => (bool) $this->GetValue($ident);
        $rows = [];
        $row = function (string $key, string $icon, string $text, bool $warn = false) use (&$rows): void {
            $rows[$key] = [$icon, $this->Translate(self::EXTRA_ROWS[$key][0]), $text, $warn];
        };
        $corners = ['FL', 'FR', 'RL', 'RR'];
        if (array_filter($corners, fn (string $c): bool => $has('TirePressure' . $c))) {
            $row('tires', 'tire', implode(' · ', array_map(fn (string $c): string => $has('TirePressure' . $c)
                ? number_format((float) $this->GetValue('TirePressure' . $c), 1, ',', '') : '–', $corners)) . ' bar',
                (bool) array_filter($corners, fn (string $c): bool => $has('TireWarn' . $c) && $bool('TireWarn' . $c)));
        }
        if ($has('TireWarnAll')) {
            $warn = $bool('TireWarnAll') || (bool) array_filter($corners, fn (string $c): bool => $has('TireWarn' . $c) && $bool('TireWarn' . $c));
            $row('tireWarn', 'tire', $this->Translate($warn ? 'Warning' : 'OK'), $warn);
        }
        if ($has('OutsideTempC')) {
            $row('outside', 'climate', $text('OutsideTempC'));
        }
        if ($has('BatterySoH')) {
            $row('soh', 'battery', $text('BatterySoH'));
        }
        if ($has('BatteryRemainKwh')) {
            $row('energy', 'battery', $text('BatteryRemainKwh') . ($has('BatteryCapacityKwh') ? ' / ' . $text('BatteryCapacityKwh') : ''));
        }
        $est = array_filter(['EstChargeStationMin' => 'AC', 'EstChargeFastMin' => 'DC'], fn (string $l, string $i): bool => $has($i) && $this->GetValue($i) > 0, ARRAY_FILTER_USE_BOTH);
        if ($est !== []) {
            $row('estCharge', 'clock', implode(' · ', array_map(fn (string $i, string $l): string => $l . ' ' . $text($i), array_keys($est), $est)));
        }
        if ($has('RangeTargetAC') || $has('RangeTargetDC')) {
            $row('rangeTarget', 'route', implode(' · ', array_filter([$has('RangeTargetAC') ? 'AC ' . $text('RangeTargetAC') : '', $has('RangeTargetDC') ? 'DC ' . $text('RangeTargetDC') : ''])));
        }
        foreach (['washer' => 'WasherFluidLow', 'brake' => 'BrakeFluidWarning', 'keyBattery' => 'SmartKeyBatteryLow', 'oil' => 'OilLevelWarning',
            'aux12v' => 'Battery12VFail', 'lamps' => 'LampWarning', 'fuelLow' => 'FuelLow'] as $key => $ident) {
            if ($has($ident)) {
                $row($key, 'warning', $text($ident), $bool($ident));
            }
        }
        $seats = array_filter(['SeatFL' => 'Driver', 'SeatFR' => 'Passenger', 'SeatRL' => 'Rear left', 'SeatRR' => 'Rear right'], $has, ARRAY_FILTER_USE_KEY);
        if ($seats !== []) {
            $on = array_filter($seats, fn (string $l, string $i): bool => $this->GetValue($i) !== $this->Translate('Off'), ARRAY_FILTER_USE_BOTH);
            $row('seats', 'climate', $on === [] ? $this->Translate('Off') : implode(', ', array_map(fn (string $i, string $l): string => $this->Translate($l) . ' ' . $this->GetValue($i), array_keys($on), $on)));
        }
        foreach (['rearHeat' => 'RearWindowHeat', 'mirrorHeat' => 'MirrorHeat', 'sunroof' => 'SunroofOpen', 'engine' => 'EngineRunning',
            'driveMode' => 'DriveMode', 'departure1' => 'Departure1', 'departure2' => 'Departure2', 'offPeak' => 'OffPeak'] as $key => $ident) {
            if ($has($ident)) {
                $row($key, $key === 'sunroof' ? 'window' : ($key === 'driveMode' || $key === 'engine' ? 'route' : ($key === 'offPeak' || str_starts_with($key, 'departure') ? 'clock' : 'defrost')),
                    $text($ident), $key === 'sunroof' && $bool($ident));
            }
        }
        $locks = array_filter(['DoorLockedFL' => 'Driver', 'DoorLockedFR' => 'Passenger', 'DoorLockedRL' => 'Rear left', 'DoorLockedRR' => 'Rear right'], $has, ARRAY_FILTER_USE_KEY);
        if ($locks !== []) {
            $open = array_filter($locks, fn (string $l, string $i): bool => !$bool($i), ARRAY_FILTER_USE_BOTH);
            $row('doorLocks', $open === [] ? 'lock' : 'lockOpen', $open === [] ? $this->Translate('Locked') : $this->Translate('Unlocked') . ': '
                . implode(', ', array_map(fn (string $l): string => $this->Translate($l), $open)), $open !== []);
        }
        if ($has('TotalConsumedKwh')) {
            $row('totals', 'bolt', $text('TotalConsumedKwh') . ($has('TotalRegenKwh') ? ' · ' . $this->Translate('recovered') . ' ' . $text('TotalRegenKwh') : ''));
        }
        if ($has('Consumption30d')) {
            $row('consumption30', 'leaf', $text('Consumption30d'));
        }
        return $rows;
    }
}
