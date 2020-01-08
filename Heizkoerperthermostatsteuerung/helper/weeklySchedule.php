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
        $this->SetValue('AutomaticMode', $State);
        $this->AdjustTemperature();
    }

    /**
     * Shows the actual action.
     */
    public function ShowActualAction(): void
    {
        $validate = $this->ValidateEventPlan();
        if (!$validate) {
            echo 'Ein Wochenplan ist nicht vorhanden oder der Wochenplan ist inaktiv!';
            return;
        }
        $actionID = $this->GetActualAction();
        $actionName = '0 = keine Aktion gefunden!';
        $event = IPS_GetEvent($this->ReadPropertyInteger('WeeklySchedule'));
        foreach ($event['ScheduleActions'] as $action) {
            if ($action['ID'] === $actionID) {
                $actionName = $actionID . ' = ' . $action['Name'];
            }
        }
        echo "Aktuelle Aktion:\n\n" . $actionName;
    }

    /**
     * Sets the temperature according to the actual action of the weekly schedule.
     */
    public function SetActualAction(): void
    {
        // Check event plan
        if (!$this->ValidateEventPlan()) {
            return;
        }
        // Set action only in automatic mode
        if ($this->GetValue('AutomaticMode')) {
            $actionID = $this->GetActualAction();
            switch ($actionID) {
                // No actual action found
                case 0:
                    $this->SendDebug(__FUNCTION__, '0 = Keine Aktion gefunden!', 0);
                    break;

                // Set-back temperature
                case 1:
                    $this->SendDebug(__FUNCTION__, '1 = Absenkung durchführen', 0);
                    $temperature = $this->GetPropertyTemperature('SetBackTemperature');
                    break;

                // Pre-heating temperature
                case 2:
                    $this->SendDebug(__FUNCTION__, '2 = Vorwärmen', 0);
                    $temperature = $this->GetPropertyTemperature('PreHeatingTemperature');
                    break;

                // Heating temperature
                case 3:
                    $this->SendDebug(__FUNCTION__, '3 = Heizen', 0);
                    $temperature = $this->GetPropertyTemperature('HeatingTemperature');
                    break;

            }
            if (isset($temperature)) {
                $this->SetValue('SetPointTemperature', $temperature);
                // Only set temperature if all doors and windows are closed
                if (!$this->GetValue('DoorWindowState')) {
                    // Boost mode must be off
                    if (!$this->GetValue('BoostMode')) {
                        // Check delay
                        $executionDelay = $this->ReadPropertyInteger('ExecutionDelay');
                        if ($executionDelay > 0) {
                            // Delay
                            $min = self::MINIMUM_DELAY_MILLISECONDS;
                            $max = $executionDelay * 1000;
                            $delay = rand($min, $max);
                            IPS_Sleep($delay);
                        }
                        $this->SetThermostatTemperature($temperature);
                    }
                }
            }
        }
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
     * Gets the actual action from the weekly schedule.
     *
     * @return int
     * Returns the action id.
     */
    private function GetActualAction(): int
    {
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