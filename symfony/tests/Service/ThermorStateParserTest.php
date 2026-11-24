<?php

namespace App\Tests\Service;

use App\Service\ThermorStateParser;
use PHPUnit\Framework\TestCase;

final class ThermorStateParserTest extends TestCase
{
    public function testExtractsWaterHeaterValues(): void
    {
        $setup = [
            'devices' => [
                [
                    'controllableName' => 'modbuslink:AtlanticDomesticHotWaterProductionMBLComponent',
                    'states' => [
                        ['name' => 'core:HeatingStatusState', 'value' => 'on'],
                        ['name' => 'modbuslink:MiddleWaterTemperatureState', 'value' => '48.5'],
                        ['name' => 'core:RemainingHotWaterState', 'value' => '120'],
                        ['name' => 'modbuslink:DHWAbsenceModeState', 'value' => 'off'],
                    ],
                ],
                [
                    'controllableName' => 'modbuslink:DHWCumulatedElectricalEnergyConsumptionMBLSystemDeviceSensor',
                    'states' => [
                        ['name' => 'core:ElectricEnergyConsumptionState', 'value' => '345'],
                    ],
                ],
            ],
        ];

        self::assertSame([
            'heatingState' => true,
            'waterTemperature' => 48.5,
            'V40Capacity' => 120,
            'absenceMode' => 0,
            'electricalConsumption' => 345,
        ], (new ThermorStateParser())->extract($setup));
    }

    public function testReturnsNullWhenRequiredStatesAreMissing(): void
    {
        $setup = [
            'devices' => [
                [
                    'controllableName' => 'modbuslink:AtlanticDomesticHotWaterProductionMBLComponent',
                    'states' => [
                        ['name' => 'core:RemainingHotWaterState', 'value' => '120'],
                        ['name' => 'modbuslink:DHWAbsenceModeState', 'value' => 'off'],
                    ],
                ],
                [
                    'controllableName' => 'modbuslink:DHWCumulatedElectricalEnergyConsumptionMBLSystemDeviceSensor',
                    'states' => [],
                ],
            ],
        ];

        self::assertNull((new ThermorStateParser())->extract($setup));
    }
}
