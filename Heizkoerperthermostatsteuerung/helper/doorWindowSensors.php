<?php

// Declare
declare(strict_types=1);

trait HKTS_doorWindowSensors
{
    /**
     * Checks the state of the door and window sensors again after the defined delay.
     */
    public function ReviewDoorWindowSensors(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->SetTimerInterval('ReviewDoorWindowSensors', 0);
        $this->SetValue('DoorWindowStateTimer', '-');
        $lastState = $this->GetValue('DoorWindowState');
        $actualState = $this->GetDoorWindowState();
        $this->SetValue('DoorWindowState', $actualState);
        // State has changed
        if ($actualState != $lastState) {
            $this->ExecuteDoorWindowAction($actualState);
        }
        // State has not changed
        else {
            // Reduce temperature
            if ($this->GetValue('DoorWindowState')) {
                $this->SetThermostatTemperature($this->ReadPropertyFloat('OpenDoorWindowTemperature'));
            }
        }
    }

    //#################### Private

    /**
     * Checks the state of the activated door and window sensors.
     */
    private function CheckDoorWindowSensors(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $lastState = $this->GetValue('DoorWindowState');
        $actualState = $this->GetDoorWindowState();
        $delay = $this->ReadPropertyInteger('ReviewDelay');
        // State has changed
        if ($actualState != $lastState) {
            // Check now, no delay
            if ($delay == 0) {
                $this->SetValue('DoorWindowState', $actualState);
                $this->SetTimerInterval('ReviewDoorWindowSensors', 0);
                $this->SetValue('DoorWindowStateTimer', '-');
                $this->ExecuteDoorWindowAction($actualState);
            }
            // Delay
            else {
                $this->SetTimerInterval('ReviewDoorWindowSensors', $delay * 1000);
                $timestamp = time() + $delay;
                $this->SetValue('DoorWindowStateTimer', date('d.m.Y, H:i:s', ($timestamp)));
            }
        }
    }

    /**
     * Executes the action based on the door and window state.
     *
     * @param bool $State
     * false    = all doors amd windows are closed
     * true     = one door or window is opened
     */
    private function ExecuteDoorWindowAction(bool $State): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        // Check chimney state
        if ($this->GetValue('ChimneyState')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Der Kamin ist an!', 0);
            return;
        }
        // Opened
        if ($State) {
            // Deactivate boost mode
            if ($this->GetValue('BoostMode')) {
                $this->ToggleBoostMode(false);
                IPS_Sleep(250);
            }
            // Reduce temperature
            if ($this->ReadPropertyBoolean('ReduceTemperature')) {
                $this->SetThermostatTemperature($this->ReadPropertyFloat('OpenDoorWindowTemperature'));
            }
        }
        // Closed
        else {
            if ($this->ReadPropertyBoolean('ReactivateBoostMode')) {
                $this->ToggleBoostMode(true);
            } else {
                $this->SetThermostatTemperature($this->GetValue('SetPointTemperature'));
            }
        }
    }

    /**
     * Gets the state of the door and window sensors.
     *
     * @return bool
     * false    = closed
     * true     = opened
     *
     * @throws Exception
     */
    private function GetDoorWindowState(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
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

    /**
     * Gets the activated door and window sensors.
     *
     * @return array
     * Returns an array of activated door and window sensors.
     */
    private function GetDoorWindowSensors(): array
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
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
}