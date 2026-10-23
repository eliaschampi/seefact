<?php

declare(strict_types=1);

namespace See\Input;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;

/**
 * Strict reader over one decoded JSON object.
 *
 * Every accessor reports the offending field as a JSON Pointer, rejects the
 * wrong JSON type instead of coercing it, and never accepts a JSON number
 * where money is expected: amounts travel as decimal strings, so no float is
 * ever the source of a fiscal value.
 */
final class Input
{
    public const string LIMA = 'America/Lima';

    /** Digits a decimal may carry and still survive Greenter's float models exactly. */
    private const int FLOAT_SAFE_DIGITS = 15;

    /** @param array<array-key, mixed> $data */
    private function __construct(
        private readonly array $data,
        private readonly string $pointer,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw InvalidInput::shape('', 'El cuerpo no es JSON válido.', 'malformed_json');
        }

        if (! self::isObject($data)) {
            throw InvalidInput::shape('', 'El cuerpo debe ser un objeto JSON.', 'malformed_json');
        }

        return new self($data, '');
    }

    public function pointer(?string $key = null): string
    {
        if ($key === null) {
            return $this->pointer;
        }

        return $this->pointer.'/'.str_replace(['~', '/'], ['~0', '~1'], $key);
    }

    /** Fails on any key outside the contract, so a typo never silently drops data. */
    public function only(string ...$keys): self
    {
        foreach (array_keys($this->data) as $key) {
            if (! in_array((string) $key, $keys, true)) {
                throw InvalidInput::shape($this->pointer((string) $key), 'Campo no admitido por el contrato.');
            }
        }

        return $this;
    }

    public function has(string $key): bool
    {
        return ($this->data[$key] ?? null) !== null;
    }

    public function object(string $key): self
    {
        $value = $this->required($key);

        if (! self::isObject($value)) {
            throw InvalidInput::shape($this->pointer($key), 'Se esperaba un objeto.');
        }

        return new self($value, $this->pointer($key));
    }

    public function optionalObject(string $key): ?self
    {
        return $this->has($key) ? $this->object($key) : null;
    }

    /** @return list<self> */
    public function objects(string $key, int $min, int $max): array
    {
        $value = $this->required($key);
        $pointer = $this->pointer($key);

        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidInput::shape($pointer, 'Se esperaba una lista.');
        }

        if (count($value) < $min || count($value) > $max) {
            throw InvalidInput::shape($pointer, "Se esperaban entre {$min} y {$max} elementos.");
        }

        $items = [];

        foreach ($value as $index => $item) {
            if (! self::isObject($item)) {
                throw InvalidInput::shape($pointer.'/'.$index, 'Se esperaba un objeto.');
            }

            $items[] = new self($item, $pointer.'/'.$index);
        }

        return $items;
    }

    /**
     * A machine value (code, identifier, series) matching the whole regex.
     * Anchors are added here so no caller can forget them.
     */
    public function code(string $key, string $regex, string $message = 'Formato inválido.'): string
    {
        $value = $this->string($key);

        if (preg_match('/^(?:'.$regex.')$/D', $value) !== 1) {
            throw InvalidInput::shape($this->pointer($key), $message);
        }

        return $value;
    }

    public function optionalCode(string $key, string $regex, string $message = 'Formato inválido.'): ?string
    {
        return $this->has($key) ? $this->code($key, $regex, $message) : null;
    }

    /** @param list<string> $allowed */
    public function oneOf(string $key, array $allowed): string
    {
        $value = $this->string($key);

        if (! in_array($value, $allowed, true)) {
            throw InvalidInput::shape($this->pointer($key), 'Valor no admitido. Se espera: '.implode(', ', $allowed).'.');
        }

        return $value;
    }

    /**
     * Human text that ends up inside the signed XML.
     *
     * Control characters are refused (line breaks only where the field
     * allows them), and so is "]]>": Greenter wraps names and descriptions in
     * CDATA, and that sequence would let text inject markup into a CPE.
     */
    public function text(string $key, int $maxLength, bool $multiline = false): string
    {
        $value = $this->string($key);
        $pointer = $this->pointer($key);

        if (trim($value) === '') {
            throw InvalidInput::shape($pointer, 'No puede estar vacío.');
        }

        if (mb_strlen($value) > $maxLength) {
            throw InvalidInput::shape($pointer, "Supera {$maxLength} caracteres.");
        }

        $controls = $multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';

        if (preg_match($controls, $value) === 1) {
            throw InvalidInput::shape($pointer, 'Contiene caracteres de control.');
        }

        if (str_contains($value, ']]>')) {
            throw InvalidInput::shape($pointer, 'Contiene la secuencia reservada "]]>".');
        }

        return $value;
    }

    public function optionalText(string $key, int $maxLength): ?string
    {
        return $this->has($key) ? $this->text($key, $maxLength) : null;
    }

    /** Opaque secret material (PEM, password). Never echoed back in errors. */
    public function secret(string $key, int $maxBytes): string
    {
        $value = $this->string($key);

        if ($value === '' || strlen($value) > $maxBytes) {
            throw InvalidInput::shape($this->pointer($key), "Debe tener entre 1 y {$maxBytes} bytes.");
        }

        return $value;
    }

    /**
     * A non-negative decimal written as a JSON string.
     *
     * The digit budget keeps every accepted value exactly representable as a
     * double, which is how Greenter's models hold amounts before printing.
     */
    public function decimal(string $key, int $scale, int $integerDigits = 12): BigDecimal
    {
        $value = $this->string($key);
        $pointer = $this->pointer($key);
        $pattern = sprintf('/^(0|[1-9]\d{0,%d})(\.\d{1,%d})?$/D', $integerDigits - 1, $scale);

        if (preg_match($pattern, $value) !== 1) {
            throw InvalidInput::shape($pointer, "Se esperaba un decimal no negativo escrito como texto, con hasta {$scale} decimales.");
        }

        if (strlen(ltrim(str_replace('.', '', $value), '0')) > self::FLOAT_SAFE_DIGITS) {
            throw InvalidInput::shape($pointer, 'Supera '.self::FLOAT_SAFE_DIGITS.' dígitos significativos.');
        }

        return BigDecimal::of($value);
    }

    public function integer(string $key, int $min, int $max): int
    {
        $value = $this->required($key);

        if (! is_int($value) || $value < $min || $value > $max) {
            throw InvalidInput::shape($this->pointer($key), "Se esperaba un entero entre {$min} y {$max}.");
        }

        return $value;
    }

    /** A calendar date (YYYY-MM-DD), as midnight in Lima. */
    public function date(string $key): DateTimeImmutable
    {
        $value = $this->string($key);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::LIMA));

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw InvalidInput::shape($this->pointer($key), 'Se esperaba una fecha YYYY-MM-DD.');
        }

        return $date;
    }

    public function optionalDate(string $key): ?DateTimeImmutable
    {
        return $this->has($key) ? $this->date($key) : null;
    }

    /** An RFC 3339 instant with an explicit offset, expressed in Lima time. */
    public function dateTime(string $key): DateTimeImmutable
    {
        $value = $this->string($key);
        $instant = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339, $value);

        if ($instant === false || $instant->format(DateTimeInterface::RFC3339) !== $value) {
            throw InvalidInput::shape($this->pointer($key), 'Se esperaba fecha y hora RFC 3339 con zona, p. ej. 2026-10-22T10:15:00-05:00.');
        }

        return $instant->setTimezone(new DateTimeZone(self::LIMA));
    }

    /** Binary content (a signed XML) carried as strict base64. */
    public function base64(string $key, int $maxBytes): string
    {
        $bytes = base64_decode($this->string($key), true);

        if ($bytes === false || $bytes === '' || strlen($bytes) > $maxBytes) {
            throw InvalidInput::shape($this->pointer($key), "Se esperaba base64 válido de hasta {$maxBytes} bytes.");
        }

        return $bytes;
    }

    private function required(string $key): mixed
    {
        if (! $this->has($key)) {
            throw InvalidInput::shape($this->pointer($key), 'Campo requerido.');
        }

        return $this->data[$key];
    }

    private function string(string $key): string
    {
        $value = $this->required($key);

        if (! is_string($value)) {
            throw InvalidInput::shape($this->pointer($key), 'Se esperaba texto.');
        }

        return $value;
    }

    /** @phpstan-assert-if-true array<array-key, mixed> $value */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }
}
