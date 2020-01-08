<?php

// Declare
declare(strict_types=1);

trait HKTS_radiatorThermostat
{
    /**
     * Toggles the set point temperature.
     *
     * @param float $Temperature
     */
    public function ToggleSetPointTemperature(float $Temperature): void
    {
        $this->SetValue('SetPointTemperature', $Temperature);
        $this->SetThermostatTemperature($Temperature);
    }

    /**
     * Toggles the boost mode.
     *
     * @param bool $State
     * false    = boost-mode off
     * true     = boost-mode on
     */
    public function ToggleBoostMode(bool $State): void
    {
        if (!$this->ValidatePropertyVariable('ThermostatInstance')) {
            return;
        }
        if ($this->GetValue('DoorWindowState')) {
            $State = false;
        }
        $this->SetValue('BoostMode', $State);
        $id = $this->ReadPropertyInteger('ThermostatInstance');
        $execute = @HM_WriteValueBoolean($id, 'BOOST_MODE', $State);
        if (!$execute) {
            $this->LogMessage(__FUNCTION__ . ' Boost-Modus konnte nicht ausgeführt werden.', KL_ERROR);
            $this->SendDebug(__FUNCTION__, 'Boost-Modus konnte nicht ausgeführt werden.', 0);
        } else {
            $this->SendDebug(__FUNCTION__, 'Boost-Modus wurde ausgeführt.', 0);
        }
    }

    /**
     * Sets the temperature on the radiator thermostat.
     *
     * @param float $Temperature
     */
    public function SetThermostatTemperature(float $Temperature): void
    {
        if (!$this->ValidatePropertyVariable('ThermostatTemperature')) {
            return;
        }
        // Enter semaphore
        if (!IPS_SemaphoreEnter($this->InstanceID . '.SetThermostatTemperature', 5000)) {
            return;
        }
        // Set thermostat temperature
        $id = $this->ReadPropertyInteger('ThermostatTemperature');
        $setTemperature = @RequestAction($id, $Temperature);
        if (!$setTemperature) {
            $this->LogMessage(__FUNCTION__ . ' Temperatur von ' . $Temperature . ' konnte nicht eingestellt werden.', KL_ERROR);
            $this->SendDebug(__FUNCTION__, 'Temperatur von ' . $Temperature . ' konnte nicht eingestellt werden.', 0);
        } else {
            $this->SendDebug(__FUNCTION__, 'Temperatur von ' . $Temperature . ' °C wurde eingestellt.', 0);
        }
        // Leave semaphore
        IPS_SemaphoreLeave($this->InstanceID . '.SetThermostatTemperature');
    }

    //#################### Private

    /**
     * Adjusts the temperature.
     */
    private function AdjustTemperature(): void
    {
        if ($this->GetValue('AutomaticMode')) {
            if ($this->ReadPropertyBoolean('AdjustTemperature')) {
                $this->SetActualAction();
            } else {
                $this->SetValue('SetPointTemperature', $this->GetValue('ThermostatTemperature'));
            }
        }
    }
}