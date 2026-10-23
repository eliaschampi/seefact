<?php

declare(strict_types=1);

namespace See\Tests\Unit;

use See\Support\RucNumber;
use See\Tests\TestCase;

final class RucNumberTest extends TestCase
{
    public function test_accepts_valid_check_digits(): void
    {
        self::assertTrue(RucNumber::isValid('20000000001'));
        self::assertTrue(RucNumber::isValid('20512333797'));
    }

    public function test_rejects_invalid_check_digits(): void
    {
        self::assertFalse(RucNumber::isValid('20512333798'));
        self::assertFalse(RucNumber::isValid('20000000002'));
        // Greenter's demo RUC: structurally fine, check digit wrong.
        self::assertFalse(RucNumber::isValid('20123456789'));
    }

    public function test_rejects_anything_but_eleven_digits(): void
    {
        foreach (['12345', '2051233379712', '20A1233379*', "20512333797\n"] as $value) {
            self::assertFalse(RucNumber::isValid($value), $value);
        }
    }
}
