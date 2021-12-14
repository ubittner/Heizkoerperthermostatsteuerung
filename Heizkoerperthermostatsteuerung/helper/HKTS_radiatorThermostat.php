<?php

/** @noinspection PhpUnused */

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2021
 * @license     CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Heizkoerperthermostatsteuerung/tree/master/Heizkoerperthermostatsteuerung
 */

declare(strict_types=1);

trait HKTS_radiatorThermostat
{
    public function DetermineThermostatVariables(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $id = $this->ReadPropertyInteger('ThermostatInstance');
        if ($id == 0 || !@IPS_ObjectExists($id)) {
            return;
        }
        $moduleID = IPS_GetInstance($id)['ModuleInfo']['ModuleID'];
        if ($moduleID !== self::HOMEMATIC_DEVICE_GUID) {
            return;
        }
        $children = IPS_GetChildrenIDs($id);
        if (!empty($children)) {
            foreach ($children as $child) {
                $ident = IPS_GetObject($child)['ObjectIdent'];
                $deviceType = $this->ReadPropertyInteger('DeviceType');
                switch ($deviceType) {
                    case 1: # HM-CC-RT-DN, Channel 4
                        switch ($ident) {
                            case 'CONTROL_MODE': # Control mode
                                IPS_SetProperty($this->InstanceID, 'ThermostatControlMode', $child);
                                break;

                            case 'SET_TEMPERATURE': # Thermostat temperature
                                IPS_SetProperty($this->InstanceID, 'ThermostatTemperature', $child);
                                break;

                            case 'ACTUAL_TEMPERATURE': # Room temperature
                                IPS_SetProperty($this->InstanceID, 'RoomTemperature', $child);
                                break;

                        }
                        break;

                    case 2: # HmIP-eTRV, Channel 1
                    case 3: # HmIP-eTRV-2, Channel 1
                        switch ($ident) {
                            case 'SET_POINT_MODE': # Control mode
                                IPS_SetProperty($this->InstanceID, 'ThermostatControlMode', $child);
                                break;

                            case 'SET_POINT_TEMPERATURE': # Thermostat temperature
                                IPS_SetProperty($this->InstanceID, 'ThermostatTemperature', $child);
                                break;

                            case 'ACTUAL_TEMPERATURE': # Room temperature
                            IPS_SetProperty($this->InstanceID, 'RoomTemperature', $child);
                                break;

                        }
                        break;

                }
                // Battery state is on channel 0
                $config = json_decode(IPS_GetConfiguration($id));
                $address = strstr($config->Address, ':', true) . ':0';
                $instances = IPS_GetInstanceListByModuleID(self::HOMEMATIC_DEVICE_GUID);
                if (!empty($instances)) {
                    foreach ($instances as $instance) {
                        $config = json_decode(IPS_GetConfiguration($instance));
                        if ($config->Address == $address) {
                            $children = IPS_GetChildrenIDs($instance);
                            if (!empty($children)) {
                                foreach ($children as $child) {
                                    $ident = IPS_GetObject($child)['ObjectIdent'];
                                    if ($ident == 'LOWBAT' || $ident == 'LOW_BAT') {
                                        IPS_SetProperty($this->InstanceID, 'BatteryState', $child);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        if (IPS_HasChanges($this->InstanceID)) {
            IPS_ApplyChanges($this->InstanceID);
        }
        echo 'Die Variablen wurden erfolgreich ermittelt!';
    }

    public function ToggleSetPointTemperature(float $Temperature): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $this->SetValue('SetPointTemperature', $Temperature);
        $this->SetThermostatTemperature($Temperature);
    }

    public function ToggleBoostMode(bool $State): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        if ($State && $this->GetValue('DoorWindowState')) {
            return;
        }
        $this->SetValue('BoostMode', $State);
        //Activate boost mode
        if ($State) {
            $temperature = $this->ReadPropertyFloat('BoostTemperature');
            // Duration from minutes to seconds
            $duration = $this->ReadPropertyInteger('BoostDuration') * 60;
            $this->SetTimerInterval('DeactivateBoostMode', $duration * 1000);
            $timestamp = time() + $duration;
            $this->SetValue('BoostModeTimer', date('d.m.Y, H:i:s', ($timestamp)));
        }
        //Deactivate boost mode
        else {
            $this->SetTimerInterval('DeactivateBoostMode', 0);
            $this->SetValue('BoostModeTimer', '-');
            $temperature = $this->GetValue('SetPointTemperature');
        }
        $this->SetThermostatTemperature($temperature);
    }

    public function TogglePartyMode(bool $State): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        if ($State) {
            if ($this->GetValue('AutomaticMode')) {
                $this->SetValue('PartyMode', true);
                //Duration from hours to seconds
                $duration = $this->ReadPropertyInteger('PartyDuration') * 60 * 60;
                //Set timer interval
                $this->SetTimerInterval('DeactivatePartyMode', $duration * 1000);
                $timestamp = time() + $duration;
                $this->SetValue('PartyModeTimer', date('d.m.Y, H:i:s', ($timestamp)));
            }
        } else {
            $this->SetValue('PartyMode', false);
            $this->SetTimerInterval('DeactivatePartyMode', 0);
            $this->SetValue('PartyModeTimer', '-');
            $this->TriggerAction(true);
        }
    }

    public function SetThermostatTemperature(float $Temperature): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $id = $this->ReadPropertyInteger('ThermostatInstance');
        if ($id == 0 || !@IPS_ObjectExists($id)) {
            return;
        }
        //Enter semaphore
        if (!IPS_SemaphoreEnter($this->InstanceID . '.SetThermostatTemperature', 5000)) {
            return;
        }
        $deviceType = $this->ReadPropertyInteger('DeviceType');
        //Automatic mode, change radiator to manual mode if necessary
        $automaticMode = $this->GetValue('AutomaticMode');
        if ($automaticMode) {
            $actualMode = $this->ReadPropertyInteger('ThermostatControlMode');
            if ($actualMode != 0 && @IPS_ObjectExists($actualMode)) {
                if (GetValue($actualMode) == 0) {
                    //Automatic mode, change to manual mode
                    $setMode = @HM_WriteValueInteger($id, 'CONTROL_MODE', 1);
                    IPS_Sleep(250);
                }
                if (isset($setMode)) {
                    if (!$setMode) {
                        $this->LogMessage(__FUNCTION__ . ' Das Heizkörperthermostat konnte nicht auf den manuellen Modus umgestellt werden.', KL_ERROR);
                        $this->SendDebug(__FUNCTION__, 'Das Heizkörperthermostat konnte nicht auf den manuellen Modus umgestellt werden.', 0);
                    } else {
                        $this->SendDebug(__FUNCTION__, 'Das Heizkörperthermostat wurde auf den manuellen Modus umgestellt.', 0);
                    }
                }
            }
        }
        //Set thermostat temperature
        switch ($deviceType) {
            case 1: # HM-CC-RT-DN, Channel 4
                $setTemperature = @HM_WriteValueFloat($id, 'MANU_MODE', $Temperature);
                break;

            case 2: # HmIP-eTRV, Channel 1
            case 3: # HmIP-eTRV-2, Channel 1
                $setTemperature = @HM_WriteValueFloat($id, 'SET_POINT_TEMPERATURE', $Temperature);
                break;

        }
        if (isset($setTemperature)) {
            if (!$setTemperature) {
                $this->LogMessage(__FUNCTION__ . ' Die Temperatur von ' . $Temperature . ' konnte nicht eingestellt werden.', KL_ERROR);
                $this->SendDebug(__FUNCTION__, 'Die Temperatur von ' . $Temperature . ' konnte nicht eingestellt werden.', 0);
            } else {
                $this->SendDebug(__FUNCTION__, 'Die Temperatur von ' . $Temperature . ' °C wurde eingestellt.', 0);
            }
        }
        //Leave semaphore
        IPS_SemaphoreLeave($this->InstanceID . '.SetThermostatTemperature');
    }

    public function UpdateThermostatTemperature(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $id = $this->ReadPropertyInteger('ThermostatTemperature');
        if ($id == 0 || !@IPS_ObjectExists($id)) {
            return;
        }
        $this->SetValue('ThermostatTemperature', GetValue($this->ReadPropertyInteger('ThermostatTemperature')));
        if (!$this->GetValue('AutomaticMode')) {
            if ($this->GetValue('BoostMode') || $this->GetValue('PartyMode') || $this->GetValue('DoorWindowState')) {
                return;
            }
            $this->SetValue('SetPointTemperature', $this->GetValue('ThermostatTemperature'));
        }
    }

    public function UpdateRoomTemperature(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $id = $this->ReadPropertyInteger('RoomTemperature');
        if ($id == 0 || !@IPS_ObjectExists($id)) {
            return;
        }
        $this->SetValue('RoomTemperature', GetValue($this->ReadPropertyInteger('RoomTemperature')));
    }

    public function UpdateBatteryState(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $id = $this->ReadPropertyInteger('BatteryState');
        if ($id == 0 || !@IPS_ObjectExists($id)) {
            return;
        }
        $this->SetValue('BatteryState', GetValue($this->ReadPropertyInteger('BatteryState')));
    }

    #################### Private

    private function AdjustTemperature(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        if ($this->GetValue('AutomaticMode')) {
            $setTemperature = false;
            if ($this->ReadPropertyBoolean('AdjustTemperature')) {
                $setTemperature = true;
            }
            $this->TriggerAction($setTemperature);
        } else {
            $this->SetValue('SetPointTemperature', $this->GetValue('ThermostatTemperature'));
        }
    }
}