<?php

declare(strict_types=1);

namespace See\Cpe;

use Greenter\Ws\Reader\XmlFilenameExtractor;
use Greenter\Ws\Reader\XmlReader;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use See\Input\InvalidInput;
use See\Support\Xml;
use Throwable;

/**
 * What a signed XML says it is, read from the XML itself.
 *
 * The send endpoints take no filename: SUNAT's name is derived from the
 * document, so a caller cannot send F001-8 under the name of F001-9.
 */
final readonly class XmlIdentity
{
    public const array DOCUMENTS = ['Invoice', 'CreditNote'];

    public const array SUMMARIES = ['SummaryDocuments', 'VoidedDocuments'];

    private const string FILENAME = '/^(?<ruc>\d{11})-(?:(?:01|03|07)-[FB][A-Z0-9]{3}-[1-9]\d{0,7}|R[CA]-\d{8}-[1-9]\d{0,4})$/D';

    private function __construct(
        public string $filename,
        public string $ruc,
        public string $sha256,
    ) {}

    /** @param list<string> $roots accepted document elements */
    public static function of(string $xml, array $roots, string $pointer): self
    {
        $document = Xml::load($xml)
            ?? throw InvalidInput::content($pointer, 'xml_invalid', 'El XML no está bien formado.');

        $root = $document->documentElement?->localName;

        if (! in_array($root, $roots, true)) {
            throw InvalidInput::content($pointer, 'xml_unsupported', 'Este endpoint acepta: '.implode(', ', $roots).'.');
        }

        $signature = Xml::single(Xml::xpath($document), '//ds:Signature/ds:SignatureValue');

        if ($signature === null || trim($signature) === '') {
            throw InvalidInput::content($pointer, 'xml_not_signed', 'El XML no está firmado. Fírmalo con /sign y envía esos mismos bytes.');
        }

        // A byte changed after signing would reach SUNAT as a rejection
        // (2335) and burn the correlativo. Refuse it here instead.
        if (! self::signatureVerifies($xml)) {
            throw InvalidInput::content($pointer, 'xml_signature_invalid', 'La firma no corresponde al contenido: el XML cambió después de firmarse.');
        }

        try {
            $filename = (string) (new XmlFilenameExtractor(new XmlReader))->getFilename($document);
        } catch (Throwable) {
            $filename = '';
        }

        if (preg_match(self::FILENAME, $filename, $match) !== 1) {
            throw InvalidInput::content($pointer, 'xml_unsupported', 'No se pudo derivar un nombre SUNAT válido del XML.');
        }

        return new self($filename, $match['ruc'], hash('sha256', $xml));
    }

    private static function signatureVerifies(string $xml): bool
    {
        try {
            return (new SignedXml)->verifyXml($xml) === true;
        } catch (Throwable) {
            return false;
        }
    }
}
