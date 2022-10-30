<?php

/**
 * @project       Heizkoerperthermostatsteuerung/Heizkoerperthermostatsteuerung
 * @file          HKTS_WeeklySchedule.php
 * @author        Ulrich Bittner
 * @copyright     2022 Ulrich Bittner
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUnused */

declare(strict_types=1);

trait HKTS_WeeklySchedule
{
    public function ShowActualAction(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $warning = json_decode('"\u26a0\ufe0f"') . "\tFehler\n\n";
        $validate = $this->ValidateEventPlan();
        if (!$validate) {
            echo $warning . "Es ist kein Wochenplan vorhanden \noder der zugewiesene Wochenplan existiert nicht mehr \noder der Wochenplan ist inaktiv!";
            return;
        }
        $actionID = $this->DetermineAction();
        $actionName = $warning . ' 0 = keine Aktion gefunden!';
        $event = IPS_GetEvent($this->ReadPropertyInteger('WeeklySchedule'));
        foreach ($event['ScheduleActions'] as $action) {
            if ($action['ID'] === $actionID) {
                $actionName = json_decode('"\u2705"') . "\tAktuelle Aktion\n\nID " . $actionID . ' = ' . $action['Name'];
            }
        }
        echo $actionName;
    }

    public function TriggerAction(bool $SetTemperature): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if (!$this->ValidateEventPlan()) {
            return;
        }
        //Trigger action only in automatic mode
        if ($this->GetValue('AutomaticMode')) {
            $actionID = $this->DetermineAction();
            switch ($actionID) {
                case 0: # No actual action found
                    $this->SendDebug(__FUNCTION__, '0 = Keine Aktion gefunden!', 0);
                    break;

                case 1: # Set-back temperature
                    $this->SendDebug(__FUNCTION__, '1 = Absenkmodus', 0);
                    $temperature = $this->ReadPropertyFloat('SetBackTemperature');
                    break;

                case 2: # Pre-heating temperature
                    $this->SendDebug(__FUNCTION__, '2 = Vorwärmmodus', 0);
                    $temperature = $this->ReadPropertyFloat('PreHeatingTemperature');
                    break;

                case 3: # Heating temperature
                    $this->SendDebug(__FUNCTION__, '3 = Heizmodus', 0);
                    $temperature = $this->ReadPropertyFloat('HeatingTemperature');
                    break;

                case 4: # Boost temperature
                    $this->SendDebug(__FUNCTION__, '4 = Boostmodus', 0);
                    $temperature = $this->ReadPropertyFloat('BoostTemperature');
                    break;

            }
            if ($SetTemperature && isset($temperature)) {
                $this->SetValue('SetPointTemperature', $temperature);
                //Check chimney state
                if ($this->GetValue('ChimneyState')) {
                    $this->SendDebug(__FUNCTION__, 'Abbruch, Der Kamin ist an!', 0);
                    return;
                }
                //Only set temperature if all doors and windows are closed
                if (!$this->GetValue('DoorWindowState')) {
                    //Boost mode must be off
                    if (!$this->GetValue('BoostMode')) {
                        $executionDelay = $this->ReadPropertyInteger('ExecutionDelay');
                        //Delay
                        if ($executionDelay > 0) {
                            $min = self::MINIMUM_DELAY_MILLISECONDS;
                            $max = $executionDelay * 1000;
                            $delay = rand($min, $max);
                            IPS_Sleep($delay);
                        }
                        //Set temperature if party mode is disabled
                        if (!$this->GetValue('PartyMode')) {
                            $this->SetThermostatTemperature($temperature);
                        }
                    }
                }
            }
        }
    }

    #################### Private

    private function ValidateEventPlan(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $result = false;
        $weeklySchedule = $this->ReadPropertyInteger('WeeklySchedule');
        if ($weeklySchedule != 0 && @IPS_ObjectExists($weeklySchedule)) {
            $event = IPS_GetEvent($weeklySchedule);
            if ($event['EventActive'] == 1) {
                $result = true;
            }
        }
        return $result;
    }

    private function DetermineAction(): int
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $actionID = 0;
        if ($this->ValidateEventPlan()) {
            $event = IPS_GetEvent($this->ReadPropertyInteger('WeeklySchedule'));
            $timestamp = time();
            $searchTime = date('H', $timestamp) * 3600 + date('i', $timestamp) * 60 + date('s', $timestamp);
            $weekDay = date('N', $timestamp);
            foreach ($event['ScheduleGroups'] as $group) {
                if (($group['Days'] & pow(2, $weekDay - 1)) > 0) {
                    $points = $group['Points'];
                    foreach ($points as $point) {
                        $startTime = $point['Start']['Hour'] * 3600 + $point['Start']['Minute'] * 60 + $point['Start']['Second'];
                        if ($startTime <= $searchTime) {
                            $actionID = $point['ActionID'];
                        }
                    }
                }
            }
        }
        return $actionID;
    }
}