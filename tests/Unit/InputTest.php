<?php

declare(strict_types=1);

namespace See\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use See\Input\Input;
use See\Input\InvalidInput;
use See\Tests\TestCase;

final class InputTest extends TestCase
{
    public function test_rejects_bodies_that_are_not_json_objects(): void
    {
        foreach (['', 'null', '[1,2]', '"x"', '{"a":'] as $body) {
            try {
                Input::fromJson($body);
                self::fail("Aceptó {$body}");
            } catch (InvalidInput $exception) {
                self::assertSame('malformed_json', $exception->errorCode);
                self::assertSame(400, $exception->status);
            }
        }
    }

    public function test_unknown_keys_are_reported_with_their_pointer(): void
    {
        $input = Input::fromJson('{"document":{"lines":[{"igv":"1.00","igv_procent":"18"}]}}');
        $line = $input->object('document')->objects('lines', 1, 5)[0];

        $error = self::capture(fn () => $line->only('igv'));

        self::assertSame('/document/lines/0/igv_procent', $error->pointer);
    }

    public function test_money_must_travel_as_a_decimal_string(): void
    {
        $input = Input::fromJson('{"a":10.5,"b":"10.5","c":"1e3","d":"-1","e":"007","f":"1.234"}');

        self::assertSame('10.5', (string) $input->decimal('b', 2));

        foreach (['a', 'c', 'd', 'e', 'f'] as $key) {
            self::assertSame('/'.$key, self::capture(fn () => $input->decimal($key, 2))->pointer);
        }
    }

    public function test_decimals_beyond_double_precision_are_refused(): void
    {
        $input = Input::fromJson('{"ok":"12345.1234567891","too_long":"123456.1234567891"}');

        self::assertSame('12345.1234567891', (string) $input->decimal('ok', 10));
        self::assertStringContainsString('15 dígitos', self::capture(fn () => $input->decimal('too_long', 10))->getMessage());
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeText(): iterable
    {
        yield 'cdata terminator' => ['abc]]><cbc:Note>x</cbc:Note>'];
        yield 'control character' => ["abc\x07"];
        yield 'blank' => ['   '];
        yield 'line break in single-line field' => ["dos\nlíneas"];
    }

    #[DataProvider('unsafeText')]
    public function test_text_that_could_corrupt_the_xml_is_refused(string $text): void
    {
        $input = Input::fromJson(json_encode(['name' => $text], JSON_THROW_ON_ERROR));

        self::capture(fn () => $input->text('name', 100));
        $this->addToAssertionCount(1);
    }

    public function test_multiline_fields_accept_line_breaks_only(): void
    {
        $input = Input::fromJson(json_encode(['ok' => "Café\nLote 1", 'tab' => "Café\tLote"], JSON_THROW_ON_ERROR));

        self::assertSame("Café\nLote 1", $input->text('ok', 100, multiline: true));
        self::capture(fn () => $input->text('tab', 100, multiline: true));
    }

    public function test_dates_are_strict_and_expressed_in_lima(): void
    {
        $input = Input::fromJson('{"at":"2026-10-22T23:30:00Z","utc":"2026-10-23T04:30:00+00:00","day":"2026-02-30","date":"2026-10-22"}');

        self::capture(fn () => $input->dateTime('at'));
        self::assertSame('2026-10-22 23:30:00 America/Lima', $input->dateTime('utc')->format('Y-m-d H:i:s e'));
        self::capture(fn () => $input->date('day'));
        self::assertSame('2026-10-22 00:00 America/Lima', $input->date('date')->format('Y-m-d H:i e'));
    }

    public function test_integers_are_not_coerced_from_strings(): void
    {
        $input = Input::fromJson('{"n":"12","m":12}');

        self::capture(fn () => $input->integer('n', 1, 99));
        self::assertSame(12, $input->integer('m', 1, 99));
    }

    private static function capture(callable $callback): InvalidInput
    {
        try {
            $callback();
        } catch (InvalidInput $exception) {
            return $exception;
        }

        self::fail('Se esperaba InvalidInput.');
    }
}
