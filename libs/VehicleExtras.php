<?php

declare(strict_types=1);

/**
 * Additional vehicle values beyond the core status (tire pressure and warnings, service warnings, outside
 * temperature, battery health, seats, departure schedules, charge estimates, …), following the EU
 * implementation of hyundai_kia_connect_api. Every value is null when the car does not report it; the vehicle
 * instance only creates variables for reported values.
 */
final class VehicleExtras
{
    /** Raw tire pressure -> display unit per CCS2 PressureUnit (0 psi, 1 kPa, 2 bar); 3 = indirect TPMS (no values). */
    private const PRESSURE_SCALE = [0 => 1.0, 1 => 5.0, 2 => 0.1];
    /** Display unit -> bar. */
    private const TO_BAR = [0 => 0.0689476, 1 => 0.01, 2 => 1.0];
    private const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /** Values from the older status (vehicleStatus, evStatus). */
    public static function legacy(array $vs): array
    {
        $ev = $vs['evStatus'] ?? [];
        $res = $ev['reservChargeInfos'] ?? [];
        $lamps = $vs['lampWireStatus'] ?? null;
        return [
            'TireWarnAll' => self::flag($vs, 'tirePressureLamp.tirePressureLampAll'),
            'TireWarnFL'  => self::flag($vs, 'tirePressureLamp.tirePressureLampFL'),
            'TireWarnFR'  => self::flag($vs, 'tirePressureLamp.tirePressureLampFR'),
            'TireWarnRL'  => self::flag($vs, 'tirePressureLamp.tirePressureLampRL'),
            'TireWarnRR'  => self::flag($vs, 'tirePressureLamp.tirePressureLampRR'),

            'WasherFluidLow'     => self::flag($vs, 'washerFluidStatus'),
            'BrakeFluidWarning'  => self::flag($vs, 'breakOilStatus'),
            'SmartKeyBatteryLow' => self::flag($vs, 'smartKeyBatteryWarning'),
            'LampWarning'        => is_array($lamps) ? self::anyTrue([
                $lamps['headLamp']['leftLowLamp'] ?? null, $lamps['headLamp']['rightLowLamp'] ?? null,
                $lamps['headLamp']['leftHighLamp'] ?? null, $lamps['headLamp']['rightHighLamp'] ?? null,
                $lamps['stopLamp']['leftLamp'] ?? null, $lamps['stopLamp']['rightLamp'] ?? null,
                $lamps['turnSignalLamp']['leftFrontLamp'] ?? null, $lamps['turnSignalLamp']['rightFrontLamp'] ?? null,
                $lamps['turnSignalLamp']['leftRearLamp'] ?? null, $lamps['turnSignalLamp']['rightRearLamp'] ?? null]) : null,

            'RearWindowHeat' => self::flag($vs, 'sideBackWindowHeat'),
            'MirrorHeat'     => self::flag($vs, 'sideMirrorHeat'),
            'SeatFL'         => self::seat(self::get($vs, 'seatHeaterVentState.flSeatHeatState')),
            'SeatFR'         => self::seat(self::get($vs, 'seatHeaterVentState.frSeatHeatState')),
            'SeatRL'         => self::seat(self::get($vs, 'seatHeaterVentState.rlSeatHeatState')),
            'SeatRR'         => self::seat(self::get($vs, 'seatHeaterVentState.rrSeatHeatState')),
            'EngineRunning'  => self::flag($vs, 'engine'),

            'BatterySoH'           => self::soh(self::get($ev, 'batterySoh')),
            'EstChargeFastMin'     => self::minutes(self::get($ev, 'remainTime2.etc1.value')),
            'EstChargePortableMin' => self::minutes(self::get($ev, 'remainTime2.etc2.value')),
            'EstChargeStationMin'  => self::minutes(self::get($ev, 'remainTime2.etc3.value')),
            'RangeTargetAC'        => self::targetRange($res['targetSOClist'] ?? null, 1),
            'RangeTargetDC'        => self::targetRange($res['targetSOClist'] ?? null, 0),
            'FuelLow'              => self::flag($vs, 'lowFuelLight'),

            'Departure1' => self::legacyDeparture($res['reservChargeInfo']['reservChargeInfoDetail'] ?? null),
            'Departure2' => self::legacyDeparture($res['reserveChargeInfo2']['reservChargeInfoDetail'] ?? null),
            'OffPeak'    => self::legacyOffPeak($res['offpeakPowerInfo'] ?? null),
            'ScheduledCharging' => isset($res['reservFlag']) ? (int) $res['reservFlag'] > 0 : null,
        ];
    }

    /** Values from the CCS2 status (state.Vehicle). */
    public static function ccs2(array $s): array
    {
        $unit = self::get($s, 'Chassis.Axle.Tire.PressureUnit');
        $temp = self::get($s, 'Cabin.HVAC.OutsideTemperature.Value');
        $tempUnit = self::get($s, 'Cabin.HVAC.OutsideTemperature.Unit');
        $remain = self::get($s, 'Green.BatteryManagement.BatteryRemain.Value');
        $capacity = self::get($s, 'Green.BatteryManagement.BatteryCapacity.Value');
        $lights = [];
        foreach (['Front.HeadLamp.SystemWarning', 'Front.Left.Low.Warning', 'Front.Right.Low.Warning', 'Front.Left.High.Warning',
            'Front.Right.High.Warning', 'Rear.Left.StopLamp.Warning', 'Rear.Right.StopLamp.Warning', 'Front.Left.TurnSignal.Warning',
            'Front.Right.TurnSignal.Warning', 'Rear.Left.TurnSignal.Warning', 'Rear.Right.TurnSignal.Warning'] as $path) {
            $lights[] = self::get($s, 'Body.Lights.' . $path);
        }
        $offPeak = self::get($s, 'Green.Reservation.OffPeakTime');
        return [
            'TirePressureFL' => self::pressure(self::get($s, 'Chassis.Axle.Row1.Left.Tire.Pressure'), $unit),
            'TirePressureFR' => self::pressure(self::get($s, 'Chassis.Axle.Row1.Right.Tire.Pressure'), $unit),
            'TirePressureRL' => self::pressure(self::get($s, 'Chassis.Axle.Row2.Left.Tire.Pressure'), $unit),
            'TirePressureRR' => self::pressure(self::get($s, 'Chassis.Axle.Row2.Right.Tire.Pressure'), $unit),
            'TireWarnAll' => self::flag($s, 'Chassis.Axle.Tire.PressureLow'),
            'TireWarnFL'  => self::flag($s, 'Chassis.Axle.Row1.Left.Tire.PressureLow'),
            'TireWarnFR'  => self::flag($s, 'Chassis.Axle.Row1.Right.Tire.PressureLow'),
            'TireWarnRL'  => self::flag($s, 'Chassis.Axle.Row2.Left.Tire.PressureLow'),
            'TireWarnRR'  => self::flag($s, 'Chassis.Axle.Row2.Right.Tire.PressureLow'),

            'WasherFluidLow'     => self::flag($s, 'Body.Windshield.Front.WasherFluid.LevelLow'),
            'BrakeFluidWarning'  => self::flag($s, 'Chassis.Brake.Fluid.Warning'),
            'SmartKeyBatteryLow' => self::flag($s, 'Electronics.FOB.LowBattery'),
            'OilLevelWarning'    => self::flag($s, 'Drivetrain.InternalCombustionEngine.OilLevelWarning'),
            'Battery12VFail'     => self::flag($s, 'Electronics.Battery.Auxiliary.FailWarning'),
            'LampWarning'        => self::anyTrue($lights),

            'OutsideTempC'   => is_numeric($temp) ? round($tempUnit === 1 ? ((float) $temp - 32) / 1.8 : (float) $temp, 1) : null,
            'RearWindowHeat' => self::state(self::get($s, 'Body.Windshield.Rear.Defog.State')),
            'SeatFL'         => self::seat(self::get($s, 'Cabin.Seat.Row1.Driver.Climate.State')),
            'SeatFR'         => self::seat(self::get($s, 'Cabin.Seat.Row1.Passenger.Climate.State')),
            'SeatRL'         => self::seat(self::get($s, 'Cabin.Seat.Row2.Left.Climate.State')),
            'SeatRR'         => self::seat(self::get($s, 'Cabin.Seat.Row2.Right.Climate.State')),
            'SunroofOpen'    => self::flag($s, 'Body.Sunroof.Glass.Open'),
            'DoorLockedFL'   => self::locked(self::get($s, 'Cabin.Door.Row1.Driver.Lock')),
            'DoorLockedFR'   => self::locked(self::get($s, 'Cabin.Door.Row1.Passenger.Lock')),
            'DoorLockedRL'   => self::locked(self::get($s, 'Cabin.Door.Row2.Left.Lock')),
            'DoorLockedRR'   => self::locked(self::get($s, 'Cabin.Door.Row2.Right.Lock')),
            'EngineRunning'  => self::flag($s, 'DrivingReady'),
            'DriveMode'      => is_scalar(self::get($s, 'Chassis.DrivingMode.State')) ? (string) self::get($s, 'Chassis.DrivingMode.State') : null,

            'BatterySoH'           => self::soh(self::get($s, 'Green.BatteryManagement.SoH.Ratio')),
            'BatteryRemainKwh'     => is_numeric($remain) && $remain > 0 ? self::kwh((float) $remain) : null,
            'BatteryCapacityKwh'   => is_numeric($capacity) && $capacity > 0 ? self::kwh((float) $capacity) : null,
            'EstChargeFastMin'     => self::minutes(self::get($s, 'Green.ChargingInformation.EstimatedTime.Quick')),
            'EstChargePortableMin' => self::minutes(self::get($s, 'Green.ChargingInformation.EstimatedTime.ICCB')),
            'EstChargeStationMin'  => self::minutes(self::get($s, 'Green.ChargingInformation.EstimatedTime.Standard')),
            'RangeTargetAC'        => is_numeric(self::get($s, 'Green.ChargingInformation.DTE.TargetSoC.Standard')) ? (float) self::get($s, 'Green.ChargingInformation.DTE.TargetSoC.Standard') : null,
            'RangeTargetDC'        => is_numeric(self::get($s, 'Green.ChargingInformation.DTE.TargetSoC.Quick')) ? (float) self::get($s, 'Green.ChargingInformation.DTE.TargetSoC.Quick') : null,
            'FuelLow'              => self::flag($s, 'Drivetrain.FuelSystem.LowFuelWarning'),

            'Departure1' => self::ccs2Departure(self::get($s, 'Green.Reservation.Departure.Schedule1'),
                self::get($s, 'Green.Reservation.Departure.Schedule1.Climate') ?? self::get($s, 'Green.Reservation.Departure.Climate')),
            'Departure2' => self::ccs2Departure(self::get($s, 'Green.Reservation.Departure.Schedule2'),
                self::get($s, 'Green.Reservation.Departure.Schedule2.Climate') ?? self::get($s, 'Green.Reservation.Departure.Climate2')),
            'OffPeak'    => is_array($offPeak) ? ['start' => self::hhmm($offPeak['StartHour'] ?? null, $offPeak['StartMin'] ?? null),
                'end' => self::hhmm($offPeak['EndHour'] ?? null, $offPeak['EndMin'] ?? null), 'only' => ($offPeak['Mode'] ?? null) === 3] : null,
            'ScheduledCharging' => is_array($offPeak) && isset($offPeak['Mode']) ? in_array((int) $offPeak['Mode'], [2, 3], true) : null,
        ];
    }

    /** CCS2 window: open when fully open or vented (Open=0 with OpenLevel>0, hyundai_kia_connect_api #1215). */
    public static function windowOpen(array $s, string $base): bool
    {
        return (bool) self::get($s, $base . '.Open') || (bool) self::get($s, $base . '.OpenLevel');
    }

    /** 12V level, or -1 when the sensor is unreliable (CCS2 SensorReliability 1, e.g. after a 12V reset) or out of range. */
    public static function battery12V(mixed $level, mixed $reliability = null): int
    {
        if (!is_numeric($level) || (int) $reliability === 1 || $level < 0 || $level > 100) {
            return -1;
        }
        return (int) $level;
    }

    /**
     * Consumption over the whole time with the account and of the last 30 days from POST /drvhistory
     * (periodTarget 1 = total, periodTarget 0 = 30 days). Energy in kWh, consumption in kWh/100 km.
     */
    public static function drivingTotals(array $total, array $last30): array
    {
        $all = $total['drivingInfo'][0] ?? null;
        $consumption = null;
        foreach ($last30['drivingInfo'] ?? [] as $period) {
            if ((int) ($period['drivingPeriod'] ?? -1) === 0 && (float) ($period['calculativeOdo'] ?? 0) > 0) {
                $consumption = round((float) ($period['totalPwrCsp'] ?? 0) / (float) $period['calculativeOdo'] / 10, 1);
                break;
            }
        }
        return [
            'TotalConsumedKwh' => is_array($all) && is_numeric($all['totalPwrCsp'] ?? null) ? round((float) $all['totalPwrCsp'] / 1000, 1) : null,
            'TotalRegenKwh'    => is_array($all) && is_numeric($all['regenPwr'] ?? null) ? round((float) $all['regenPwr'] / 1000, 1) : null,
            'Consumption30d'   => $consumption,
        ];
    }

    /** Dot path into nested arrays, null when missing. */
    public static function get(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }
        return $data;
    }

    private static function flag(array $data, string $path): ?bool
    {
        $v = self::get($data, $path);
        return $v === null || is_array($v) ? null : (bool) $v;
    }

    /** CCS2 on/off state: 1 = on, 0/2 = off. */
    private static function state(mixed $v): ?bool
    {
        return is_numeric($v) ? (int) $v === 1 : null;
    }

    /** CCS2 door lock: 0 = locked. */
    private static function locked(mixed $v): ?bool
    {
        return is_numeric($v) ? (int) $v === 0 : null;
    }

    private static function anyTrue(array $values): ?bool
    {
        $known = array_filter($values, static fn ($v): bool => $v !== null && !is_array($v));
        return $known === [] ? null : in_array(true, array_map('boolval', $known), true);
    }

    /** Seat climate 0–8 (0/2 off, 1 on, 3–5 cooling low/medium/high, 6–8 heating low/medium/high). */
    private static function seat(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v >= 0 && (int) $v <= 8 ? (int) $v : null;
    }

    private static function soh(mixed $v): ?float
    {
        return is_numeric($v) && $v > 0 && $v <= 100 ? round((float) $v, 1) : null;
    }

    private static function minutes(mixed $v): ?int
    {
        return is_numeric($v) && $v >= 0 ? (int) $v : null;
    }

    /** Battery energy: the API sends Wh on most cars, kWh on some. */
    private static function kwh(float $v): float
    {
        return round($v > 1000 ? $v / 1000 : $v, 1);
    }

    /** Tire pressure in bar; null without a reading (255) or with indirect TPMS (unit 3). */
    private static function pressure(mixed $raw, mixed $unit): ?float
    {
        if (!is_numeric($raw) || (int) $raw === 255 || !is_numeric($unit) || !isset(self::PRESSURE_SCALE[(int) $unit])) {
            return null;
        }
        return round((float) $raw * self::PRESSURE_SCALE[(int) $unit] * self::TO_BAR[(int) $unit], 2);
    }

    private static function targetRange(mixed $list, int $plugType): ?float
    {
        foreach (is_array($list) ? $list : [] as $entry) {
            if ((int) ($entry['plugType'] ?? -1) === $plugType && is_numeric(self::get($entry, 'dte.rangeByFuel.totalAvailableRange.value'))) {
                return (float) self::get($entry, 'dte.rangeByFuel.totalAvailableRange.value');
            }
        }
        return null;
    }

    private static function hhmm(mixed $h, mixed $m): ?string
    {
        // 31:70 is the "not configured" value on some cars
        return is_numeric($h) && is_numeric($m) && $h >= 0 && $h <= 23 && $m >= 0 && $m <= 59 ? sprintf('%02d:%02d', $h, $m) : null;
    }

    /** Legacy time "HHMM" with timeSection 1 = PM for 12 h values. */
    private static function legacyTime(mixed $time): ?string
    {
        $t = (string) ($time['time'] ?? '');
        if (!preg_match('/^(\d{2})(\d{2})$/', $t, $m)) {
            return null;
        }
        $h = (int) $m[1];
        if ((int) ($time['timeSection'] ?? 0) === 1 && $h < 12) {
            $h += 12;
        }
        return self::hhmm($h, (int) $m[2]);
    }

    /** Departure: ['enabled', 'days' (0 = Sunday), 'time', 'climate', 'temp', 'defrost']. */
    private static function legacyDeparture(mixed $d): ?array
    {
        if (!is_array($d)) {
            return null;
        }
        $fatc = $d['reservFatcSet'] ?? [];
        return [
            'enabled' => (bool) ($d['reservChargeSet'] ?? false),
            'days'    => array_values(array_filter(array_map('intval', (array) ($d['reservInfo']['day'] ?? [])), static fn (int $x): bool => $x >= 0 && $x <= 6)),
            'time'    => self::legacyTime($d['reservInfo']['time'] ?? null),
            'climate' => isset($fatc['airCtrl']) ? (int) $fatc['airCtrl'] > 0 : null,
            'temp'    => isset($fatc['airTemp']['value']) && (int) ($fatc['airTemp']['hvacTempType'] ?? 0) > 0
                ? VehicleStatusMapper::tempCodeToCelsius($fatc['airTemp']['value']) : null,
            'defrost' => isset($fatc['defrost']) ? (bool) $fatc['defrost'] : null,
        ];
    }

    private static function legacyOffPeak(mixed $p): ?array
    {
        if (!is_array($p) || !isset($p['offPeakPowerTime1'])) {
            return null;
        }
        return ['start' => self::legacyTime($p['offPeakPowerTime1']['starttime'] ?? null),
            'end' => self::legacyTime($p['offPeakPowerTime1']['endtime'] ?? null), 'only' => (int) ($p['offPeakPowerFlag'] ?? 0) === 2];
    }

    private static function ccs2Departure(mixed $schedule, mixed $climate): ?array
    {
        if (!is_array($schedule)) {
            return null;
        }
        $days = [];
        foreach (self::DAYS as $i => $day) {
            if ((int) ($schedule[$day] ?? 0) === 1) {
                $days[] = $i;
            }
        }
        $climate = is_array($climate) ? $climate : [];
        $activation = $schedule['Activation'] ?? $climate['Activation'] ?? null;
        return [
            'enabled' => (bool) ($schedule['Enable'] ?? false),
            'days'    => $days,
            'time'    => self::hhmm($schedule['Hour'] ?? null, $schedule['Min'] ?? null),
            'climate' => $activation === null ? null : (bool) $activation,
            'temp'    => is_numeric($climate['Temperature'] ?? null) ? (float) $climate['Temperature'] : null,
            'defrost' => isset($climate['Defrost']) ? (bool) $climate['Defrost'] : null,
        ];
    }
}
