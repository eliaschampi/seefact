<?php

declare(strict_types=1);

namespace See\Tests\Unit;

use Greenter\Zip\ZipFly;
use RuntimeException;
use See\Sunat\Environment;
use See\Sunat\SolCredentials;
use See\Tests\TestCase;
use SoapFault;
use stdClass;
use UnexpectedValueException;

final class SunatClientTest extends TestCase
{
    private const string FILENAME = '20000000001-01-F001-1';

    public function test_send_bill_zips_the_exact_bytes_and_reads_the_cdr(): void
    {
        $calls = [];
        $zip = self::cdrZip('0', 'La Factura numero F001-1, ha sido aceptada');
        $sunat = self::sunat(static fn (): object => (object) ['applicationResponse' => $zip], $calls);

        $reply = $sunat->sendBill(Environment::Beta, self::sol(), self::FILENAME, '<xml>firmado</xml>')->toArray();

        self::assertSame('cdr', $reply['outcome']);
        self::assertSame([
            'code' => '0',
            'description' => 'La Factura numero F001-1, ha sido aceptada',
            'notes' => [],
            'reference_id' => 'F001-1',
            'zip_base64' => base64_encode($zip),
        ], $reply['cdr']);

        self::assertCount(1, $calls);
        self::assertSame('https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService', $calls[0]['endpoint']);
        self::assertSame('20000000001MODDATOS', $calls[0]['username']);
        self::assertSame('sendBill', $calls[0]['operation']);
        $parameters = $calls[0]['arguments']['parameters'];
        self::assertIsArray($parameters);
        self::assertSame(self::FILENAME.'.zip', $parameters['fileName']);
        self::assertIsString($parameters['contentFile']);
        self::assertSame(
            [['filename' => self::FILENAME.'.xml', 'content' => '<xml>firmado</xml>']],
            (new ZipFly)->decompress($parameters['contentFile']),
        );
    }

    public function test_observations_and_rejections_stay_as_sunat_wrote_them(): void
    {
        $observed = self::cdrZip('0', 'Aceptada con observaciones', ['4287 - El precio unitario de la operación no coincide']);
        $rejected = self::cdrZip('2017', 'El numero de documento de identidad del receptor debe ser RUC');

        $first = self::sunat(static fn (): object => (object) ['applicationResponse' => $observed])
            ->sendBill(Environment::Beta, self::sol(), self::FILENAME, '<x/>')->toArray();
        $second = self::sunat(static fn (): object => (object) ['applicationResponse' => $rejected])
            ->sendBill(Environment::Beta, self::sol(), self::FILENAME, '<x/>')->toArray();

        self::assertSame(['4287 - El precio unitario de la operación no coincide'], self::cdrOf($first)['notes']);
        self::assertSame('2017', self::cdrOf($second)['code']);
    }

    public function test_a_fault_with_a_sunat_code_is_sunats_verdict(): void
    {
        $reply = self::sendFailing(new SoapFault('soap-env:Client.0111', 'No tiene el perfil para enviar comprobantes electronicos'));

        self::assertSame('fault', $reply['outcome']);
        self::assertSame([
            'code' => '0111',
            'message' => 'No tiene el perfil para enviar comprobantes electronicos',
            'detail' => null,
            'faultcode' => 'soap-env:Client.0111',
        ], $reply['fault']);
    }

    public function test_a_bare_server_fault_whose_string_is_the_code(): void
    {
        $reply = self::sendFailing(new SoapFault('soap-env:Server', '0130', null, (object) ['message' => 'El sistema no puede responder su solicitud.']));

        self::assertSame('fault', $reply['outcome']);
        self::assertIsArray($reply['fault']);
        self::assertSame('0130', $reply['fault']['code']);
        self::assertSame('El sistema no puede responder su solicitud.', $reply['fault']['message']);
    }

    public function test_network_and_tls_failures_are_transport_errors_not_sunat_codes(): void
    {
        // Greenter would report this one as SUNAT code "1".
        $tls = self::sendFailing(new SoapFault('soap-env:Server', 'SSL operation failed with code 1'));
        $http = self::sendFailing(new SoapFault('HTTP', 'Could not connect to host'));
        $html = self::sendFailing(new SoapFault('Client', 'looks like we got no XML document'));
        $other = self::sendFailing(new RuntimeException('DNS'));

        foreach ([$tls, $http, $html, $other] as $reply) {
            self::assertSame('transport_error', $reply['outcome']);
            self::assertArrayNotHasKey('fault', $reply);
        }

        self::assertSame(['message' => 'soap-env:Server: SSL operation failed with code 1'], $tls['error']);
    }

    public function test_an_answer_without_a_readable_cdr_is_an_invalid_response_with_evidence(): void
    {
        $empty = self::sunat(static fn (): object => (object) ['applicationResponse' => ''])
            ->sendBill(Environment::Beta, self::sol(), self::FILENAME, '<x/>')->toArray();
        $corrupt = self::sunat(static fn (): object => (object) ['applicationResponse' => 'not a zip'])
            ->sendBill(Environment::Beta, self::sol(), self::FILENAME, '<x/>')->toArray();

        self::assertSame('invalid_response', $empty['outcome']);
        self::assertNull($empty['raw_base64']);
        self::assertSame('invalid_response', $corrupt['outcome']);
        self::assertSame(base64_encode('not a zip'), $corrupt['raw_base64']);
    }

    public function test_send_summary_returns_the_ticket(): void
    {
        $calls = [];
        $reply = self::sunat(static fn (): object => (object) ['ticket' => '1745000000123'], $calls)
            ->sendSummary(Environment::Production, self::sol(), '20000000001-RC-20261022-1', '<x/>')->toArray();

        self::assertSame(['outcome' => 'ticket', 'ticket' => '1745000000123'], $reply);
        self::assertSame('sendSummary', $calls[0]['operation']);
        self::assertSame('https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService', $calls[0]['endpoint']);
    }

    public function test_ticket_codes_are_never_cast(): void
    {
        $pending = self::ticket((object) ['statusCode' => '98']);
        $odd = self::ticket((object) ['statusCode' => 'abc']);
        $done = self::ticket((object) ['statusCode' => '0', 'content' => self::cdrZip('0', 'El Resumen diario RC-20261022-1, ha sido aceptado', [], 'RC-20261022-1')]);
        $failed = self::ticket((object) ['statusCode' => '99', 'content' => 'garbage']);

        self::assertSame(['outcome' => 'status', 'status_code' => '98', 'cdr' => null, 'cdr_error' => null], $pending);
        self::assertSame('abc', $odd['status_code']);
        self::assertSame('0', self::cdrOf($done)['code']);
        self::assertSame('99', $failed['status_code']);
        self::assertNull($failed['cdr']);
        self::assertIsArray($failed['cdr_error']);
        self::assertSame(base64_encode('garbage'), $failed['cdr_error']['raw_base64']);
    }

    public function test_cdr_consult_goes_to_the_consult_service(): void
    {
        $calls = [];
        $status = new stdClass;
        $status->statusCode = '0004';
        $status->statusMessage = 'La constancia existe';
        $status->content = self::cdrZip('0', 'La Factura numero F001-1, ha sido aceptada');

        $reply = self::sunat(static fn (): object => (object) ['statusCdr' => $status], $calls)
            ->cdrStatus(Environment::Production, self::sol(), '20000000001', '01', 'F001', 1)->toArray();

        self::assertSame('consult', $reply['outcome']);
        self::assertSame('0004', $reply['status_code']);
        self::assertSame('0', self::cdrOf($reply)['code']);
        self::assertSame('https://e-factura.sunat.gob.pe/ol-it-wsconscpegem/billConsultService', $calls[0]['endpoint']);
        self::assertStringEndsWith('billConsultService.wsdl', $calls[0]['wsdl']);
        self::assertSame(
            ['rucComprobante' => '20000000001', 'tipoComprobante' => '01', 'serieComprobante' => 'F001', 'numeroComprobante' => 1],
            $calls[0]['arguments']['parameters'],
        );
    }

    public function test_cdr_consult_does_not_exist_in_beta(): void
    {
        $this->expectException(UnexpectedValueException::class);

        self::sunat(static fn (): never => throw new RuntimeException('no'))
            ->cdrStatus(Environment::Beta, self::sol(), '20000000001', '01', 'F001', 1);
    }

    private static function sol(): SolCredentials
    {
        return new SolCredentials('20000000001', 'MODDATOS', 'MODDATOS');
    }

    /** @return array<string, mixed> */
    private static function sendFailing(\Throwable $failure): array
    {
        return self::sunat(static fn (): never => throw $failure)
            ->sendBill(Environment::Beta, self::sol(), self::FILENAME, '<x/>')->toArray();
    }

    /** @return array<string, mixed> */
    private static function ticket(object $status): array
    {
        return self::sunat(static fn (): object => (object) ['status' => $status])
            ->ticketStatus(Environment::Beta, self::sol(), '1745000000123')->toArray();
    }

    /**
     * @param  array<string, mixed>  $reply
     * @return array<string, mixed>
     */
    private static function cdrOf(array $reply): array
    {
        self::assertIsArray($reply['cdr'] ?? null, json_encode($reply, JSON_THROW_ON_ERROR));

        /** @var array<string, mixed> */
        return $reply['cdr'];
    }
}
