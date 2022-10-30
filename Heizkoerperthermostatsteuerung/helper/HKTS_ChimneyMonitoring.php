<?php

/**
 * @project       Heizkoerperthermostatsteuerung/Heizkoerperthermostatsteuerung
 * @file          HKTS_ChimneyMonitoring.php
 * @author        Ulrich Bittner
 * @copyright     2022 Ulrich Bittner
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

trait HKTS_ChimneyMonitoring
{
    public function TriggerChimneyMonitoring(bool $State): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird mit dem Parameter $State = ' . json_encode($State) . ' ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $this->SetValue('ChimneyState', $State);
        //Check automatic mode
        if (!$this->GetValue('AutomaticMode')) {
            //Abort
            $this->SendDebug(__FUNCTION__, 'Abbruch, Die Automatik ist ausgeschaltet!', 0);
            return;
        }
        //Chimney is off
        if (!$State) {
            $this->SendDebug(__FUNCTION__, 'Der Kamin ist aus.', 0);
            //Set temperature
            $temperature = floatval($this->GetValue('SetPointTemperature'));
            $this->SendDebug(__FUNCTION__, 'Die letzte Solltemperatur von ' . $temperature . '°C wird eingestellt.', 0);
            $this->SetThermostatTemperature($temperature);
        }
        //Chimney is on
        if ($State) {
            $this->SendDebug(__FUNCTION__, 'Der Kamin ist an.', 0);
            //Deactivate boost mode
            $this->SetValue('BoostMode', false);
            $this->SetValue('BoostModeTimer', '-');
            //Deactivate party mode
            $this->SetValue('PartyMode', false);
            $this->SetValue('PartyModeTimer', '-');
            //Set temperature
            $temperature = $this->ReadPropertyFloat('ChimneySetBackTemperature');
            $this->SendDebug(__FUNCTION__, 'Die Absenktemperatur von ' . $temperature . '°C wird eingestellt.', 0);
            $this->SetThermostatTemperature($temperature);
        }
    }
}