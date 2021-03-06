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
 * @version     1.00-4
 * @date        2020-03-02, 18:00, 1583168400
 * @review      2020-03-02, 18:00
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
    // Helper
    use HKTS_chimneyMonitoring;
    use HKTS_doorWindowSensors;
    use HKTS_radiatorThermostat;
    use HKTS_weeklySchedule;

    // Constants
    private const MINIMUM_DELAY_MILLISECONDS = 100;
    private const HOMEMATIC_DEVICE_GUID = '{EE4A81C6-5C90-4DB7-AD2F-F6BBD521412E}';

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

        // Validate configuration
        $this->ValidateConfiguration();

        // Disable timers
        $this->DisableTimers();

        // Create links
        $this->CreateLinks();

        // Set options
        $this->SetOptions();

        // Register messages
        $this->RegisterMessages();

        // Check condition
        $this->CheckActualCondition();
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
        $this->SendDebug(__FUNCTION__, 'SenderID: ' . $SenderID . ', Message: ' . $Message . ', Data: ' . print_r($Data, true), 0);
        if (!empty($Data)) {
            foreach ($Data as $key => $value) {
                $this->SendDebug(__FUNCTION__, 'Data[' . $key . '] = ' . json_encode($value), 0);
            }
        }
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

            /*
             * $Data[0] = Actual value
             * $Data[1] = Difference to last value
             * $Data[2] = Last value
             */
            case VM_UPDATE:
                // Thermostat temperature
                if ($SenderID == $this->ReadPropertyInteger('ThermostatTemperature')) {
                    if ($Data[1]) {
                        $this->SendDebug(__FUNCTION__, 'Die Thermostat-Temperatur hat sich auf ' . $Data[0] . '°C geändert.', 0);
                        $this->UpdateThermostatTemperature();
                        if (!$this->GetValue('AutomaticMode') && !$this->GetValue('DoorWindowState')) {
                            $this->SetValue('SetPointTemperature', $this->GetValue('ThermostatTemperature'));
                        }
                    }
                }
                // Room temperature
                if ($SenderID == $this->ReadPropertyInteger('RoomTemperature')) {
                    if ($Data[1]) {
                        $this->SendDebug(__FUNCTION__, 'Die Raum-Temperatur hat sich auf ' . $Data[0] . '°C geändert.', 0);
                        $this->UpdateRoomTemperature();
                    }
                }
                // Door and window sensors
                $doorWindowSensors = json_decode($this->ReadPropertyString('DoorWindowSensors'), true);
                if (!empty($doorWindowSensors)) {
                    if (array_search($SenderID, array_column($doorWindowSensors, 'ID')) !== false) {
                        if ($Data[1]) {
                            $this->CheckDoorWindowSensors();
                        }
                    }
                }
                // Battery state
                if ($SenderID == $this->ReadPropertyInteger('BatteryState')) {
                    if ($Data[1]) {
                        $this->SendDebug(__FUNCTION__, 'Der Batteriestatus hat sich auf den Wert ' . json_encode($Data[0]) . 'geändert.', 0);
                        $this->UpdateBatteryState();
                    }
                }
                // Chimney state
                if ($SenderID == $this->ReadPropertyInteger('ChimneyMonitoring')) {
                    if ($Data[1]) {
                        $this->SendDebug(__FUNCTION__, 'Der Kaminstatus hat sich auf den Wert ' . json_encode($Data[0]) . ' geändert.', 0);
                        $this->TriggerChimneyMonitoring($Data[0]);
                    }
                }
                break;

            // $Data[0] = last run
            // $Data[1] = next run
            case EM_UPDATE:
                // Weekly schedule
                $this->TriggerAction(true);
                break;

        }
    }

    public function GetConfigurationForm()
    {
        $formdata = json_decode(file_get_contents(__DIR__ . '/form.json'));
        // Registered messages
        $registeredVariables = $this->GetMessageList();
        foreach ($registeredVariables as $senderID => $messageID) {
            $senderName = IPS_GetName($senderID);
            $parentName = $senderName;
            $parentID = IPS_GetParent($senderID);
            if (is_int($parentID) && $parentID != 0 && @IPS_ObjectExists($parentID)) {
                $parentName = IPS_GetName($parentID);
            }
            switch ($messageID) {
                case [10001]:
                    $messageDescription = 'IPS_KERNELSTARTED';
                    break;

                case [10603]:
                    $messageDescription = 'VM_UPDATE';
                    break;

                case [10803]:
                    $messageDescription = 'EM_UPDATE';
                    break;

                default:
                    $messageDescription = 'keine Bezeichnung';
            }
            $formdata->elements[8]->items[0]->values[] = [
                'ParentName'                                            => $parentName,
                'SenderID'                                              => $senderID,
                'SenderName'                                            => $senderName,
                'MessageID'                                             => $messageID,
                'MessageDescription'                                    => $messageDescription];
        }
        return json_encode($formdata);
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

            case 'PartyMode':
                $this->TogglePartyMode($Value);
                break;

        }
    }

    protected function KernelReady()
    {
        $this->ApplyChanges();
    }

    //#################### Private

    private function RegisterProperties(): void
    {
        // Visibility
        $this->RegisterPropertyBoolean('EnableAutomaticMode', true);
        $this->RegisterPropertyBoolean('EnableWeeklySchedule', true);
        $this->RegisterPropertyBoolean('EnableSetPointTemperature', true);
        $this->RegisterPropertyBoolean('EnableBoostMode', true);
        $this->RegisterPropertyBoolean('EnablePartyMode', true);
        $this->RegisterPropertyBoolean('EnableThermostatTemperature', true);
        $this->RegisterPropertyBoolean('EnableRoomTemperature', true);
        $this->RegisterPropertyBoolean('EnableDoorWindowState', true);
        $this->RegisterPropertyBoolean('EnableBatteryState', true);
        $this->RegisterPropertyBoolean('EnableChimneyState', true);
        $this->RegisterPropertyBoolean('EnableBoostModeTimer', true);
        $this->RegisterPropertyBoolean('EnablePartyModeTimer', true);
        $this->RegisterPropertyBoolean('EnableDoorWindowStateTimer', true);

        // Radiator thermostat
        $this->RegisterPropertyInteger('DeviceType', 0);
        $this->RegisterPropertyInteger('ThermostatInstance', 0);
        $this->RegisterPropertyInteger('ThermostatControlMode', 0);
        $this->RegisterPropertyInteger('ThermostatTemperature', 0);
        $this->RegisterPropertyInteger('RoomTemperature', 0);
        $this->RegisterPropertyInteger('BatteryState', 0);

        // Temperatures
        $this->RegisterPropertyFloat('SetBackTemperature', 18.0);
        $this->RegisterPropertyFloat('PreHeatingTemperature', 20.0);
        $this->RegisterPropertyFloat('HeatingTemperature', 22.0);
        $this->RegisterPropertyFloat('BoostTemperature', 30.0);

        // Mode duration
        $this->RegisterPropertyInteger('BoostDuration', 5);
        $this->RegisterPropertyInteger('PartyDuration', 24);

        // Weekly schedule
        $this->RegisterPropertyInteger('WeeklySchedule', 0);
        $this->RegisterPropertyBoolean('AdjustTemperature', false);
        $this->RegisterPropertyInteger('ExecutionDelay', 3);

        // Door and window sensors
        $this->RegisterPropertyString('DoorWindowSensors', '[]');
        $this->RegisterPropertyInteger('ReviewDelay', 0);
        $this->RegisterPropertyBoolean('ReduceTemperature', true);
        $this->RegisterPropertyFloat('OpenDoorWindowTemperature', 12);
        $this->RegisterPropertyBoolean('ReactivateBoostMode', false);

        // Chimney Monitoring
        $this->RegisterPropertyInteger('ChimneyMonitoring', 0);
        $this->RegisterPropertyFloat('ChimneySetBackTemperature', 6.0);
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

        // Set point temperature
        $profile = 'HKTS.' . $this->InstanceID . '.SetPointTemperature';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 2);
        }
        IPS_SetVariableProfileIcon($profile, 'Temperature');
        IPS_SetVariableProfileValues($profile, 0, 31, 0.5);
        IPS_SetVariableProfileDigits($profile, 1);
        IPS_SetVariableProfileText($profile, '', ' °C');

        // Boost mode
        $profile = 'HKTS.' . $this->InstanceID . '.BoostMode';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Flame', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 1, 'An', 'Flame', 0xFF0000);

        // Party mode
        $profile = 'HKTS.' . $this->InstanceID . '.PartyMode';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Party', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 1, 'An', 'Hourglass', 0xFFFF00);

        // Door and window state
        $profile = 'HKTS.' . $this->InstanceID . '.DoorWindowState';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Geschlossen', 'Window', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 1, 'Geöffnet', 'Window', 0x0000FF);

        // Battery state
        $profile = 'HKTS.' . $this->InstanceID . '.BatteryState';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'OK', 'Battery', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 1, 'Batterie schwach', 'Battery', 0xFF0000);

        // Chimney state
        $profile = 'HKTS.' . $this->InstanceID . '.ChimneyState';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Flame', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 1, 'An', 'Flame', 0xFF0000);
    }

    private function DeleteProfiles(): void
    {
        $profiles = ['AutomaticMode', 'SetPointTemperature', 'BoostMode', 'PartyMode', 'DoorWindowState', 'BatteryState', 'ChimneyState'];
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

        // Set point temperature
        $profile = 'HKTS.' . $this->InstanceID . '.SetPointTemperature';
        $this->RegisterVariableFloat('SetPointTemperature', 'Soll-Temperatur', $profile, 2);
        $this->EnableAction('SetPointTemperature');

        // Boost mode
        $profile = 'HKTS.' . $this->InstanceID . '.BoostMode';
        $this->RegisterVariableBoolean('BoostMode', 'Boost-Modus', $profile, 3);
        $this->EnableAction('BoostMode');

        // Party mode
        $profile = 'HKTS.' . $this->InstanceID . '.PartyMode';
        $this->RegisterVariableBoolean('PartyMode', 'Party-Modus', $profile, 4);
        $this->EnableAction('PartyMode');

        // Thermostat temperature
        $this->RegisterVariableFloat('ThermostatTemperature', 'Thermostat-Temperatur', 'Temperature', 5);
        IPS_SetIcon($this->GetIDForIdent('ThermostatTemperature'), 'Radiator');

        // Room temperature
        $this->RegisterVariableFloat('RoomTemperature', 'Raum-Temperatur', 'Temperature', 6);

        // Door and window state
        $profile = 'HKTS.' . $this->InstanceID . '.DoorWindowState';
        $this->RegisterVariableBoolean('DoorWindowState', 'Tür- / Fensterstatus', $profile, 7);

        // Chimney state
        $profile = 'HKTS.' . $this->InstanceID . '.ChimneyState';
        $this->RegisterVariableBoolean('ChimneyState', 'Kaminstatus', $profile, 8);

        // Battery state
        $profile = 'HKTS.' . $this->InstanceID . '.BatteryState';
        $this->RegisterVariableBoolean('BatteryState', 'Batteriestatus', $profile, 9);

        // Boost mode timer info
        $this->RegisterVariableString('BoostModeTimer', 'Boost-Modus Timer', '', 10);
        $id = $this->GetIDForIdent('BoostModeTimer');
        IPS_SetIcon($id, 'Clock');

        // Party mode timer info
        $this->RegisterVariableString('PartyModeTimer', 'Party-Modus Timer', '', 11);
        $id = $this->GetIDForIdent('PartyModeTimer');
        IPS_SetIcon($id, 'Clock');

        // Door window state timer info
        $this->RegisterVariableString('DoorWindowStateTimer', 'Tür- / Fensterstatus Timer', '', 12);
        $id = $this->GetIDForIdent('DoorWindowStateTimer');
        IPS_SetIcon($id, 'Clock');
    }

    private function CreateLinks(): void
    {
        // Create link for weekly schedule
        $weeklySchedule = $this->ReadPropertyInteger('WeeklySchedule');
        $link = @IPS_GetLinkIDByName('Wochenplan', $this->InstanceID);
        if ($weeklySchedule != 0 && @IPS_ObjectExists($weeklySchedule)) {
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
            $hide = true;
            if ($this->ValidateEventPlan()) {
                if ($this->ReadPropertyBoolean('EnableWeeklySchedule') && $this->GetValue('AutomaticMode')) {
                    $hide = false;
                }
            }
            IPS_SetHidden($id, $hide);
        }

        // Set point temperature
        $id = $this->GetIDForIdent('SetPointTemperature');
        $use = $this->ReadPropertyBoolean('EnableSetPointTemperature');
        IPS_SetHidden($id, !$use);

        // Boost mode
        $id = $this->GetIDForIdent('BoostMode');
        $use = $this->ReadPropertyBoolean('EnableBoostMode');
        IPS_SetHidden($id, !$use);

        // Party mode
        $id = $this->GetIDForIdent('PartyMode');
        $use = $this->ReadPropertyBoolean('EnablePartyMode');
        IPS_SetHidden($id, !$use);

        // Thermostat temperature
        $id = $this->GetIDForIdent('ThermostatTemperature');
        $use = $this->ReadPropertyBoolean('EnableThermostatTemperature');
        IPS_SetHidden($id, !$use);

        // Room temperature
        $id = $this->GetIDForIdent('RoomTemperature');
        $use = $this->ReadPropertyBoolean('EnableRoomTemperature');
        IPS_SetHidden($id, !$use);

        // Door and window state
        $id = $this->GetIDForIdent('DoorWindowState');
        $use = $this->ReadPropertyBoolean('EnableDoorWindowState');
        IPS_SetHidden($id, !$use);

        // Battery state
        $id = $this->GetIDForIdent('BatteryState');
        $use = $this->ReadPropertyBoolean('EnableBatteryState');
        IPS_SetHidden($id, !$use);

        // Chimney state
        $id = $this->GetIDForIdent('ChimneyState');
        $chimneyMonitoring = $this->ReadPropertyInteger('ChimneyMonitoring');
        $use = false;
        if ($chimneyMonitoring != 0 && IPS_ObjectExists($chimneyMonitoring)) {
            $use = $this->ReadPropertyBoolean('EnableChimneyState');
        }
        IPS_SetHidden($id, !$use);

        // Boost mode timer info
        $id = $this->GetIDForIdent('BoostModeTimer');
        $use = $this->ReadPropertyBoolean('EnableBoostModeTimer');
        IPS_SetHidden($id, !$use);

        // Party mode timer info
        $id = $this->GetIDForIdent('PartyModeTimer');
        $use = $this->ReadPropertyBoolean('EnablePartyModeTimer');
        IPS_SetHidden($id, !$use);

        // Door windows state timer info
        $id = $this->GetIDForIdent('DoorWindowStateTimer');
        $use = $this->ReadPropertyBoolean('EnableDoorWindowStateTimer');
        IPS_SetHidden($id, !$use);
    }

    private function RegisterTimers(): void
    {
        $this->RegisterTimer('ReviewDoorWindowSensors', 0, 'HKTS_ReviewDoorWindowSensors(' . $this->InstanceID . ');');
        $this->RegisterTimer('DeactivateBoostMode', 0, 'HKTS_ToggleBoostMode(' . $this->InstanceID . ', false);');
        $this->RegisterTimer('DeactivatePartyMode', 0, 'HKTS_TogglePartyMode(' . $this->InstanceID . ', false);');
    }

    private function DisableTimers(): void
    {
        $this->SetTimerInterval('DeactivateBoostMode', 0);
        $this->SetValue('BoostModeTimer', '-');
        $this->SetTimerInterval('DeactivatePartyMode', 0);
        $this->SetValue('PartyModeTimer', '-');
        $this->SetTimerInterval('ReviewDoorWindowSensors', 0);
        $this->SetValue('DoorWindowStateTimer', '-');
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
        $id = $this->ReadPropertyInteger('WeeklySchedule');
        if ($id != 0 && @IPS_ObjectExists($id)) {
            $this->RegisterMessage($id, EM_UPDATE);
        }
        // Thermostat temperature
        $id = $this->ReadPropertyInteger('ThermostatTemperature');
        if ($id != 0 && IPS_ObjectExists($id)) {
            $this->RegisterMessage($id, VM_UPDATE);
        }
        // Room temperature
        $id = $this->ReadPropertyInteger('RoomTemperature');
        if ($id != 0 && IPS_ObjectExists($id)) {
            $this->RegisterMessage($id, VM_UPDATE);
        }
        // Door and window sensors
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
        // Battery state
        $id = $this->ReadPropertyInteger('BatteryState');
        if ($id != 0 && IPS_ObjectExists($id)) {
            $this->RegisterMessage($id, VM_UPDATE);
        }
        // Chimney monitoring
        $id = $this->ReadPropertyInteger('ChimneyMonitoring');
        if ($id != 0 && IPS_ObjectExists($id)) {
            $this->RegisterMessage($id, VM_UPDATE);
        }
    }

    private function ValidateConfiguration(): void
    {
        $state = 102;
        $deviceType = $this->ReadPropertyInteger('DeviceType');
        // Thermostat instance
        $id = $this->ReadPropertyInteger('ThermostatInstance');
        if ($id != 0) {
            if (!@IPS_ObjectExists($id)) {
                $this->LogMessage('Konfiguration: Instanz Heizkörperthermostat ID ungültig!', KL_ERROR);
                $state = 200;
            } else {
                $instance = IPS_GetInstance($id);
                $moduleID = $instance['ModuleInfo']['ModuleID'];
                if ($moduleID !== self::HOMEMATIC_DEVICE_GUID) {
                    $this->LogMessage('Konfiguration: Instanz Heizkörperthermostat GUID ungültig!', KL_ERROR);
                    $state = 200;
                } else {
                    // Check channel
                    $config = json_decode(IPS_GetConfiguration($id));
                    $address = strstr($config->Address, ':', false);
                    switch ($deviceType) {
                        // HM
                        case 1:
                            if ($address != ':4') {
                                $this->LogMessage('Konfiguration: Instanz Heizkörperthermostat Kanal ungültig!', KL_ERROR);
                                $state = 200;
                            }
                            break;

                        // HmIP
                        case 2:
                        case 3:
                            if ($address != ':1') {
                                $this->LogMessage('Konfiguration: Instanz Heizkörperthermostat Kanal ungültig!', KL_ERROR);
                                $state = 200;
                            }
                            break;

                    }
                }
            }
        }
        // Thermostat control mode
        $id = $this->ReadPropertyInteger('ThermostatControlMode');
        if ($id != 0) {
            if (!@IPS_ObjectExists($id)) {
                $this->LogMessage('Konfiguration: Variable Steuerungsmodus ID ungültig!', KL_ERROR);
                $state = 200;
            } else {
                $parent = IPS_GetParent($id);
                if ($parent == 0) {
                    $this->LogMessage('Konfiguration: Variable Steuerungsmodus, keine übergeordnete ID gefunden!', KL_ERROR);
                    $state = 200;
                } else {
                    $instance = IPS_GetInstance($parent);
                    $moduleID = $instance['ModuleInfo']['ModuleID'];
                    if ($moduleID !== self::HOMEMATIC_DEVICE_GUID) {
                        $this->LogMessage('Konfiguration: Variable Steuerungsmodus GUID ungültig!', KL_ERROR);
                        $state = 200;
                    } else {
                        // Check channel
                        $config = json_decode(IPS_GetConfiguration($parent));
                        $address = strstr($config->Address, ':', false);
                        switch ($deviceType) {
                            // HM
                            case 1:
                                if ($address != ':4') {
                                    $this->LogMessage('Konfiguration: Variable Steuerungsmodus Kanal ungültig!', KL_ERROR);
                                    $state = 200;
                                }
                                break;

                            // HmIP
                            case 2:
                            case 3:
                                if ($address != ':1') {
                                    $this->LogMessage('Konfiguration: Variable Steuerungsmodus Kanal ungültig!', KL_ERROR);
                                    $state = 200;
                                }
                                break;

                        }
                    }
                }
                $ident = IPS_GetObject($id)['ObjectIdent'];
                switch ($deviceType) {
                    // HM
                    case 1:
                        if ($ident != 'CONTROL_MODE') {
                            $this->LogMessage('Konfiguration: Variable Steuerungsmodus IDENT ungültig!', KL_ERROR);
                            $state = 200;
                        }
                        break;

                    // HmIP
                    case 2:
                    case 3:
                        if ($ident != 'SET_POINT_MODE') {
                            $this->LogMessage('Konfiguration: Variable Steuerungsmodus IDENT ungültig!', KL_ERROR);
                            $state = 200;
                        }
                        break;
                }
            }
        }
        // Thermostat temperature
        $id = $this->ReadPropertyInteger('ThermostatTemperature');
        if ($id != 0) {
            if (!@IPS_ObjectExists($id)) {
                $this->LogMessage('Konfiguration: Variable Thermostat-Temperatur ID ungültig!', KL_ERROR);
                $state = 200;
            } else {
                $parent = IPS_GetParent($id);
                if ($parent == 0) {
                    $this->LogMessage('Konfiguration: Variable Thermostat-Temperatur, keine übergeordnete ID gefunden!', KL_ERROR);
                    $state = 200;
                } else {
                    $instance = IPS_GetInstance($parent);
                    $moduleID = $instance['ModuleInfo']['ModuleID'];
                    if ($moduleID !== self::HOMEMATIC_DEVICE_GUID) {
                        $this->LogMessage('Konfiguration: Variable Thermostat-Temperatur GUID ungültig!', KL_ERROR);
                        $state = 200;
                    } else {
                        // Check channel
                        $config = json_decode(IPS_GetConfiguration($parent));
                        $address = strstr($config->Address, ':', false);
                        switch ($deviceType) {
                            // HM
                            case 1:
                                if ($address != ':4') {
                                    $this->LogMessage('Konfiguration: Variable Thermostat-Temperatur Kanal ungültig!', KL_ERROR);
                                    $state = 200;
                                }
                                break;

                            // HmIP
                            case 2:
                            case 3:
                                if ($address != ':1') {
                                    $this->LogMessage('Konfiguration: Variable Thermostat-Temperatur Kanal ungültig!', KL_ERROR);
                                    $state = 200;
                                }
                                break;

                        }
                    }
                }
                $ident = IPS_GetObject($id)['ObjectIdent'];
                switch ($deviceType) {
                    // HM
                    case 1:
                        if ($ident != 'SET_TEMPERATURE') {
                            $this->LogMessage('Konfiguration: Variable Thermostat-Temperatur IDENT ungültig!', KL_ERROR);
                            $state = 200;
                        }
                        break;

                    // HmIP
                    case 2:
                    case 3:
                        if ($ident != 'SET_POINT_TEMPERATURE') {
                            $this->LogMessage('Konfiguration: Variable Thermostat-Temperatur IDENT ungültig!', KL_ERROR);
                            $state = 200;
                        }
                        break;
                }
            }
        }
        // Battery state
        $id = $this->ReadPropertyInteger('BatteryState');
        if ($id != 0) {
            if (!@IPS_ObjectExists($id)) {
                $this->LogMessage('Konfiguration: Variable Batteriestatus ID ungültig!', KL_ERROR);
                $state = 200;
            } else {
                $parent = IPS_GetParent($id);
                if ($parent == 0) {
                    $this->LogMessage('Konfiguration: Variable Batteriestatus, keine übergeordnete ID gefunden!', KL_ERROR);
                    $state = 200;
                } else {
                    $instance = IPS_GetInstance($parent);
                    $moduleID = $instance['ModuleInfo']['ModuleID'];
                    if ($moduleID !== self::HOMEMATIC_DEVICE_GUID) {
                        $this->LogMessage('Konfiguration: Variable Batteriestatus GUID ungültig!', KL_ERROR);
                        $state = 200;
                    } else {
                        // Check channel
                        $config = json_decode(IPS_GetConfiguration($parent));
                        $address = strstr($config->Address, ':', false);
                        switch ($deviceType) {
                            // HM
                            case 1:
                                // HmIP
                            case 2:
                            case 3:
                                if ($address != ':0') {
                                    $this->LogMessage('Konfiguration: Variable Batteriestatus Kanal ungültig!', KL_ERROR);
                                    $state = 200;
                                }
                                break;

                        }
                    }
                }
                $ident = IPS_GetObject($id)['ObjectIdent'];
                switch ($deviceType) {
                    // HM
                    case 1:
                        if ($ident != 'LOWBAT') {
                            $this->LogMessage('Konfiguration: Variable Batteriestatus IDENT ungültig!', KL_ERROR);
                            $state = 200;
                        }
                        break;

                    // HmIP
                    case 2:
                    case 3:
                        if ($ident != 'LOW_BAT') {
                            $this->LogMessage('Konfiguration: Variable Batteriestatus IDENT ungültig!', KL_ERROR);
                            $state = 200;
                        }
                        break;
                }
            }
        }
        // Set state
        $this->SetStatus($state);
    }

    private function CheckActualCondition(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        // Update values
        $this->UpdateThermostatTemperature();
        $this->UpdateRoomTemperature();
        $this->UpdateBatteryState();
        // Check automatic mode
        if (!$this->GetValue('AutomaticMode')) {
            return;
        }
        // Check chimney
        $chimneyState = false;
        $id = $this->ReadPropertyInteger('ChimneyMonitoring');
        if ($id != 0 && IPS_ObjectExists($id)) {
            $chimneyState = GetValueBoolean($id);
        }
        $this->SetValue('ChimneyState', $chimneyState);
        if ($chimneyState) {
            $this->TriggerChimneyMonitoring(true);
            return;
        }
        // Adjust temperature
        $this->AdjustTemperature();
        // Check door and window sensors
        $this->CheckDoorWindowSensors();
    }
}