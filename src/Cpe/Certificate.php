<?php

declare(strict_types=1);

namespace See\Cpe;

use DateTimeImmutable;
use LogicException;
use See\Input\InvalidInput;
use SensitiveParameter;

/**
 * The issuer's signing credential: one passwordless PEM holding the RSA
 * private key and its X.509 certificate. A .pfx/.p12 becomes that PEM once,
 * in `fromPkcs12`, and the core keeps the PEM sealed.
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
        public string $subject,
        public string $sha256,
    ) {}

    /**
     * A .pfx/.p12 as a certification authority issues it, opened with its
     * password. Files protected with RC2-40 need OpenSSL's legacy provider
     * (deploy/openssl.cnf); without it the cause goes to the log as a 500,
     * because it is this host's configuration, not the caller's file.
     */
    public static function fromPkcs12(
        #[SensitiveParameter] string $pkcs12,
        #[SensitiveParameter] string $password,
        DateTimeImmutable $now,
        string $filePointer,
        string $passwordPointer,
    ): self {
        $parts = null;
        // By reference: OpenSSL fills $parts, which an arrow function would copy.
        $read = self::quietly(static function () use ($pkcs12, $password, &$parts): bool {
            return openssl_pkcs12_read($pkcs12, $parts, $password);
        });

        if (! $read) {
            // OpenSSL's reason strings are stable; its messages around them are not.
            $errors = self::opensslErrors();

            if (str_contains($errors, 'mac verify failure')) {
                throw InvalidInput::content($passwordPointer, 'pkcs12_password', 'La contraseña del certificado no es correcta.');
            }

            if (str_contains($errors, 'unsupported') && ! self::legacyProviderActive()) {
                throw new LogicException('OpenSSL no tiene el provider legacy: cargue deploy/openssl.cnf con OPENSSL_CONF al iniciar PHP.');
            }

            throw InvalidInput::content($filePointer, 'pkcs12_invalid', 'El archivo no es un certificado .pfx o .p12 válido.');
        }

        $key = is_array($parts) ? ($parts['pkey'] ?? null) : null;
        $certificate = is_array($parts) ? ($parts['cert'] ?? null) : null;

        if (! is_string($key) || ! is_string($certificate)) {
            throw InvalidInput::content($filePointer, 'certificate_invalid', 'El .pfx debe traer la clave privada y su certificado.');
        }

        return self::fromPem($key.$certificate, $now, $filePointer);
    }

    /** RC2-40 is listed only while OpenSSL's legacy provider is loaded. */
    public static function legacyProviderActive(): bool
    {
        return in_array('rc2-40-cbc', openssl_get_cipher_methods(), true);
    }

    public static function fromPem(#[SensitiveParameter] string $pem, DateTimeImmutable $now, string $pointer): self
    {
        $key = self::quietly(static fn () => openssl_pkey_get_private($pem));
        $certificate = self::quietly(static fn () => openssl_x509_read($pem));

        if ($key === false || $certificate === false) {
            self::opensslErrors();

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

        $subject = $info['name'] ?? null;
        $sha256 = openssl_x509_fingerprint($certificate, 'sha256');

        if (! is_string($subject) || $sha256 === false) {
            throw InvalidInput::content($pointer, 'certificate_invalid', 'No se pudo leer el titular del certificado.');
        }

        return new self($pem, (new DateTimeImmutable)->setTimestamp($to), $subject, $sha256);
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

    /**
     * Drains OpenSSL's error queue: a long-lived FPM worker must not carry one
     * request's errors into the next. Returns them for classification.
     */
    private static function opensslErrors(): string
    {
        $errors = [];

        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return implode("\n", $errors);
    }
}
