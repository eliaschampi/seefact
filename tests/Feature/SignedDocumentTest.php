<?php

declare(strict_types=1);

namespace See\Tests\Feature;

use Greenter\XMLSecLibs\Sunat\SignedXml;
use PHPUnit\Framework\Attributes\DataProvider;
use See\Support\Xml;
use See\Tests\TestCase;

/**
 * Golden files: the signed XML of every contract example, byte for byte.
 * RSA PKCS#1 v1.5 signatures are deterministic, so a diff here means the
 * CPE changed. Regenerate deliberately with UPDATE_GOLDEN=1.
 */
final class SignedDocumentTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function examples(): iterable
    {
        yield 'factura contado' => ['document-01-cash', '/v1/documents/sign', '20000000001-01-F001-1'];
        yield 'factura crédito' => ['document-01-credit', '/v1/documents/sign', '20000000001-01-F001-2'];
        yield 'boleta mixta' => ['document-03-mixed', '/v1/documents/sign', '20000000001-03-B001-1'];
        yield 'nota de crédito' => ['document-07', '/v1/documents/sign', '20000000001-07-FC01-1'];
        yield 'resumen diario' => ['summary-rc', '/v1/summaries/sign', '20000000001-RC-20261022-1'];
        yield 'comunicación de baja' => ['summary-ra', '/v1/summaries/sign', '20000000001-RA-20261023-1'];
    }

    #[DataProvider('examples')]
    public function test_signed_xml_matches_its_golden_file(string $example, string $path, string $filename): void
    {
        $key = str_starts_with($example, 'summary') ? 'summary' : 'document';
        $response = self::kernel()->handle(self::post($path, [
            'certificate' => self::certificatePem(),
            $key => self::example($example),
        ]));

        self::assertSame(200, $response->status, $response->json());
        $body = $response->body;
        self::assertIsString($body['xml_base64']);
        $xml = base64_decode($body['xml_base64'], true);
        self::assertIsString($xml);

        self::assertSame($filename, $body['filename']);
        self::assertSame(hash('sha256', $xml), $body['xml_sha256']);
        self::assertTrue((new SignedXml)->verifyXml($xml), 'La firma XMLDSig no verifica.');

        $document = Xml::load($xml);
        self::assertNotNull($document);
        self::assertSame($body['digest_value'], Xml::single(Xml::xpath($document), '//ds:DigestValue'));

        $golden = self::ROOT.'/tests/Golden/'.$filename.'.xml';

        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents($golden, $xml);
        }

        self::assertFileExists($golden, 'Genera los golden con UPDATE_GOLDEN=1 composer test.');
        self::assertSame(file_get_contents($golden), $xml, "El XML firmado de {$filename} cambió.");
    }

    public function test_text_is_escaped_for_the_xml_context_it_lands_in(): void
    {
        $body = self::signedDocument('document-03-mixed');
        self::assertIsString($body['xml_base64']);
        $xml = (string) base64_decode($body['xml_base64'], true);

        // Element text without CDATA: escaped.
        self::assertStringContainsString('<cbc:CitySubdivisionName>URB. MAGISTERIO &amp; LOS PINOS</cbc:CitySubdivisionName>', $xml);
        // CDATA: carried verbatim.
        self::assertStringContainsString('<![CDATA[CALLE LOS ÁLAMOS 12 <INT. 3>]]>', $xml);
        self::assertStringContainsString('<![CDATA[MARÍA PÉREZ & HIJOS]]>', $xml);
        self::assertStringContainsString("<![CDATA[Café orgánico tostado\nLote 2026-10]]>", $xml);
    }

    public function test_decimal_quantities_and_unit_values_survive_greenters_floats(): void
    {
        $body = self::signedDocument('document-03-mixed');
        self::assertIsString($body['xml_base64']);
        $xml = (string) base64_decode($body['xml_base64'], true);

        self::assertStringContainsString('<cbc:InvoicedQuantity unitCode="KGM">1.5</cbc:InvoicedQuantity>', $xml);
        self::assertStringContainsString('<cbc:PriceAmount currencyID="PEN">40.677966</cbc:PriceAmount>', $xml);
        self::assertStringContainsString('<cbc:PayableAmount currencyID="PEN">318.50</cbc:PayableAmount>', $xml);
    }

    public function test_rendering_does_not_leak_the_precision_setting(): void
    {
        $before = ini_get('precision');
        self::signedDocument('document-01-cash');

        self::assertSame($before, ini_get('precision'));
    }
}
