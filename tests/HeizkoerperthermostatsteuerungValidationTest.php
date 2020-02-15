<?php

declare(strict_types=1);
include_once __DIR__ . '/stubs/Validator.php';
class HeizkoerperthermostatsteuerungValidationTest extends TestCaseSymconValidation
{
    public function testValidateHeizkoerperthermostatsteuerung(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }
    public function testValidateHeizkoerperthermostatsteuerungModule(): void
    {
        $this->validateModule(__DIR__ . '/../Heizkoerperthermostatsteuerung');
    }
}