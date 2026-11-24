<?php

namespace App\Service;

final class ThermorStateParser
{
    private const HEATER_DEVICE = 'modbuslink:AtlanticDomesticHotWaterProductionMBLComponent';
    private const ENERGY_DEVICE = 'modbuslink:DHWCumulatedElectricalEnergyConsumptionMBLSystemDeviceSensor';

    public function extract(array $setup): ?array
    {
        $heatingStatus = null;
        $waterTemperature = null;
        $waterCapacity = null;
        $absenceMode = null;
        $electricalConsumption = null;

        $devices = $setup['devices'] ?? [];
        if (!is_array($devices)) {
            return null;
        }

        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }

            $states = $device['states'] ?? [];
            if (!is_array($states)) {
                continue;
            }

            if (($device['controllableName'] ?? null) === self::HEATER_DEVICE) {
                $heatingStatus = $this->stateValue($states, 'core:HeatingStatusState');
                $waterTemperature = $this->stateValue($states, 'modbuslink:MiddleWaterTemperatureState');
                $waterCapacity = $this->stateValue($states, 'core:RemainingHotWaterState');
                $absenceMode = $this->stateValue($states, 'modbuslink:DHWAbsenceModeState');
            } elseif (($device['controllableName'] ?? null) === self::ENERGY_DEVICE) {
                $electricalConsumption = $this->stateValue($states, 'core:ElectricEnergyConsumptionState');
            }
        }

        if (!is_string($heatingStatus)
            || !is_numeric($waterTemperature)
            || !is_numeric($waterCapacity)
            || !is_string($absenceMode)
            || !is_numeric($electricalConsumption)
        ) {
            return null;
        }

        return [
            'heatingState' => $heatingStatus !== 'off',
            'waterTemperature' => (float) $waterTemperature,
            'V40Capacity' => (int) $waterCapacity,
            'absenceMode' => $absenceMode === 'off' ? 0 : 1,
            'electricalConsumption' => (int) $electricalConsumption,
        ];
    }

    private function stateValue(array $states, string $name): mixed
    {
        foreach ($states as $state) {
            if (is_array($state) && ($state['name'] ?? null) === $name) {
                return $state['value'] ?? null;
            }
        }

        return null;
    }
}
