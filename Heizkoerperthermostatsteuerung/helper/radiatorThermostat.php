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
        if (!$this->ValidatePropertyVariable('BoostMode')) {
            return;
        }
        if ($this->GetValue('DoorWindowState')) {
            $State = false;
        }
        $this->SetValue('BoostMode', $State);
        $id = $this->ReadPropertyInteger('BoostMode');
        $execute = IPS_RunScriptText('@RequestAction(' . $id . ', ' . (int) $State . ');');
        if (!$execute) {
            $this->LogMessage(__FUNCTION__ . ' Boost-Modus konnte nicht ausgeführt werden.', KL_ERROR);
            $this->SendDebug(__FUNCTION__, 'Boost-Modus konnte nicht ausgeführt werden.', 0);
        } else {
            $this->SendDebug(__FUNCTION__, 'Boost-Modus wurde ausgeführt.', 0);
        }
    }

    /**
     * Set the value for the variable.
     *
     * @param string $Name
     */
    public function SetVariableValue(string $Name): void
    {
        if (!$this->ValidatePropertyVariable($Name)) {
            return;
        }
        $this->SetValue($Name, GetValue($this->ReadPropertyInteger($Name)));
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
        $setTemperature = IPS_RunScriptText('@RequestAction(' . $id . ', ' . (float) $Temperature . ');');
        if (!$setTemperature) {
            $this->LogMessage(__FUNCTION__ . ' Temperatur von ' . $Temperature . ' konnte nicht eingestellt werden.', KL_ERROR);
            $this->SendDebug(__FUNCTION__, 'Temperatur von ' . $Temperature . ' konnte nicht eingestellt werden.', 0);
        } else {
            $this->SendDebug(__FUNCTION__, 'Temperatur von ' . $Temperature . ' °C wurde eingestellt.', 0);
        }
        // Leave semaphore
        IPS_SemaphoreLeave($this->InstanceID . '.SetThermostatTemperature');
    }
}