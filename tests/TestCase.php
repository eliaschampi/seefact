<?php

declare(strict_types=1);

namespace See\Tests;

use Closure;
use DateTimeImmutable;
use Greenter\Ws\Services\WsClientInterface;
use Greenter\Zip\ZipFly;
use PHPUnit\Framework\TestCase as BaseTestCase;
use See\Cpe\Signer;
use See\Http\Request;
use See\Kernel;
use See\Sunat\SolCredentials;
use See\Sunat\SunatClient;

abstract class TestCase extends BaseTestCase
{
    protected const string TOKEN = 'test-token-0123456789abcdef0123456789';

    protected const string ROOT = __DIR__.'/..';

    /** Inside the fixture certificate's validity (2026-10-22 → 2046-10-17). */
    protected static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2027-01-01T12:00:00-05:00');
    }

    /** @return array<string, mixed> */
    protected static function example(string $name): array
    {
        $json = file_get_contents(self::ROOT.'/docs/examples/'.$name.'.json');
        self::assertIsString($json);
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** The password of tests/Fixtures/test-only-certificate{,-legacy}.pfx. */
    protected const string PFX_PASSWORD = 'clave de prueba';

    /** The fixture certificate as a .pfx: modern (AES) or legacy (RC2-40). */
    protected static function pfx(string $file): string
    {
        $pfx = file_get_contents(__DIR__.'/Fixtures/'.$file);
        self::assertIsString($pfx);

        return $pfx;
    }

    protected static function certificatePem(): string
    {
        $pem = file_get_contents(__DIR__.'/Fixtures/test-only-certificate.pem');
        self::assertIsString($pem);

        return $pem;
    }

    /**
     * A SUNAT client whose SOAP calls are answered by $answer.
     *
     * @param  Closure(string, array<mixed>): mixed  $answer  operation, arguments → response (or throw)
     * @param  list<array{wsdl: string, endpoint: string, username: string, operation: string, arguments: array<mixed>}>  $calls
     */
    protected static function sunat(Closure $answer, array &$calls = []): SunatClient
    {
        return new SunatClient(static function (string $wsdl, string $endpoint, SolCredentials $sol) use ($answer, &$calls): WsClientInterface {
            return new class($answer, $wsdl, $endpoint, $sol->username(), $calls) implements WsClientInterface
            {
                /** @param list<array<string, mixed>> $calls */
                public function __construct(
                    private readonly Closure $answer,
                    private readonly string $wsdl,
                    private readonly string $endpoint,
                    private readonly string $username,
                    private array &$calls,
                ) {}

                public function call($function, $arguments)
                {
                    $this->calls[] = [
                        'wsdl' => $this->wsdl,
                        'endpoint' => $this->endpoint,
                        'username' => $this->username,
                        'operation' => $function,
                        'arguments' => $arguments,
                    ];

                    return ($this->answer)($function, $arguments);
                }
            };
        });
    }

    /** @param list<string> $logs */
    protected static function kernel(?SunatClient $sunat = null, array &$logs = [], ?string $token = self::TOKEN): Kernel
    {
        return new Kernel(
            $token,
            Signer::create(null),
            $sunat ?? self::sunat(static fn (): never => throw new \LogicException('SUNAT no debía ser llamado.')),
            static fn (): DateTimeImmutable => self::now(),
            static function (string $line) use (&$logs): void {
                $logs[] = $line;
            },
        );
    }

    /**
     * @param  array<string, mixed>|string  $body
     * @param  array<string, string>  $headers
     */
    protected static function post(string $path, array|string $body, array $headers = []): Request
    {
        return new Request('POST', $path, [
            'authorization' => 'Bearer '.self::TOKEN,
            'content-type' => 'application/json',
            ...array_change_key_case($headers),
        ], is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    protected static function signedDocument(string $example): array
    {
        $response = self::kernel()->handle(self::post('/v1/documents/sign', [
            'certificate' => self::certificatePem(),
            'document' => self::example($example),
        ]));
        self::assertSame(200, $response->status, $response->json());

        return $response->body;
    }

    /** A CDR as SUNAT ships it: a zip holding R-{filename}.xml. */
    protected static function cdrZip(string $code, string $description, array $notes = [], string $reference = 'F001-1'): string
    {
        $noteXml = implode('', array_map(static fn (string $note): string => '<cbc:Note>'.htmlspecialchars($note, ENT_XML1).'</cbc:Note>', $notes));
        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <ar:ApplicationResponse xmlns:ar="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
            <cbc:ID>1745000000000</cbc:ID>{$noteXml}
            <cac:DocumentResponse><cac:Response><cbc:ReferenceID>{$reference}</cbc:ReferenceID><cbc:ResponseCode>{$code}</cbc:ResponseCode><cbc:Description>{$description}</cbc:Description></cac:Response></cac:DocumentResponse>
            </ar:ApplicationResponse>
            XML;

        return (string) (new ZipFly)->compress('R-20000000001-01-'.$reference.'.xml', $xml);
    }
}
