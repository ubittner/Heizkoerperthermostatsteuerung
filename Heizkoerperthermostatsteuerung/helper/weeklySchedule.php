<?php

// Declare
declare(strict_types=1);

trait HKTS_weeklySchedule
{
    /**
     * Toggles the automatic mode
     *
     * @param bool $State
     * false    = off
     * true     = on
     */
    public function ToggleAutomaticMode(bool $State): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->SetValue('AutomaticMode', $State);
        $this->AdjustTemperature();
        // Weekly schedule visibility
        $id = @IPS_GetLinkIDByName('Wochenplan', $this->InstanceID);
        if ($id !== false) {
            $hide = true;
            if ($this->ReadPropertyBoolean('EnableWeeklySchedule') && $State) {
                $hide = false;
            }
            IPS_SetHidden($id, $hide);
        }
    }

    /**
     * Shows the actual action.
     */
    public function ShowActualAction(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $validate = $this->ValidateEventPlan();
        if (!$validate) {
            echo 'Ein Wochenplan ist nicht vorhanden oder der Wochenplan ist inaktiv!';
            return;
        }
        $actionID = $this->DetermineAction();
        $actionName = '0 = keine Aktion gefunden!';
        $event = IPS_GetEvent($this->ReadPropertyInteger('WeeklySchedule'));
        foreach ($event['ScheduleActions'] as $action) {
            if ($action['ID'] === $actionID) {
                $actionName = $actionID . ' = ' . $action['Name'];
            }
        }
        echo "Aktuelle Aktion:\n\n" . $actionName;
    }

    //#################### Private

    /**
     * Validates the event plan.
     * The event plan must be existing and active.
     *
     * @return bool
     * false    = validation failed
     * true     = validation ok
     */
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

    /**
     * Triggers the action of the weekly schedule an sets the temperature.
     *
     * @param bool $SetTemperature
     * false    = don't set temperature
     * true     = set temperature
     */
    private function TriggerAction(bool $SetTemperature): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if (!$this->ValidateEventPlan()) {
            return;
        }
        // Trigger action only in automatic mode
        if ($this->GetValue('AutomaticMode')) {
            $actionID = $this->DetermineAction();
            switch ($actionID) {
                // No actual action found
                case 0:
                    $this->SendDebug(__FUNCTION__, '0 = Keine Aktion gefunden!', 0);
                    break;

                // Set-back temperature
                case 1:
                    $this->SendDebug(__FUNCTION__, '1 = Absenkmodus', 0);
                    $temperature = $this->ReadPropertyFloat('SetBackTemperature');
                    break;

                // Pre-heating temperature
                case 2:
                    $this->SendDebug(__FUNCTION__, '2 = Vorwärmmodus', 0);
                    $temperature = $this->ReadPropertyFloat('PreHeatingTemperature');
                    break;

                // Heating temperature
                case 3:
                    $this->SendDebug(__FUNCTION__, '3 = Heizmodus', 0);
                    $temperature = $this->ReadPropertyFloat('HeatingTemperature');
                    break;

                // Boost temperature
                case 4:
                    $this->SendDebug(__FUNCTION__, '4 = Boostmodus', 0);
                    $temperature = $this->ReadPropertyFloat('BoostTemperature');
                    break;

            }
            if ($SetTemperature && isset($temperature)) {
                $this->SetValue('SetPointTemperature', $temperature);
                // Check chimney state
                if ($this->GetValue('ChimneyState')) {
                    $this->SendDebug(__FUNCTION__, 'Abbruch, Der Kamin ist an!', 0);
                    return;
                }
                // Only set temperature if all doors and windows are closed
                if (!$this->GetValue('DoorWindowState')) {
                    // Boost mode must be off
                    if (!$this->GetValue('BoostMode')) {
                        $executionDelay = $this->ReadPropertyInteger('ExecutionDelay');
                        // Delay
                        if ($executionDelay > 0) {
                            $min = self::MINIMUM_DELAY_MILLISECONDS;
                            $max = $executionDelay * 1000;
                            $delay = rand($min, $max);
                            IPS_Sleep($delay);
                        }
                        // Set temperature if party mode is disabled
                        if (!$this->GetValue('PartyMode')) {
                            $this->SetThermostatTemperature($temperature);
                        }
                    }
                }
            }
        }
    }

    /**
     * Determines the action from the weekly schedule.
     *
     * @return int
     * Returns the action id.
     */
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