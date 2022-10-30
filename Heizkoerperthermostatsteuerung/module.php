<?php

/**
 * @project       Heizkoerperthermostatsteuerung/Heizkoerperthermostatsteuerung
 * @file          module.php
 * @author        Ulrich Bittner
 * @copyright     2022 Ulrich Bittner
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUnused */

declare(strict_types=1);

include_once __DIR__ . '/helper/HKTS_autoload.php';

class Heizkoerperthermostatsteuerung extends IPSModule
{
    //Helper
    use HKTS_BackupRestore;
    use HKTS_ChimneyMonitoring;
    use HKTS_Config;
    use HKTS_DoorWindowSensors;
    use HKTS_RadiatorThermostat;
    use HKTS_WeeklySchedule;

    //Constants
    private const LIBRARY_GUID = '{7EF35A5D-430D-6D3A-82D7-59F0C56C3A56}';
    private const MODULE_NAME = 'Heizkoerperthermostatsteuerung';
    private const MODULE_PREFIX = 'UBHKTS';
    private const MODULE_VERSION = '2.0-44, 30.10.2022';
    private const MINIMUM_DELAY_MILLISECONDS = 100;
    private const HOMEMATIC_DEVICE_GUID = '{EE4A81C6-5C90-4DB7-AD2F-F6BBD521412E}';
    private const ABLAUFSTEUERUNG_MODULE_GUID = '{0559B287-1052-A73E-B834-EBD9B62CB938}';
    private const ABLAUFSTEUERUNG_MODULE_PREFIX = 'AST';

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        ########## Properties

        //Info
        $this->RegisterPropertyString('Note', '');
        //Functions
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
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
        //Radiator thermostat
        $this->RegisterPropertyInteger('DeviceType', 0);
        $this->RegisterPropertyInteger('ThermostatInstance', 0);
        $this->RegisterPropertyInteger('ThermostatControlMode', 0);
        $this->RegisterPropertyInteger('ThermostatTemperature', 0);
        $this->RegisterPropertyInteger('RoomTemperature', 0);
        $this->RegisterPropertyInteger('BatteryState', 0);
        //Temperatures
        $this->RegisterPropertyFloat('SetBackTemperature', 18.0);
        $this->RegisterPropertyFloat('PreHeatingTemperature', 20.0);
        $this->RegisterPropertyFloat('HeatingTemperature', 22.0);
        $this->RegisterPropertyFloat('BoostTemperature', 30.0);
        //Mode duration
        $this->RegisterPropertyInteger('BoostDuration', 5);
        $this->RegisterPropertyInteger('PartyDuration', 24);
        //Weekly schedule
        $this->RegisterPropertyInteger('WeeklySchedule', 0);
        $this->RegisterPropertyBoolean('AdjustTemperature', false);
        $this->RegisterPropertyInteger('ExecutionDelay', 3);
        //Door and window sensors
        $this->RegisterPropertyString('DoorWindowSensors', '[]');
        $this->RegisterPropertyInteger('ReviewDelay', 0);
        $this->RegisterPropertyBoolean('ReduceTemperature', true);
        $this->RegisterPropertyFloat('OpenDoorWindowTemperature', 12);
        $this->RegisterPropertyBoolean('ReactivateBoostMode', false);
        //Chimney Monitoring
        $this->RegisterPropertyInteger('ChimneyMonitoring', 0);
        $this->RegisterPropertyFloat('ChimneySetBackTemperature', 6.0);
        //Command control
        $this->RegisterPropertyInteger('CommandControl', 0);

        ########## Variables

        //Automatic mode
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.AutomaticMode';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Execute', -1);
        IPS_SetVariableProfileAssociation($profile, 1, 'An', 'Clock', 0x00FF00);
        $id = @$this->GetIDForIdent('AutomaticMode');
        $this->RegisterVariableBoolean('AutomaticMode', 'Automatik', $profile, 10);
        $this->EnableAction('AutomaticMode');
        if (!$id) {
            $this->SetValue('AutomaticMode', true);
        }

        //Set point temperature
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.SetPointTemperature';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 2);
        }
        IPS_SetVariableProfileIcon($profile, 'Temperature');
        IPS_SetVariableProfileValues($profile, 0, 31, 0.5);
        IPS_SetVariableProfileDigits($profile, 1);
        IPS_SetVariableProfileText($profile, '', ' °C');
        $this->RegisterVariableFloat('SetPointTemperature', 'Soll-Temperatur', $profile, 30);
        $this->EnableAction('SetPointTemperature');

        //Boost mode
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.BoostMode';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Flame', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 1, 'An', 'Flame', 0xFF0000);
        $this->RegisterVariableBoolean('BoostMode', 'Boost-Modus', $profile, 40);
        $this->EnableAction('BoostMode');

        //Party mode
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.PartyMode';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Party', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 1, 'An', 'Hourglass', 0xFFFF00);
        $this->RegisterVariableBoolean('PartyMode', 'Party-Modus', $profile, 50);
        $this->EnableAction('PartyMode');

        //Thermostat temperature
        $id = @$this->GetIDForIdent('ThermostatTemperature');
        $this->RegisterVariableFloat('ThermostatTemperature', 'Thermostat-Temperatur', 'Temperature', 60);
        if (!$id) {
            IPS_SetIcon($this->GetIDForIdent('ThermostatTemperature'), 'Radiator');
        }

        //Room temperature
        $this->RegisterVariableFloat('RoomTemperature', 'Raum-Temperatur', 'Temperature', 70);

        //Door and window state
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.DoorWindowState';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Geschlossen', 'Window', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 1, 'Geöffnet', 'Window', 0x0000FF);
        $this->RegisterVariableBoolean('DoorWindowState', 'Tür- / Fensterstatus', $profile, 80);

        //Battery state
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.BatteryState';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'OK', 'Battery', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 1, 'Batterie schwach', 'Battery', 0xFF0000);
        $this->RegisterVariableBoolean('BatteryState', 'Batteriestatus', $profile, 90);

        //Chimney state
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.ChimneyState';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Flame', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 1, 'An', 'Flame', 0xFF0000);
        $this->RegisterVariableBoolean('ChimneyState', 'Kaminstatus', $profile, 100);

        //Boost mode timer info
        $id = @$this->GetIDForIdent('BoostModeTimer');
        $this->RegisterVariableString('BoostModeTimer', 'Boost-Modus Timer', '', 110);
        if (!$id) {
            IPS_SetIcon($this->GetIDForIdent('BoostModeTimer'), 'Clock');
        }

        //Party mode timer info
        $id = @$this->GetIDForIdent('PartyModeTimer');
        $this->RegisterVariableString('PartyModeTimer', 'Party-Modus Timer', '', 120);
        if (!$id) {
            IPS_SetIcon($this->GetIDForIdent('PartyModeTimer'), 'Clock');
        }

        //Door window state timer info
        $id = @$this->GetIDForIdent('DoorWindowStateTimer');
        $this->RegisterVariableString('DoorWindowStateTimer', 'Tür- / Fensterstatus Timer', '', 130);
        if (!$id) {
            IPS_SetIcon($this->GetIDForIdent('DoorWindowStateTimer'), 'Clock');
        }

        ########## Timer

        $this->RegisterTimer('ReviewDoorWindowSensors', 0, self::MODULE_PREFIX . '_ReviewDoorWindowSensors(' . $this->InstanceID . ');');
        $this->RegisterTimer('DeactivateBoostMode', 0, self::MODULE_PREFIX . '_ToggleBoostMode(' . $this->InstanceID . ', false);');
        $this->RegisterTimer('DeactivatePartyMode', 0, self::MODULE_PREFIX . '_TogglePartyMode(' . $this->InstanceID . ', false);');
    }

    public function ApplyChanges()
    {
        //Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        //Never delete this line!
        parent::ApplyChanges();

        //Check runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        $this->UnlockSemaphore('SetThermostatTemperature');

        ########## WebFront options

        //Automatic mode
        IPS_SetHidden($this->GetIDForIdent('AutomaticMode'), !$this->ReadPropertyBoolean('EnableAutomaticMode'));

        //Weekly schedule
        $id = @IPS_GetLinkIDByName('Wochenplan', $this->InstanceID);
        if (is_int($id)) {
            $hide = true;
            if ($this->ValidateEventPlan()) {
                if ($this->ReadPropertyBoolean('EnableWeeklySchedule') && $this->GetValue('AutomaticMode')) {
                    $hide = false;
                }
            }
            IPS_SetHidden($id, $hide);
        }

        //Set point temperature
        IPS_SetHidden($this->GetIDForIdent('SetPointTemperature'), !$this->ReadPropertyBoolean('EnableSetPointTemperature'));

        //Boost mode
        IPS_SetHidden($this->GetIDForIdent('BoostMode'), !$this->ReadPropertyBoolean('EnableBoostMode'));

        //Party mode
        IPS_SetHidden($this->GetIDForIdent('PartyMode'), !$this->ReadPropertyBoolean('EnablePartyMode'));

        //Thermostat temperature
        IPS_SetHidden($this->GetIDForIdent('ThermostatTemperature'), !$this->ReadPropertyBoolean('EnableThermostatTemperature'));

        //Room temperature
        IPS_SetHidden($this->GetIDForIdent('RoomTemperature'), !$this->ReadPropertyBoolean('EnableRoomTemperature'));

        //Door and window state
        IPS_SetHidden($this->GetIDForIdent('DoorWindowState'), !$this->ReadPropertyBoolean('EnableDoorWindowState'));

        //Battery state
        IPS_SetHidden($this->GetIDForIdent('BatteryState'), !$this->ReadPropertyBoolean('EnableBatteryState'));

        //Chimney state
        $chimneyMonitoring = $this->ReadPropertyInteger('ChimneyMonitoring');
        $use = false;
        if ($chimneyMonitoring != 0 && IPS_ObjectExists($chimneyMonitoring)) {
            $use = $this->ReadPropertyBoolean('EnableChimneyState');
        }
        IPS_SetHidden($this->GetIDForIdent('ChimneyState'), !$use);

        //Boost mode timer info
        IPS_SetHidden($this->GetIDForIdent('BoostModeTimer'), !$this->ReadPropertyBoolean('EnableBoostModeTimer'));

        //Party mode timer info
        IPS_SetHidden($this->GetIDForIdent('PartyModeTimer'), !$this->ReadPropertyBoolean('EnablePartyModeTimer'));

        //Door windows state timer info
        IPS_SetHidden($this->GetIDForIdent('DoorWindowStateTimer'), !$this->ReadPropertyBoolean('EnableDoorWindowStateTimer'));

        ########## References and Messages

        //Delete all references
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        //Delete all message registrations
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message == EM_UPDATE) {
                    $this->UnregisterMessage($id, EM_UPDATE);
                }
                if ($message == VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        //Register references and messages
        if (!$this->CheckMaintenanceMode()) {
            $this->SendDebug(__FUNCTION__, 'Referenzen und Nachrichten werden registriert.', 0);
            //Weekly schedule
            $id = $this->ReadPropertyInteger('WeeklySchedule');
            if ($id != 0 && @IPS_ObjectExists($id)) {
                $this->RegisterReference($id);
                $this->RegisterMessage($id, EM_UPDATE);
            }
            //Thermostat temperature
            $id = $this->ReadPropertyInteger('ThermostatTemperature');
            if ($id != 0 && IPS_ObjectExists($id)) {
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
            //Room temperature
            $id = $this->ReadPropertyInteger('RoomTemperature');
            if ($id != 0 && IPS_ObjectExists($id)) {
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
            //Door and window sensors
            foreach (json_decode($this->ReadPropertyString('DoorWindowSensors')) as $sensor) {
                if ($sensor->UseSensor) {
                    $id = $sensor->ID;
                    if ($id != 0 && @IPS_ObjectExists($id)) {
                        $this->RegisterReference($id);
                        $this->RegisterMessage($id, VM_UPDATE);
                    }
                }
            }
            //Battery state
            $id = $this->ReadPropertyInteger('BatteryState');
            if ($id != 0 && IPS_ObjectExists($id)) {
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
            //Chimney monitoring
            $id = $this->ReadPropertyInteger('ChimneyMonitoring');
            if ($id != 0 && IPS_ObjectExists($id)) {
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        ########## Links

        //Weekly schedule
        $targetID = $this->ReadPropertyInteger('WeeklySchedule');
        $linkID = @IPS_GetLinkIDByName('Nächstes Wochenplanereignis', $this->InstanceID);
        if ($targetID != 0 && @IPS_ObjectExists($targetID)) {
            //Check for existing link
            if (!is_int($linkID) && !$linkID) {
                $linkID = IPS_CreateLink();
            }
            IPS_SetParent($linkID, $this->InstanceID);
            IPS_SetPosition($linkID, 20);
            IPS_SetName($linkID, 'Nächstes Wochenplanereignis');
            IPS_SetIcon($linkID, 'Calendar');
            IPS_SetLinkTargetID($linkID, $targetID);
        } else {
            if (is_int($linkID)) {
                IPS_SetHidden($linkID, true);
            }
        }

        ########## Timer

        $this->SetTimerInterval('DeactivateBoostMode', 0);
        $this->SetValue('BoostModeTimer', '-');
        $this->SetTimerInterval('DeactivatePartyMode', 0);
        $this->SetValue('PartyModeTimer', '-');
        $this->SetTimerInterval('ReviewDoorWindowSensors', 0);
        $this->SetValue('DoorWindowStateTimer', '-');

        ########## Misc

        if ($this->CheckMaintenanceMode()) {
            return;
        }

        //Validate configuration
        $this->ValidateConfiguration();

        //Check condition
        $this->CheckActualCondition();
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();

        //Delete profiles
        $profiles = ['AutomaticMode', 'SetPointTemperature', 'BoostMode', 'PartyMode', 'DoorWindowState', 'BatteryState', 'ChimneyState'];
        foreach ($profiles as $profile) {
            $profileName = self::MODULE_PREFIX . '.' . $this->InstanceID . '.' . $profile;
            if (@IPS_VariableProfileExists($profileName)) {
                IPS_DeleteVariableProfile($profileName);
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug('MessageSink', 'Message from SenderID ' . $SenderID . ' with Message ' . $Message . "\r\n Data: " . print_r($Data, true), 0);
        if (!empty($Data)) {
            foreach ($Data as $key => $value) {
                $this->SendDebug(__FUNCTION__, 'Data[' . $key . '] = ' . json_encode($value), 0);
            }
        }
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

            case VM_UPDATE:

                //$Data[0] = actual value
                //$Data[1] = value changed
                //$Data[2] = last value
                //$Data[3] = timestamp actual value
                //$Data[4] = timestamp value changed
                //$Data[5] = timestamp last value

                if ($this->CheckMaintenanceMode()) {
                    return;
                }

                //Thermostat temperature
                if ($SenderID == $this->ReadPropertyInteger('ThermostatTemperature')) {
                    if ($Data[1]) {
                        $this->SendDebug(__FUNCTION__, 'Die Thermostat-Temperatur hat sich auf ' . $Data[0] . '°C geändert.', 0);
                        //$this->UpdateThermostatTemperature();
                        $scriptText = self::MODULE_PREFIX . '_UpdateThermostatTemperature(' . $this->InstanceID . ');';
                        IPS_RunScriptText($scriptText);
                    }
                }
                //Room temperature
                if ($SenderID == $this->ReadPropertyInteger('RoomTemperature')) {
                    if ($Data[1]) {
                        $this->SendDebug(__FUNCTION__, 'Die Raum-Temperatur hat sich auf ' . $Data[0] . '°C geändert.', 0);
                        //$this->UpdateRoomTemperature();
                        $scriptText = self::MODULE_PREFIX . '_UpdateRoomTemperature(' . $this->InstanceID . ');';
                        IPS_RunScriptText($scriptText);
                    }
                }
                //Door and window sensors
                $doorWindowSensors = json_decode($this->ReadPropertyString('DoorWindowSensors'), true);
                if (!empty($doorWindowSensors)) {
                    if (in_array($SenderID, array_column($doorWindowSensors, 'ID'))) {
                        if ($Data[1]) {
                            //$this->CheckDoorWindowSensors();
                            $scriptText = self::MODULE_PREFIX . '_CheckDoorWindowSensors(' . $this->InstanceID . ');';
                            IPS_RunScriptText($scriptText);
                        }
                    }
                }
                //Battery state
                if ($SenderID == $this->ReadPropertyInteger('BatteryState')) {
                    if ($Data[1]) {
                        $this->SendDebug(__FUNCTION__, 'Der Batteriestatus hat sich auf den Wert ' . json_encode($Data[0]) . 'geändert.', 0);
                        //$this->UpdateBatteryState();
                        $scriptText = self::MODULE_PREFIX . '_UpdateBatteryState(' . $this->InstanceID . ');';
                        IPS_RunScriptText($scriptText);
                    }
                }
                //Chimney state
                if ($SenderID == $this->ReadPropertyInteger('ChimneyMonitoring')) {
                    if ($Data[1]) {
                        $this->SendDebug(__FUNCTION__, 'Der Kaminstatus hat sich auf den Wert ' . json_encode($Data[0]) . ' geändert.', 0);
                        //$this->TriggerChimneyMonitoring($Data[0]);
                        $scriptText = self::MODULE_PREFIX . '_TriggerChimneyMonitoring(' . $this->InstanceID . ', ' . json_encode($Data[0]) . ');';
                        IPS_RunScriptText($scriptText);
                    }
                }
                break;

            case EM_UPDATE:

                //$Data[0] = last run
                //$Data[1] = next run

                if ($this->CheckMaintenanceMode()) {
                    return;
                }

                //Weekly schedule
                //$this->TriggerAction(true);
                $scriptText = self::MODULE_PREFIX . '_TriggerAction(' . $this->InstanceID . ', true);';
                IPS_RunScriptText($scriptText);
                break;

        }
    }

    public function CreateCommandControlInstance(): void
    {
        $id = IPS_CreateInstance(self::ABLAUFSTEUERUNG_MODULE_GUID);
        if (is_int($id)) {
            IPS_SetName($id, 'Ablaufsteuerung');
            echo 'Instanz mit der ID ' . $id . ' wurde erfolgreich erstellt!';
        } else {
            echo 'Instanz konnte nicht erstellt werden!';
        }
    }

    #################### Request action

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

    public function ToggleAutomaticMode(bool $State): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->SetValue('AutomaticMode', $State);
        $this->AdjustTemperature();
        //Weekly schedule visibility
        $id = @IPS_GetLinkIDByName('Wochenplan', $this->InstanceID);
        if (is_int($id)) {
            $hide = true;
            if ($this->ReadPropertyBoolean('EnableWeeklySchedule') && $State) {
                $hide = false;
            }
            IPS_SetHidden($id, $hide);
        }
    }

    #################### Private

    private function KernelReady(): void
    {
        $this->ApplyChanges();
    }

    private function ValidateConfiguration(): void
    {
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $state = 102;
        $deviceType = $this->ReadPropertyInteger('DeviceType');
        //Thermostat instance
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
                    //Check channel
                    $config = json_decode(IPS_GetConfiguration($id));
                    $address = strstr($config->Address, ':');
                    switch ($deviceType) {
                        case 1: # HM-CC-RT-DN, Channel 4
                            if ($address != ':4') {
                                $this->LogMessage('Konfiguration: Instanz Heizkörperthermostat Kanal ungültig!', KL_ERROR);
                                $state = 200;
                            }
                            break;

                        case 2: # HmIP-eTRV, Channel 1
                        case 3: # HmIP-eTRV-2, Channel 1
                        case 4: # HmIP-eTRV-E, Channel 1
                            if ($address != ':1') {
                                $this->LogMessage('Konfiguration: Instanz Heizkörperthermostat Kanal ungültig!', KL_ERROR);
                                $state = 200;
                            }
                            break;

                    }
                }
            }
        }
        //Thermostat control mode
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
                        //Check channel
                        $config = json_decode(IPS_GetConfiguration($parent));
                        $address = strstr($config->Address, ':');
                        switch ($deviceType) {
                            case 1: # HM-CC-RT-DN, Channel 4
                                if ($address != ':4') {
                                    $this->LogMessage('Konfiguration: Variable Steuerungsmodus Kanal ungültig!', KL_ERROR);
                                    $state = 200;
                                }
                                break;

                            case 2: # HmIP-eTRV, Channel 1
                            case 3: # HmIP-eTRV-2, Channel 1
                            case 4: # HmIP-eTRV-E, Channel 1
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
                    case 1: # HM-CC-RT-DN
                        if ($ident != 'CONTROL_MODE') {
                            $this->LogMessage('Konfiguration: Variable Steuerungsmodus IDENT ungültig!', KL_ERROR);
                            $state = 200;
                        }
                        break;

                    case 2: # HmIP-eTRV
                    case 3: # HmIP-eTRV-2
                    case 4: # HmIP-eTRV-E
                        if ($ident != 'SET_POINT_MODE') {
                            $this->LogMessage('Konfiguration: Variable Steuerungsmodus IDENT ungültig!', KL_ERROR);
                            $state = 200;
                        }
                        break;
                }
            }
        }
        //Thermostat temperature
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
                        //Check channel
                        $config = json_decode(IPS_GetConfiguration($parent));
                        $address = strstr($config->Address, ':');
                        switch ($deviceType) {
                            case 1: # HM-CC-RT-DN, Channel 4
                                if ($address != ':4') {
                                    $this->LogMessage('Konfiguration: Variable Thermostat-Temperatur Kanal ungültig!', KL_ERROR);
                                    $state = 200;
                                }
                                break;

                            case 2: # HmIP-eTRV, Channel 1
                            case 3: # HmIP-eTRV-2, Channel 1
                            case 4: # HmIP-eTRV-E, Channel 1
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
                    case 1: # HM-CC-RT-DN
                        if ($ident != 'SET_TEMPERATURE') {
                            $this->LogMessage('Konfiguration: Variable Thermostat-Temperatur IDENT ungültig!', KL_ERROR);
                            $state = 200;
                        }
                        break;

                    case 2: # HmIP-eTRV
                    case 3: # HmIP-eTRV-2
                    case 4: # HmIP-eTRV-E
                        if ($ident != 'SET_POINT_TEMPERATURE') {
                            $this->LogMessage('Konfiguration: Variable Thermostat-Temperatur IDENT ungültig!', KL_ERROR);
                            $state = 200;
                        }
                        break;
                }
            }
        }
        //Battery state
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
                        //Check channel
                        $config = json_decode(IPS_GetConfiguration($parent));
                        $address = strstr($config->Address, ':');
                        switch ($deviceType) {
                            case 1: # HM-CC-RT-DN, Channel 0
                            case 2: # HmIP-eTRV, Channel 0
                            case 3: # HmIP-eTRV-2, Channel 0
                            case 4: # HmIP-eTRV-E, Channel 0
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
                    case 1: # HM-CC-RT-DN
                        if ($ident != 'LOWBAT') {
                            $this->LogMessage('Konfiguration: Variable Batteriestatus IDENT ungültig!', KL_ERROR);
                            $state = 200;
                        }
                        break;

                    case 2: # HmIP-eTRV
                    case 3: # HmIP-eTRV-2
                    case 4: # HmIP-eTRV-E
                        if ($ident != 'LOW_BAT') {
                            $this->LogMessage('Konfiguration: Variable Batteriestatus IDENT ungültig!', KL_ERROR);
                            $state = 200;
                        }
                        break;
                }
            }
        }
        //Set state
        $this->SetStatus($state);
    }

    private function CheckActualCondition(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        //Update values
        $this->UpdateThermostatTemperature();
        $this->UpdateRoomTemperature();
        $this->UpdateBatteryState();
        //Check automatic mode
        if (!$this->GetValue('AutomaticMode')) {
            return;
        }
        //Check chimney
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
        //Adjust temperature
        $this->AdjustTemperature();
        //Check door and window sensors
        $this->CheckDoorWindowSensors();
    }

    private function CheckMaintenanceMode(): bool
    {
        $result = false;
        $status = 102;
        if ($this->ReadPropertyBoolean('MaintenanceMode')) {
            $result = true;
            $status = 104;
            $text = 'Abbruch, der Wartungsmodus ist aktiv!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . $text, KL_WARNING);
        }
        $this->SetStatus($status);
        IPS_SetDisabled($this->InstanceID, $result);
        return $result;
    }
}