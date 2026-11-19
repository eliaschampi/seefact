<?php

declare(strict_types=1);

namespace See\Cpe;

use Brick\Math\BigDecimal;
use DOMNode;
use DOMXPath;
use Greenter\Builder\BuilderInterface;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Note;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Voided\Voided;
use Greenter\Xml\Builder\InvoiceBuilder;
use Greenter\Xml\Builder\NoteBuilder;
use Greenter\Xml\Builder\SummaryBuilder;
use Greenter\Xml\Builder\VoidedBuilder;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use LogicException;
use See\Support\Xml;
use Throwable;

/**
 * Renders a mapped document to UBL with Greenter, signs it once and audits
 * the result before anyone can send it.
 *
 * Nothing here can be asked to regenerate a document: the bytes returned
 * are the CPE.
 */
final class Signer
{
    /** @param array<string, mixed> $twigOptions */
    private function __construct(private readonly array $twigOptions) {}

    /** Greenter's own defaults plus an optional compiled-template cache. */
    public static function create(?string $cacheDirectory): self
    {
        return new self([
            'autoescape' => false,
            'cache' => $cacheDirectory === null || $cacheDirectory === '' ? false : $cacheDirectory,
        ]);
    }

    public function sign(Mapped $mapped, Certificate $certificate): SignedDocument
    {
        $document = Xml::load($this->render($mapped))
            ?? throw new LogicException('Greenter generó un XML mal formado.');
        SummaryMapper::addCustomerNames($document, $mapped->summaryCustomerNames);

        $signer = new SignedXml;
        $signer->setCertificate($certificate->pem);
        $signer->sign($document);

        $xml = $document->saveXML();

        if ($xml === false) {
            throw new LogicException('No se pudo serializar el XML firmado.');
        }

        $xpath = Xml::xpath($document);
        self::audit($xpath, $mapped);

        $digest = Xml::single($xpath, '//ds:SignedInfo/ds:Reference/ds:DigestValue')
            ?? throw new LogicException('El XML firmado no tiene DigestValue.');

        return new SignedDocument($mapped->filename, $xml, hash('sha256', $xml), $digest);
    }

    /**
     * Greenter prints floats through PHP's string conversion. With the
     * default precision (14) a value like 12345.123456789 would lose digits;
     * -1 prints the shortest representation that round-trips, which is the
     * original decimal for every value Input accepts.
     */
    private function render(Mapped $mapped): string
    {
        $precision = ini_get('precision');
        ini_set('precision', '-1');

        try {
            return (string) $this->builder($mapped)->build($mapped->model);
        } finally {
            ini_set('precision', $precision);
        }
    }

    private function builder(Mapped $mapped): BuilderInterface
    {
        return match (true) {
            $mapped->model instanceof Invoice => new InvoiceBuilder($this->twigOptions),
            $mapped->model instanceof Note => new NoteBuilder($this->twigOptions),
            $mapped->model instanceof Summary => new SummaryBuilder($this->twigOptions),
            $mapped->model instanceof Voided => new VoidedBuilder($this->twigOptions),
            default => throw new LogicException('Documento sin plantilla UBL: '.$mapped->model::class),
        };
    }

    /** Every line and amount the mapper declared must appear, exactly, in the signed XML. */
    private static function audit(DOMXPath $xpath, Mapped $mapped): void
    {
        $lines = $xpath->query($mapped->linePath);
        $found = $lines === false ? 0 : $lines->length;

        if ($lines === false || $found !== count($mapped->lines)) {
            throw new LogicException("Auditoría XML: {$mapped->linePath} tiene {$found} nodos, se esperaban ".count($mapped->lines).'.');
        }

        self::auditAmounts($xpath, $mapped->amounts, null, '');

        foreach ($mapped->lines as $index => $amounts) {
            $line = $lines->item($index);
            self::auditAmounts($xpath, $amounts, $line instanceof DOMNode ? $line : null, $mapped->linePath.'['.($index + 1).']/');
        }
    }

    /** @param array<string, BigDecimal> $amounts */
    private static function auditAmounts(DOMXPath $xpath, array $amounts, ?DOMNode $context, string $label): void
    {
        foreach ($amounts as $path => $expected) {
            $actual = Xml::single($xpath, $path, $context);

            if ($actual === null || ! self::sameAmount($actual, $expected)) {
                throw new LogicException("Auditoría XML: {$label}{$path} = ".var_export($actual, true).", se esperaba {$expected}.");
            }
        }
    }

    private static function sameAmount(string $text, BigDecimal $expected): bool
    {
        try {
            return BigDecimal::of(trim($text))->isEqualTo($expected);
        } catch (Throwable) {
            return false;
        }
    }
}
