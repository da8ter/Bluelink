<?php

declare(strict_types=1);

class BluelinkConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->ConnectParent('{A1B2C3D4-5678-9ABC-DEF0-123456789ABC}');

        $this->RegisterPropertyBoolean('DebugEnabled', false);
        $this->RegisterPropertyInteger('DebugLevel', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $vehicles = $this->GetVehicleList();
        $existingInstances = $this->GetExistingVehicleInstances();

        $values = [];
        foreach ($vehicles as $vehicle) {
            $vin = $vehicle['VIN'] ?? '';
            $vehicleId = $vehicle['VehicleId'] ?? '';
            $instanceId = $existingInstances[$vin] ?? 0;

            $values[] = [
                'VIN'         => $vin,
                'VehicleId'   => $vehicleId,
                'VehicleName' => $vehicle['VehicleName'] ?? '',
                'Model'       => $vehicle['Model'] ?? '',
                'ModelYear'   => $vehicle['ModelYear'] ?? '',
                'instanceID'  => $instanceId,
                'create'      => [
                    'moduleID'      => '{C3D4E5F6-789A-BCDE-F012-3456789ABCDE}',
                    'configuration' => [
                        'VIN'       => $vin,
                        'VehicleId' => $vehicleId,
                    ],
                    'name' => 'Bluelink ' . ($vehicle['VehicleName'] ?? $vin),
                ],
            ];
        }

        $form['actions'][0]['values'] = $values;

        return json_encode($form);
    }

    // ── Private methods ─────────────────────────────────────────────

    private function GetVehicleList(): array
    {
        try {
            $response = $this->SendDataToParent(json_encode([
                'DataID'  => '{D7F8E9A0-B1C2-3D4E-5F6A-7B8C9D0E1F2A}',
                'Command' => 'GetVehicles',
            ]));

            if (!is_string($response) || $response === '') {
                $this->SendDebug('GetVehicleList', 'Empty/invalid response from parent', 0);
                return [];
            }

            $data = json_decode($response, true);
            if (($data['success'] ?? false) && !empty($data['vehicles'])) {
                return $data['vehicles'];
            }
        } catch (Exception $e) {
            $this->SendDebug('GetVehicleList', 'Error: ' . $e->getMessage(), 0);
        }

        return [];
    }

    protected function SendDebug($Message, $Data, $Format)
    {
        $debugEnabled = $this->ReadPropertyBoolean('DebugEnabled');
        if (!$debugEnabled) {
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
        parent::SendDebug($Message, $Data, $Format);
    }

    private function GetExistingVehicleInstances(): array
    {
        $instances = [];
        $moduleId = '{C3D4E5F6-789A-BCDE-F012-3456789ABCDE}';

        foreach (IPS_GetInstanceListByModuleID($moduleId) as $instanceId) {
            $vin = IPS_GetProperty($instanceId, 'VIN');
            if (!empty($vin)) {
                $instances[$vin] = $instanceId;
            }
        }

        return $instances;
    }
}
