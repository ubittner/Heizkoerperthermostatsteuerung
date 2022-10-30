<?php

/**
 * @project       Heizkoerperthermostatsteuerung/Heizkoerperthermostatsteuerung
 * @file          HKTS_BackupRestore.php
 * @author        Ulrich Bittner
 * @copyright     2022 Ulrich Bittner
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

/** @noinspection PhpUnused */

declare(strict_types=1);

trait HKTS_BackupRestore
{
    public function CreateBackup(int $BackupCategory): void
    {
        if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] == 102) {
            $name = 'Konfiguration (' . IPS_GetName($this->InstanceID) . ' #' . $this->InstanceID . ') ' . date('d.m.Y H:i:s');
            $config = json_decode(IPS_GetConfiguration($this->InstanceID), true);
            $config['DoorWindowSensors'] = json_decode($config['DoorWindowSensors'], true);
            $json_string = json_encode($config, JSON_HEX_APOS | JSON_PRETTY_PRINT);
            $content = "<?php\n// Backup " . date('d.m.Y, H:i:s') . "\n// ID " . $this->InstanceID . "\n$" . "config = '" . $json_string . "';";
            $backupScript = IPS_CreateScript(0);
            IPS_SetParent($backupScript, $BackupCategory);
            IPS_SetName($backupScript, $name);
            IPS_SetHidden($backupScript, true);
            IPS_SetScriptContent($backupScript, $content);
            echo 'Die Konfiguration wurde erfolgreich gesichert!';
        }
    }

    public function RestoreConfiguration(int $ConfigurationScript): void
    {
        if ($ConfigurationScript != 0 && IPS_ObjectExists($ConfigurationScript)) {
            $object = IPS_GetObject($ConfigurationScript);
            if ($object['ObjectType'] == 3) {
                $content = IPS_GetScriptContent($ConfigurationScript);
                preg_match_all('/\'([^;]+)\'/', $content, $matches);
                $config = json_decode($matches[1][0], true);
                $config['DoorWindowSensors'] = json_encode($config['DoorWindowSensors']);
                IPS_SetConfiguration($this->InstanceID, json_encode($config));
                if (IPS_HasChanges($this->InstanceID)) {
                    IPS_ApplyChanges($this->InstanceID);
                }
            }
            echo 'Die Konfiguration wurde erfolgreich wiederhergestellt!';
        }
    }
}
