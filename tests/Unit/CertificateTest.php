<?php

declare(strict_types=1);

namespace See\Tests\Unit;

use DateTimeImmutable;
use See\Cpe\Certificate;
use See\Input\InvalidInput;
use See\Tests\TestCase;

final class CertificateTest extends TestCase
{
    public function test_accepts_a_matching_rsa_key_and_certificate(): void
    {
        $certificate = Certificate::fromPem(self::certificatePem(), self::now(), '/certificate');

        self::assertSame('2046-10-17', $certificate->validTo->format('Y-m-d'));
    }

    public function test_rejects_an_expired_certificate(): void
    {
        self::assertSame('certificate_expired', self::errorCode(self::certificatePem(), new DateTimeImmutable('2047-01-01')));
    }

    public function test_rejects_a_certificate_before_its_validity(): void
    {
        self::assertSame('certificate_not_yet_valid', self::errorCode(self::certificatePem(), new DateTimeImmutable('2020-01-01')));
    }

    public function test_rejects_a_key_that_does_not_sign_for_the_certificate(): void
    {
        $otherKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($otherKey);
        self::assertTrue(openssl_pkey_export($otherKey, $otherKeyPem));
        self::assertIsString($otherKeyPem);
        $certificateOnly = substr(self::certificatePem(), (int) strpos(self::certificatePem(), '-----BEGIN CERTIFICATE-----'));

        self::assertSame('certificate_key_mismatch', self::errorCode($otherKeyPem.$certificateOnly, self::now()));
    }

    public function test_rejects_pem_without_both_parts_or_with_a_password(): void
    {
        $keyOnly = substr(self::certificatePem(), 0, (int) strpos(self::certificatePem(), '-----BEGIN CERTIFICATE-----'));
        $key = openssl_pkey_get_private(self::certificatePem());
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $encryptedKey, 'secret'));
        self::assertIsString($encryptedKey);
        $certificateOnly = substr(self::certificatePem(), strlen($keyOnly));

        self::assertSame('certificate_invalid', self::errorCode($keyOnly, self::now()));
        self::assertSame('certificate_invalid', self::errorCode($certificateOnly, self::now()));
        self::assertSame('certificate_invalid', self::errorCode($encryptedKey.$certificateOnly, self::now()));
        self::assertSame('certificate_invalid', self::errorCode('not a pem', self::now()));
    }

    private static function errorCode(string $pem, DateTimeImmutable $now): string
    {
        try {
            Certificate::fromPem($pem, $now, '/certificate');
        } catch (InvalidInput $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame('/certificate', $exception->pointer);

            return $exception->errorCode;
        }

        self::fail('Se esperaba un certificado rechazado.');
    }
}
