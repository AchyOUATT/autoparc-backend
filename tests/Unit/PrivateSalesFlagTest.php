<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PrivateSalesFlagTest extends TestCase
{
    public static function environmentValues(): array
    {
        return [
            'absent' => [null, false],
            'empty' => ['', false],
            'false' => ['false', false],
            'uppercase false' => ['FALSE', false],
            'Laravel false' => ['(false)', false],
            'zero' => ['0', false],
            'no' => ['no', false],
            'uppercase no' => ['NO', false],
            'off' => ['off', false],
            'uppercase off' => ['OFF', false],
            'non' => ['non', false],
            'unrecognized' => ['peut-etre', false],
            'other number' => ['2', false],
            'true' => ['true', true],
            'uppercase true' => ['TRUE', true],
            'Laravel true' => ['(true)', true],
            'one' => ['1', true],
            'on' => ['on', true],
            'uppercase on' => ['ON', true],
            'yes' => ['yes', true],
            'uppercase yes' => ['YES', true],
        ];
    }

    #[DataProvider('environmentValues')]
    public function test_the_actual_configuration_opens_only_for_an_affirmative_value(?string $value, bool $expected): void
    {
        $key = 'PRIVATE_SALES_ENABLED';
        $hadEnv = array_key_exists($key, $_ENV);
        $hadServer = array_key_exists($key, $_SERVER);
        $previousEnv = $_ENV[$key] ?? null;
        $previousServer = $_SERVER[$key] ?? null;
        $previousNative = getenv($key);

        try {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }

            // Read the real config file so restoring `(bool) env(...)` fails this test.
            $configuration = require dirname(__DIR__, 2).'/config/private_sales.php';
            $this->assertSame($expected, $configuration['enabled']);
        } finally {
            if ($hadEnv) {
                $_ENV[$key] = $previousEnv;
            } else {
                unset($_ENV[$key]);
            }
            if ($hadServer) {
                $_SERVER[$key] = $previousServer;
            } else {
                unset($_SERVER[$key]);
            }
            putenv($previousNative === false ? $key : $key.'='.$previousNative);
        }
    }
}
