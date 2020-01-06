<?php

// Declare
declare(strict_types=1);

trait HKTS_doorWindowSensors
{
    /**
     * Checks the state of the door and window sensors.
     */
    public function CheckDoorWindowSensors(): void
    {
        // Get actual state of doors and windows
        $state = $this->GetDoorWindowState();
        $delay = $this->ReadPropertyInteger('ReviewDelay');
        // Check now, no delay
        if ($delay == 0) {
            $this->SetValue('DoorWindowState', $state);
            $this->DisableTimers();
            // Opened
            if ($state) {
                // Deactivate boost mode
                if ($this->GetValue('BoostMode')) {
                    $this->SetValue('BoostMode', false);
                    IPS_Sleep(250);
                }
                // Set back temperature
                $this->SetThermostatTemperature($this->GetPropertyTemperature('SetBackTemperature'));
            } // Closed
            else {
                $this->SetThermostatTemperature($this->GetValue('SetPointTemperature'));
            }
        } else {
            // Delay
            $this->SetTimerInterval('ReviewDoorWindowSensors', $delay * 1000);
        }
    }

    /**
     * Checks the state of the door and window sensors again after the defined delay.
     */
    public function ReviewDoorWindowSensors(): void
    {
        // Disable timer
        $this->DisableTimers();
        $lastState = $this->GetValue('DoorWindowState');
        $actualState = $this->GetDoorWindowState();
        $this->SetValue('DoorWindowState', $actualState);
        // The door and window state has changed since first check
        if ($actualState != $lastState) {
            // Doors and windows are still open
            if ($actualState) {
                // Deactivate boost mode
                if ($this->GetValue('BoostMode')) {
                    $this->ToggleBoostMode(false);
                    IPS_Sleep(250);
                }
                // Set back temperature
                $this->SetThermostatTemperature($this->GetPropertyTemperature('SetBackTemperature'));
            } else {
                $this->SetThermostatTemperature($this->GetValue('SetPointTemperature'));
            }
        } else {
            if ($this->GetValue('DoorWindowState')) {
                // Set back temperature
                $this->SetThermostatTemperature($this->GetPropertyTemperature('SetBackTemperature'));
            }
        }
    }

    //#################### Private

    /**
     * Gets the activated door and window sensors.
     *
     * @return array
     * Returns an array of activated door and window sensors.
     */
    private function GetDoorWindowSensors(): array
    {
        $sensors = [];
        $doorWindowSensors = json_decode($this->ReadPropertyString('DoorWindowSensors'));
        if (!empty($doorWindowSensors)) {
            foreach ($doorWindowSensors as $doorWindowSensor) {
                $id = $doorWindowSensor->ID;
                if ($id != 0 && @IPS_ObjectExists($id)) {
                    if ($doorWindowSensor->UseSensor) {
                        array_push($sensors, $id);
                    }
                }
            }
        }
        return $sensors;
    }

    /**
     * Gets the state of the door and window sensors.
     *
     * @return bool
     * false    = closed
     * true     = opened
     */
    private function GetDoorWindowState(): bool
    {
        $state = false;
        $sensors = $this->GetDoorWindowSensors();
        if (!empty($sensors)) {
            foreach ($sensors as $sensor) {
                if (boolval(GetValue($sensor))) {
                    $state = true;
                }
            }
        }
        return $state;
    }
}