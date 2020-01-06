<?php

/*
 * @module      Heizkoerperthermostatsteuerung
 *
 * @prefix      HKTS
 *
 * @file        module.php
 *
 * @author      Ulrich Bittner
 * @copyright   (c) 2019, 2020
 * @license    	CC BY-NC-SA 4.0
 *              https://creativecommons.org/licenses/by-nc-sa/4.0/
 *
 * @version     1.00-1
 * @date:       2020-01-02, 18:00, 1577984400
 *
 * @see         https://github.com/ubittner/Heizkoerperthermostatsteuerung/
 *
 * @guids       Library
 *              {7EF35A5D-430D-6D3A-82D7-59F0C56C3A56}
 *
 *              Heizkoerperthermostatsteuerung
 *             	{5158B7B6-06BA-E8F2-58B9-E79D52AFAC65}
 */

// Declare
declare(strict_types=1);

// Include
include_once __DIR__ . '/helper/autoload.php';

// Class
class Heizkoerperthermostatsteuerung extends IPSModule
{
    // Traits
    use HKTS_doorWindowSensors;
    use HKTS_radiatorThermostat;
    use HKTS_weeklySchedule;

    // Constants
    private const MINIMUM_DELAY_MILLISECONDS = 100;

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Register properties
        $this->RegisterProperties();

        // Create profiles
        $this->CreateProfiles();

        // Register variables
        $this->RegisterVariables();

        // Register timers
        $this->RegisterTimers();
    }

    public function ApplyChanges()
    {
        // Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Never delete this line!
        parent::ApplyChanges();

        // Check runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        // Create links
        $this->CreateLinks();

        // Set options
        $this->SetOptions();

        // Disable timers
        $this->DisableTimers();

        // Register messages
        $this->RegisterMessages();

        // Updates
        $this->SetVariableValue('BoostMode');
        $this->SetVariableValue('ThermostatTemperature');
        $this->SetVariableValue('RoomTemperature');
        $this->CheckDoorWindowSensors();
        $this->SetActualAction();
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();

        // Delete profiles
        $this->DeleteProfiles();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug('MessageSink', 'Message from SenderID ' . $SenderID . ' with Message ' . $Message . "\r\n Data: " . print_r($Data, true), 0);
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

            // $Data[0] = actual value
            // $Data[1] = difference to last value
            // $Data[2] = last value
            case VM_UPDATE:

                // Door window sensors
                $doorWindowSensors = json_decode($this->ReadPropertyString('DoorWindowSensors'), true);
                if (!empty($doorWindowSensors)) {
                    if (array_search($SenderID, array_column($doorWindowSensors, 'ID')) !== false) {
                        if ($Data[1]) {
                            $this->CheckDoorWindowSensors();
                        }
                    }
                }

                // Radiator variables
                if ($SenderID == $this->ReadPropertyInteger('BoostMode')) {
                    if ($Data[1]) {
                        $this->SetVariableValue('BoostMode');
                    }
                }
                if ($SenderID == $this->ReadPropertyInteger('ThermostatTemperature')) {
                    if ($Data[1]) {
                        $this->SetVariableValue('ThermostatTemperature');
                    }
                }
                if ($SenderID == $this->ReadPropertyInteger('RoomTemperature')) {
                    if ($Data[1]) {
                        $this->SetVariableValue('RoomTemperature');
                    }
                }
                break;

            // $Data[0] = last run
            // $Data[1] = next run
            case EM_UPDATE:
                // Weekly schedule
                $this->SetActualAction();
                break;

        }
    }

    protected function KernelReady()
    {
        $this->ApplyChanges();
    }

    public function ShowRegisteredMessages(): void
    {
        $registeredMessages = $this->GetMessageList();
        echo "Registrierte Nachrichten:\n\n";
        print_r($registeredMessages);
    }

    //#################### Request action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'AutomaticMode':
                $this->ToggleAutomaticMode($Value);
                break;

            case 'SetPointTemperature':
                $this->ToggleSetPointTemperature($Value);
                break;

            case 'BoostMode':
                $this->ToggleBoostMode($Value);
                break;

        }
    }

    //#################### Private

    private function RegisterProperties(): void
    {
        // Visibility
        $this->RegisterPropertyBoolean('EnableAutomaticMode', true);
        $this->RegisterPropertyBoolean('EnableWeeklySchedule', true);
        $this->RegisterPropertyBoolean('EnableDoorWindowState', true);
        $this->RegisterPropertyBoolean('EnableSetPointTemperature', true);
        $this->RegisterPropertyBoolean('EnableBoostMode', true);
        $this->RegisterPropertyBoolean('EnableThermostatTemperature', true);
        $this->RegisterPropertyBoolean('EnableRoomTemperature', true);

        // Radiator thermostat
        $this->RegisterPropertyInteger('ThermostatTemperature', 0);
        $this->RegisterPropertyInteger('BoostMode', 0);
        $this->RegisterPropertyInteger('RoomTemperature', 0);

        // Temperatures
        $this->RegisterPropertyFloat('SetBackTemperature', 18.0);
        $this->RegisterPropertyFloat('PreHeatingTemperature', 20.0);
        $this->RegisterPropertyFloat('HeatingTemperature', 22.0);

        // Weekly schedule
        $this->RegisterPropertyInteger('WeeklySchedule', 0);
        $this->RegisterPropertyInteger('ExecutionDelay', 3);

        // Door window sensors
        $this->RegisterPropertyString('DoorWindowSensors', '[]');
        $this->RegisterPropertyInteger('ReviewDelay', 0);
    }

    private function ValidatePropertyVariable(string $Name): bool
    {
        $validate = false;
        $variable = $this->ReadPropertyInteger($Name);
        if ($variable != 0 && IPS_ObjectExists($variable)) {
            $validate = true;
        }
        return $validate;
    }

    private function GetPropertyTemperature(string $Name): float
    {
        return $this->ReadPropertyFloat($Name);
    }

    private function CreateProfiles(): void
    {
        // Automatic mode
        $profile = 'HKTS.' . $this->InstanceID . '.AutomaticMode';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Execute', -1);
        IPS_SetVariableProfileAssociation($profile, 1, 'An', 'Clock', 0x00FF00);

        // Door and window status
        $profile = 'HKTS.' . $this->InstanceID . '.DoorWindowState';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Geschlossen', 'Window', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 1, 'Geöffnet', 'Window', 0x0000FF);

        // Set point temperature
        $profile = 'HKTS.' . $this->InstanceID . '.SetPointTemperature';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 2);
        }
        IPS_SetVariableProfileIcon($profile, 'Temperature');
        IPS_SetVariableProfileValues($profile, 0, 31, 0.5);
        IPS_SetVariableProfileDigits($profile, 1);
        IPS_SetVariableProfileText($profile, '', ' °C');

        // Boost control
        $profile = 'HKTS.' . $this->InstanceID . '.BoostMode';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Flame', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 1, 'An', 'Flame', 0xFF0000);
    }

    private function DeleteProfiles(): void
    {
        $profiles = ['AutomaticMode', 'DoorWindowState', 'SetPointTemperature', 'BoostMode'];
        foreach ($profiles as $profile) {
            $profileName = 'HKTS.' . $this->InstanceID . '.' . $profile;
            if (@IPS_VariableProfileExists($profileName)) {
                IPS_DeleteVariableProfile($profileName);
            }
        }
    }

    private function RegisterVariables(): void
    {
        // Automatic mode
        $profile = 'HKTS.' . $this->InstanceID . '.AutomaticMode';
        $this->RegisterVariableBoolean('AutomaticMode', 'Automatik', $profile, 0);
        $this->EnableAction('AutomaticMode');

        // Door and window status
        $profile = 'HKTS.' . $this->InstanceID . '.DoorWindowState';
        $this->RegisterVariableBoolean('DoorWindowState', 'Tür- / Fensterstatus', $profile, 2);

        // Set point temperature weekly schedule
        $profile = 'HKTS.' . $this->InstanceID . '.SetPointTemperature';
        $this->RegisterVariableFloat('SetPointTemperature', 'Soll-Temperatur', $profile, 3);
        $this->EnableAction('SetPointTemperature');

        // Boost mode
        $profile = 'HKTS.' . $this->InstanceID . '.BoostMode';
        $this->RegisterVariableBoolean('BoostMode', 'Boost-Modus', $profile, 4);
        $this->EnableAction('BoostMode');

        // Thermostat temperature
        $this->RegisterVariableFloat('ThermostatTemperature', 'Thermostat-Temperatur', 'Temperature', 5);
        IPS_SetIcon($this->GetIDForIdent('ThermostatTemperature'), 'Radiator');

        // Room temperature
        $this->RegisterVariableFloat('RoomTemperature', 'Raum-Temperatur', 'Temperature', 6);
    }

    private function CreateLinks(): void
    {
        // Create link for weekly schedule
        $weeklySchedule = $this->ReadPropertyInteger('WeeklySchedule');
        $link = @IPS_GetLinkIDByName('Wochenplan', $this->InstanceID);
        if ($weeklySchedule != 0 && @IPS_ObjectExists($weeklySchedule)) {
            // Check for existing link
            if ($link === false) {
                $link = IPS_CreateLink();
            }
            IPS_SetParent($link, $this->InstanceID);
            IPS_SetPosition($link, 1);
            IPS_SetName($link, 'Wochenplan');
            IPS_SetIcon($link, 'Calendar');
            IPS_SetLinkTargetID($link, $weeklySchedule);
        } else {
            if ($link !== false) {
                IPS_SetHidden($link, true);
            }
        }
    }

    private function SetOptions(): void
    {
        // Automatic mode
        $id = $this->GetIDForIdent('AutomaticMode');
        $use = $this->ReadPropertyBoolean('EnableAutomaticMode');
        IPS_SetHidden($id, !$use);

        // Weekly schedule
        $id = @IPS_GetLinkIDByName('Wochenplan', $this->InstanceID);
        if ($id !== false) {
            $use = $this->ReadPropertyBoolean('EnableWeeklySchedule');
            IPS_SetHidden($id, !$use);
        }

        // Door and window status
        $id = $this->GetIDForIdent('DoorWindowState');
        $use = $this->ReadPropertyBoolean('EnableDoorWindowState');
        IPS_SetHidden($id, !$use);

        // Set point temperature
        $id = $this->GetIDForIdent('SetPointTemperature');
        $use = $this->ReadPropertyBoolean('EnableSetPointTemperature');
        IPS_SetHidden($id, !$use);

        // Boost mode
        $id = $this->GetIDForIdent('BoostMode');
        $use = $this->ReadPropertyBoolean('EnableBoostMode');
        IPS_SetHidden($id, !$use);

        // Actual temperature
        $id = $this->GetIDForIdent('ThermostatTemperature');
        $use = $this->ReadPropertyBoolean('EnableThermostatTemperature');
        IPS_SetHidden($id, !$use);

        // Room temperature
        $id = $this->GetIDForIdent('RoomTemperature');
        $use = $this->ReadPropertyBoolean('EnableRoomTemperature');
        IPS_SetHidden($id, !$use);
    }

    private function RegisterTimers(): void
    {
        // Review door and window sensor
        $this->RegisterTimer('ReviewDoorWindowSensors', 0, 'HKTS_ReviewDoorWindowSensors(' . $this->InstanceID . ');');
    }

    private function DisableTimers(): void
    {
        // Review door and window sensor
        $this->SetTimerInterval('ReviewDoorWindowSensors', 0);
    }

    private function UnregisterMessages(): void
    {
        foreach ($this->GetMessageList() as $id => $registeredMessage) {
            foreach ($registeredMessage as $messageType) {
                if ($messageType == VM_UPDATE) {
                    $this->UnregisterMessage($id, VM_UPDATE);
                }
                if ($messageType == EM_UPDATE) {
                    $this->UnregisterMessage($id, EM_UPDATE);
                }
            }
        }
    }

    private function RegisterMessages(): void
    {
        // Unregister first
        $this->UnregisterMessages();

        // Weekly schedule
        $weeklySchedule = $this->ReadPropertyInteger('WeeklySchedule');
        if ($weeklySchedule != 0 && @IPS_ObjectExists($weeklySchedule)) {
            $this->RegisterMessage($weeklySchedule, EM_UPDATE);
        }

        // Register door window sensors
        $doorWindowSensors = json_decode($this->ReadPropertyString('DoorWindowSensors'));
        if (!empty($doorWindowSensors)) {
            foreach ($doorWindowSensors as $doorWindowSensor) {
                $use = $doorWindowSensor->UseSensor;
                $id = $doorWindowSensor->ID;
                if ($use) {
                    if ($id != 0 && @IPS_ObjectExists($id)) {
                        $this->RegisterMessage($id, VM_UPDATE);
                    }
                }
            }
        }

        // Radiator variables
        $name = 'ThermostatTemperature';
        if ($this->ValidatePropertyVariable($name)) {
            $id = $this->ReadPropertyInteger($name);
            $this->RegisterMessage($id, VM_UPDATE);
        }
        $name = 'BoostMode';
        if ($this->ValidatePropertyVariable($name)) {
            $id = $this->ReadPropertyInteger($name);
            $this->RegisterMessage($id, VM_UPDATE);
        }
        $name = 'RoomTemperature';
        if ($this->ValidatePropertyVariable($name)) {
            $id = $this->ReadPropertyInteger($name);
            $this->RegisterMessage($id, VM_UPDATE);
        }
    }
}