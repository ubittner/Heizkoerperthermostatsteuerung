<?php

/** @noinspection PhpUnused */

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2021
 * @license     CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Heizkoerperthermostatsteuerung/tree/master/Heizkoerperthermostatsteuerung
 */

declare(strict_types=1);

trait HKTS_doorWindowSensors
{
    public function CheckDoorWindowSensors(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $lastState = $this->GetValue('DoorWindowState');
        $actualState = $this->GetDoorWindowState();
        $delay = $this->ReadPropertyInteger('ReviewDelay');
        //State has changed
        if ($actualState != $lastState) {
            //Check now, no delay
            if ($delay == 0) {
                $this->SetValue('DoorWindowState', $actualState);
                $this->SetTimerInterval('ReviewDoorWindowSensors', 0);
                $this->SetValue('DoorWindowStateTimer', '-');
                $this->ExecuteDoorWindowAction($actualState);
            }
            //Delay
            else {
                $this->SetTimerInterval('ReviewDoorWindowSensors', $delay * 1000);
                $timestamp = time() + $delay;
                $this->SetValue('DoorWindowStateTimer', date('d.m.Y, H:i:s', ($timestamp)));
            }
        }
    }

    public function ReviewDoorWindowSensors(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $this->SetTimerInterval('ReviewDoorWindowSensors', 0);
        $this->SetValue('DoorWindowStateTimer', '-');
        $lastState = $this->GetValue('DoorWindowState');
        $actualState = $this->GetDoorWindowState();
        $this->SetValue('DoorWindowState', $actualState);
        //State has changed
        if ($actualState != $lastState) {
            $this->ExecuteDoorWindowAction($actualState);
        }
        //State has not changed
        else {
            //Reduce temperature
            if ($this->GetValue('DoorWindowState')) {
                $this->SetThermostatTemperature($this->ReadPropertyFloat('OpenDoorWindowTemperature'));
            }
        }
    }

    #################### Private

    private function ExecuteDoorWindowAction(bool $State): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        //Check chimney state
        if ($this->GetValue('ChimneyState')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Der Kamin ist an!', 0);
            return;
        }
        //Opened
        if ($State) {
            //Deactivate boost mode
            if ($this->GetValue('BoostMode')) {
                $this->ToggleBoostMode(false);
                IPS_Sleep(250);
            }
            //Reduce temperature
            if ($this->ReadPropertyBoolean('ReduceTemperature')) {
                $this->SetThermostatTemperature($this->ReadPropertyFloat('OpenDoorWindowTemperature'));
            }
        }
        //Closed
        else {
            if ($this->ReadPropertyBoolean('ReactivateBoostMode')) {
                $this->ToggleBoostMode(true);
            } else {
                $this->SetThermostatTemperature(floatval($this->GetValue('SetPointTemperature')));
            }
        }
    }

    private function GetDoorWindowState(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $state = false;
        if ($this->CheckMaintenanceMode()) {
            return $state;
        }
        foreach (json_decode($this->ReadPropertyString('DoorWindowSensors')) as $sensor) {
            if ($sensor->UseSensor) {
                $id = $sensor->ID;
                if ($id != 0 && @IPS_ObjectExists($id)) {
                    $actualValue = boolval(GetValue($id));
                    $triggerValue = boolval($sensor->TriggerValue);
                    if ($actualValue == $triggerValue) {
                        $state = true;
                    }
                }
            }
        }
        return $state;
    }
}