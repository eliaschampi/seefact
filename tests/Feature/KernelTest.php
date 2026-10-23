<?php

declare(strict_types=1);

namespace See\Tests\Feature;

use DateTimeImmutable;
use Greenter\Zip\ZipFly;
use RuntimeException;
use See\Cpe\Signer;
use See\Http\Request;
use See\Kernel;
use See\Tests\TestCase;

final class KernelTest extends TestCase
{
    private const array SOL = ['ruc' => '20000000001', 'user' => 'MODDATOS', 'password' => 'S3cr3t<&>'];

    public function test_health_needs_no_token_but_reports_missing_configuration(): void
    {
        $health = new Request('GET', '/v1/health', [], '');

        self::assertSame(['status' => 'ok', 'contract' => 'v1'], self::kernel()->handle($health)->body);
        self::assertSame(503, self::kernel(token: null)->handle($health)->status);
    }

    public function test_routes_methods_auth_and_media_type(): void
    {
        $kernel = self::kernel();

        self::assertSame(404, $kernel->handle(self::post('/v1/nope', []))->status);
        $wrongMethod = $kernel->handle(new Request('GET', '/v1/documents/sign', [], ''));
        self::assertSame(405, $wrongMethod->status);
        self::assertSame('POST', $wrongMethod->headers['Allow']);
        self::assertSame(401, $kernel->handle(self::post('/v1/documents/sign', [], ['authorization' => 'Bearer wrong']))->status);
        self::assertSame(401, $kernel->handle(new Request('POST', '/v1/documents/sign', ['content-type' => 'application/json'], '{}'))->status);
        self::assertSame(415, $kernel->handle(self::post('/v1/documents/sign', [], ['content-type' => 'text/plain']))->status);
    }

    public function test_errors_share_one_envelope_with_a_json_pointer(): void
    {
        $malformed = self::kernel()->handle(self::post('/v1/documents/sign', '{"certificate":'));
        $invalid = self::kernel()->handle(self::post('/v1/documents/sign', ['certificate' => 'x', 'document' => ['type' => '99']]));

        self::assertSame(400, $malformed->status);
        self::assertSame('malformed_json', self::errorOf($malformed->body)['code']);
        self::assertSame(400, $invalid->status);
        self::assertSame(['code' => 'invalid_input', 'message' => 'Valor no admitido. Se espera: 01, 03, 07.', 'pointer' => '/document/type'], self::errorOf($invalid->body));
    }

    public function test_send_derives_the_name_from_the_xml_and_sends_those_exact_bytes(): void
    {
        $signed = self::signedDocument('document-01-cash');
        $calls = [];
        $sunat = self::sunat(static fn (): object => (object) ['applicationResponse' => self::cdrZip('0', 'La Factura numero F001-1, ha sido aceptada')], $calls);

        $response = self::kernel($sunat)->handle(self::post('/v1/documents/send', [
            'environment' => 'beta',
            'sol' => self::SOL,
            'xml_base64' => $signed['xml_base64'],
        ]));

        self::assertSame(200, $response->status, $response->json());
        self::assertSame('cdr', $response->body['outcome']);
        self::assertSame('20000000001-01-F001-1', $response->body['filename']);
        self::assertSame($signed['xml_sha256'], $response->body['xml_sha256']);

        $parameters = $calls[0]['arguments']['parameters'];
        self::assertIsArray($parameters);
        self::assertIsString($parameters['contentFile']);
        $files = (new ZipFly)->decompress($parameters['contentFile']);
        self::assertIsString($signed['xml_base64']);
        self::assertSame(base64_decode($signed['xml_base64'], true), $files[0]['content'] ?? null);
    }

    public function test_send_refuses_xml_that_is_unsigned_altered_or_of_another_issuer(): void
    {
        $signed = self::signedDocument('document-01-cash');
        self::assertIsString($signed['xml_base64']);
        $xml = (string) base64_decode($signed['xml_base64'], true);
        $unsigned = (string) preg_replace('#<ds:Signature.*</ds:Signature>#s', '', $xml);
        $altered = str_replace('<cbc:PayableAmount currencyID="PEN">204.80<', '<cbc:PayableAmount currencyID="PEN">104.80<', $xml);
        self::assertNotSame($xml, $altered);

        self::assertSame('xml_not_signed', self::sendError(base64_encode($unsigned)));
        self::assertSame('xml_signature_invalid', self::sendError(base64_encode($altered)));
        self::assertSame('xml_invalid', self::sendError(base64_encode('<Invoice')));
        self::assertSame('sol_ruc_mismatch', self::sendError((string) $signed['xml_base64'], ['ruc' => '20512333797']));
    }

    public function test_documents_and_summaries_are_sent_through_their_own_routes(): void
    {
        $invoice = self::signedDocument('document-01-cash');
        $response = self::kernel()->handle(self::post('/v1/summaries/send', [
            'environment' => 'beta',
            'sol' => self::SOL,
            'xml_base64' => $invoice['xml_base64'],
        ]));

        self::assertSame(422, $response->status);
        self::assertSame('xml_unsupported', self::errorOf($response->body)['code']);
    }

    public function test_daily_summary_round_trip_returns_the_ticket(): void
    {
        $signed = self::kernel()->handle(self::post('/v1/summaries/sign', [
            'certificate' => self::certificatePem(),
            'summary' => self::example('summary-rc'),
        ]))->body;
        $sunat = self::sunat(static fn (): object => (object) ['ticket' => '1745000000123']);

        $response = self::kernel($sunat)->handle(self::post('/v1/summaries/send', [
            'environment' => 'beta',
            'sol' => self::SOL,
            'xml_base64' => $signed['xml_base64'],
        ]));

        self::assertSame(['outcome' => 'ticket', 'ticket' => '1745000000123', 'filename' => '20000000001-RC-20261022-1', 'xml_sha256' => $signed['xml_sha256']], $response->body);
    }

    public function test_ticket_status_and_cdr_consult(): void
    {
        $sunat = self::sunat(static fn (): object => (object) ['status' => (object) ['statusCode' => '98']]);
        $ticket = self::kernel($sunat)->handle(self::post('/v1/tickets/status', ['environment' => 'beta', 'sol' => self::SOL, 'ticket' => '1745000000123']));
        $consultInBeta = self::kernel()->handle(self::post('/v1/cdr/status', [
            'environment' => 'beta',
            'sol' => self::SOL,
            'document' => ['ruc' => '20000000001', 'type' => '01', 'series' => 'F001', 'number' => 1],
        ]));

        self::assertSame('98', $ticket->body['status_code']);
        self::assertSame(422, $consultInBeta->status);
        self::assertSame('consult_not_available', self::errorOf($consultInBeta->body)['code']);
    }

    public function test_logs_carry_correlation_and_outcome_but_never_secrets_or_documents(): void
    {
        $logs = [];
        $signed = self::signedDocument('document-01-cash');
        $sunat = self::sunat(static fn (): object => (object) ['applicationResponse' => self::cdrZip('0', 'Aceptada')]);
        $kernel = self::kernel($sunat, $logs);

        $kernel->handle(self::post('/v1/documents/sign', ['certificate' => self::certificatePem(), 'document' => self::example('document-01-cash')], ['x-request-id' => 'req-123']));
        $sent = $kernel->handle(self::post('/v1/documents/send', ['environment' => 'beta', 'sol' => self::SOL, 'xml_base64' => $signed['xml_base64']]));

        self::assertCount(2, $logs);
        self::assertSame('req-123', json_decode($logs[0], true, 4, JSON_THROW_ON_ERROR)['request_id']);
        self::assertSame(['outcome' => 'cdr', 'sunat_code' => '0'], array_intersect_key(json_decode($logs[1], true, 4, JSON_THROW_ON_ERROR), ['outcome' => 1, 'sunat_code' => 1]));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $sent->headers['X-Request-Id']);

        $everything = implode("\n", $logs);
        self::assertStringNotContainsString('S3cr3t', $everything);
        self::assertStringNotContainsString('PRIVATE KEY', $everything);
        self::assertStringNotContainsString('CLIENTE EMPRESA', $everything);
    }

    public function test_unexpected_failures_are_a_generic_500_with_a_logged_cause(): void
    {
        $logs = [];
        $kernel = new Kernel(
            self::TOKEN,
            Signer::create(null),
            self::sunat(static fn (): never => throw new RuntimeException('no')),
            static fn (): DateTimeImmutable => throw new RuntimeException('reloj roto'),
            static function (string $line) use (&$logs): void {
                $logs[] = $line;
            },
        );

        $response = $kernel->handle(self::post('/v1/documents/sign', ['certificate' => self::certificatePem(), 'document' => self::example('document-01-cash')]));

        self::assertSame(500, $response->status);
        self::assertSame('Error interno del servicio.', self::errorOf($response->body)['message']);
        self::assertStringContainsString('reloj roto', $logs[0]);
    }

    /** @param array<string, string> $sol */
    private static function sendError(string $xmlBase64, array $sol = []): string
    {
        $response = self::kernel()->handle(self::post('/v1/documents/send', [
            'environment' => 'beta',
            'sol' => [...self::SOL, ...$sol],
            'xml_base64' => $xmlBase64,
        ]));
        self::assertSame(422, $response->status, $response->json());

        return self::errorOf($response->body)['code'];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, ?string>
     */
    private static function errorOf(array $body): array
    {
        self::assertIsArray($body['error'] ?? null);

        /** @var array<string, ?string> */
        return $body['error'];
    }
}
