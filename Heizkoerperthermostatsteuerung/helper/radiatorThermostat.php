<?php

// Declare
declare(strict_types=1);

trait HKTS_radiatorThermostat
{
    /**
     * Determines the necessary variables of the thermostat.
     */
    public function DetermineThermostatVariables(): void
    {
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
                switch ($ident) {
                    // Thermostat temperature
                    case 'SET_TEMPERATURE':
                    case 'SET_POINT_TEMPERATURE':
                        IPS_SetProperty($this->InstanceID, 'ThermostatTemperature', $child);
                        break;

                    // Room temperature
                    case 'ACTUAL_TEMPERATURE':
                        IPS_SetProperty($this->InstanceID, 'RoomTemperature', $child);
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
     * false    = boost mode off
     * true     = boost mode on
     */
    public function ToggleBoostMode(bool $State): void
    {
        if ($State && $this->GetValue('DoorWindowState')) {
            $State = false;
        }
        // Activate boost mode
        if ($State) {
            $temperature = $this->ReadPropertyFloat('BoostTemperature');
            $duration = $this->ReadPropertyInteger('BoostDuration') * 1000;
            $this->SetTimerInterval('DeactivateBoostMode', $duration);
        } // Deactivate boost mode
        else {
            $this->SetTimerInterval('DeactivateBoostMode', 0);
            $temperature = $this->GetValue('SetPointTemperature');
        }
        $this->SetValue('BoostMode', $State);
        $this->SetThermostatTemperature($temperature);
    }

    /**
     * Toggles the party mode.
     *
     * @param bool $State
     * false    = party mode off
     * true     = party mode on
     */
    public function TogglePartyMode(bool $State): void
    {
        if ($State) {
            if ($this->GetValue('AutomaticMode')) {
                $this->SetValue('PartyMode', $State);
                // Set timer interval
                $this->SetTimerInterval('DeactivatePartyMode', $this->ReadPropertyInteger('PartyDuration') * 60 * 60 * 1000);
            }
        } else {
            $this->SetValue('PartyMode', $State);
            $this->SetTimerInterval('DeactivatePartyMode', 0);
            $this->TriggerAction(true);
        }
    }

    /**
     * Sets the temperature on the radiator thermostat.
     *
     * @param float $Temperature
     */
    public function SetThermostatTemperature(float $Temperature): void
    {
        $id = $this->ReadPropertyInteger('ThermostatTemperature');
        if ($id == 0 || !@IPS_ObjectExists($id)) {
            return;
        }
        // Enter semaphore
        if (!IPS_SemaphoreEnter($this->InstanceID . '.SetThermostatTemperature', 5000)) {
            return;
        }
        // Set thermostat temperature
        $setTemperature = @RequestAction($id, $Temperature);
        if (!$setTemperature) {
            $this->LogMessage(__FUNCTION__ . ' Die Temperatur von ' . $Temperature . ' konnte nicht eingestellt werden.', KL_ERROR);
            $this->SendDebug(__FUNCTION__, 'Die Temperatur von ' . $Temperature . ' konnte nicht eingestellt werden.', 0);
        } else {
            $this->SendDebug(__FUNCTION__, 'Die Temperatur von ' . $Temperature . ' °C wurde eingestellt.', 0);
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
            $setTemperature = false;
            if ($this->ReadPropertyBoolean('AdjustTemperature')) {
                $setTemperature = true;
            }
            $this->TriggerAction($setTemperature);
        } else {
            $this->SetValue('SetPointTemperature', $this->GetValue('ThermostatTemperature'));
        }
    }

    /**
     * Updates the thermostat temperature.
     */
    private function UpdateThermostatTemperature(): void
    {
        $name = 'ThermostatTemperature';
        $id = $this->ReadPropertyInteger($name);
        if ($id == 0 || !@IPS_ObjectExists($id)) {
            return;
        }
        $this->SetValue($name, GetValue($this->ReadPropertyInteger($name)));
    }

    /**
     * Updates the room temperature.
     */
    private function UpdateRoomTemperature(): void
    {
        $name = 'RoomTemperature';
        $id = $this->ReadPropertyInteger($name);
        if ($id == 0 || !@IPS_ObjectExists($id)) {
            return;
        }
        $this->SetValue($name, GetValue($this->ReadPropertyInteger($name)));
    }

    /**
     * Updates the battery state.
     */
    private function UpdateBatteryState(): void
    {
        $name = 'BatteryState';
        $id = $this->ReadPropertyInteger($name);
        if ($id == 0 || !@IPS_ObjectExists($id)) {
            return;
        }
        $this->SetValue($name, GetValue($this->ReadPropertyInteger($name)));
    }
}