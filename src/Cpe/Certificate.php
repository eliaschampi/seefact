<?php

declare(strict_types=1);

namespace See\Cpe;

use DateTimeImmutable;
use See\Input\InvalidInput;
use SensitiveParameter;

/**
 * The issuer's signing credential: one passwordless PEM holding the RSA
 * private key and its X.509 certificate (a .pfx is converted before it gets
 * here).
 *
 * It lives only in this request's memory. Problems SUNAT would reject later
 * — a key that does not match the certificate, an expired certificate — are
 * refused here, before anything is signed.
 */
final readonly class Certificate
{
    private function __construct(
        #[SensitiveParameter] public string $pem,
        public DateTimeImmutable $validTo,
    ) {}

    public static function fromPem(#[SensitiveParameter] string $pem, DateTimeImmutable $now, string $pointer): self
    {
        $key = self::quietly(static fn () => openssl_pkey_get_private($pem));
        $certificate = self::quietly(static fn () => openssl_x509_read($pem));

        if ($key === false || $certificate === false) {
            self::drainOpensslErrors();

            throw InvalidInput::content($pointer, 'certificate_invalid', 'El PEM debe contener la clave privada sin contraseña y su certificado X.509.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw InvalidInput::content($pointer, 'certificate_invalid', 'La clave privada debe ser RSA.');
        }

        if (! openssl_x509_check_private_key($certificate, $key)) {
            throw InvalidInput::content($pointer, 'certificate_key_mismatch', 'La clave privada no corresponde al certificado.');
        }

        $info = openssl_x509_parse($certificate);
        $from = is_array($info) ? ($info['validFrom_time_t'] ?? null) : null;
        $to = is_array($info) ? ($info['validTo_time_t'] ?? null) : null;

        if (! is_int($from) || ! is_int($to)) {
            throw InvalidInput::content($pointer, 'certificate_invalid', 'No se pudo leer la vigencia del certificado.');
        }

        if ($now->getTimestamp() < $from) {
            throw InvalidInput::content($pointer, 'certificate_not_yet_valid', 'El certificado aún no está vigente.');
        }

        if ($now->getTimestamp() > $to) {
            throw InvalidInput::content($pointer, 'certificate_expired', 'El certificado está vencido.');
        }

        return new self($pem, (new DateTimeImmutable)->setTimestamp($to));
    }

    /**
     * OpenSSL reports unreadable input both as false and as an E_WARNING;
     * the false is handled above, the warning would only pollute the log.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private static function quietly(callable $call): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /** A long-lived FPM worker must not carry one request's OpenSSL errors into the next. */
    private static function drainOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // drain
        }
    }
}
