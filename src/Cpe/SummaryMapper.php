<?php

declare(strict_types=1);

namespace See\Cpe;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use Greenter\Model\Sale\Document;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;
use Greenter\Model\Voided\Voided;
use Greenter\Model\Voided\VoidedDetail;
use LogicException;
use See\Input\Input;
use See\Input\InvalidInput;
use See\Support\Xml;

/**
 * summary.v1 → Greenter Summary (RC, resumen diario de boletas) or Voided
 * (RA, comunicación de baja de facturas).
 *
 * The correlativo is per issuer and per day; the core owns it, like every
 * other number. Deadlines (RC within the legal window) are the core's too.
 */
final class SummaryMapper
{
    public const string DAILY_SUMMARY = 'RC';

    public const string VOIDED = 'RA';

    private const string RECEIPT_ID = 'B[A-Z0-9]{3}-[1-9]\d{0,7}';

    private const int MAX_LINES = 500;

    private function __construct() {}

    public static function map(Input $summary): Mapped
    {
        $type = $summary->oneOf('type', [self::DAILY_SUMMARY, self::VOIDED]);

        return $type === self::DAILY_SUMMARY ? self::dailySummary($summary) : self::voided($summary);
    }

    private static function dailySummary(Input $summary): Mapped
    {
        $summary->only('type', 'number', 'reference_date', 'issue_date', 'currency', 'issuer', 'lines');
        [$referenceDate, $issueDate] = self::dates($summary);
        $currency = $summary->oneOf('currency', ['PEN', 'USD']);
        $lines = $summary->objects('lines', 1, self::MAX_LINES);
        $amounts = [];
        $details = [];
        $customerNames = [];

        foreach ($lines as $index => $line) {
            $line->only('type', 'id', 'customer', 'status', 'reference', 'totals', 'igv_percent');
            $type = $line->oneOf('type', [DocumentMapper::RECEIPT, DocumentMapper::CREDIT_NOTE]);
            $customer = $line->object('customer')->only('doc_type', 'doc_number', 'name');
            [$customerType, $customerNumber] = Parties::identity($customer);
            $customerName = $customer->optionalText('name', 250);

            if ($customerName !== null) {
                $customerNames[$index + 1] = $customerName;
            }

            $detail = (new SummaryDetail)
                ->setTipoDoc($type)
                ->setSerieNro($line->code('id', self::RECEIPT_ID, 'Se esperaba serie-número de boleta, p. ej. B001-25.'))
                ->setEstado($line->oneOf('status', ['1', '2', '3']))
                ->setClienteTipo($customerType)
                ->setClienteNro($customerNumber)
                ->setDocReferencia(self::reference($line, $type));

            $path = '/*/sac:SummaryDocumentsLine['.($index + 1).']';

            foreach (self::lineTotals($line->object('totals'), $detail) as $node => $amount) {
                $amounts[$path.$node] = $amount;
            }

            // SUNAT rejects a line without the IGV rate (2992), even when
            // the boleta has no taxed operations.
            $percent = $line->decimal('igv_percent', 2, 3);
            $detail->setPorcentajeIgv((float) (string) $percent);
            $amounts[$path."/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory[cac:TaxScheme/cbc:ID='1000']/cbc:Percent"] = $percent;

            $details[] = $detail;
        }

        $model = (new Summary)
            ->setCorrelativo((string) $summary->integer('number', 1, 99_999))
            ->setFecGeneracion($referenceDate)
            ->setFecResumen($issueDate)
            ->setMoneda($currency)
            ->setCompany(Parties::summaryIssuer($summary->object('issuer')))
            ->setDetails($details);

        return new Mapped($model, $model->getName(), $amounts, ['/*/sac:SummaryDocumentsLine' => count($lines)], $customerNames);
    }

    /**
     * RS 000048-2026 adds the buyer's name to RC. Greenter 5.3 cannot
     * render it; complete its DOM before signing, without a second template.
     *
     * @param  array<int, string>  $names  one-based line position → name
     */
    public static function addCustomerNames(DOMDocument $document, array $names): void
    {
        if ($names === []) {
            return;
        }

        $xpath = Xml::xpath($document);

        foreach ($names as $position => $name) {
            $customers = $xpath->query("/*/sac:SummaryDocumentsLine[{$position}]/cac:AccountingCustomerParty");
            $customer = $customers === false ? null : $customers->item(0);

            if ($customers === false || $customers->length !== 1 || ! $customer instanceof DOMElement) {
                throw new LogicException('Greenter no generó el adquirente de la línea del resumen.');
            }

            $party = $document->createElementNS(Xml::NAMESPACES['cac'], 'cac:Party');
            $customer->appendChild($party);
            $legalEntity = $document->createElementNS(Xml::NAMESPACES['cac'], 'cac:PartyLegalEntity');
            $party->appendChild($legalEntity);
            $registration = $document->createElementNS(Xml::NAMESPACES['cbc'], 'cbc:RegistrationName');
            $legalEntity->appendChild($registration);
            $registration->appendChild($document->createTextNode($name));
        }
    }

    private static function voided(Input $summary): Mapped
    {
        $summary->only('type', 'number', 'reference_date', 'issue_date', 'issuer', 'lines');
        [$referenceDate, $issueDate] = self::dates($summary);
        $lines = $summary->objects('lines', 1, self::MAX_LINES);
        $details = [];

        foreach ($lines as $line) {
            $line->only('type', 'series', 'number', 'reason');
            $details[] = (new VoidedDetail)
                ->setTipoDoc($line->oneOf('type', [DocumentMapper::INVOICE, DocumentMapper::CREDIT_NOTE]))
                ->setSerie($line->code('series', 'F[A-Z0-9]{3}', 'La baja por RA es de facturas y sus notas (serie F); las boletas se anulan en el resumen diario.'))
                ->setCorrelativo((string) $line->integer('number', 1, 99_999_999))
                ->setDesMotivoBaja($line->text('reason', 100));
        }

        $model = (new Voided)
            ->setCorrelativo((string) $summary->integer('number', 1, 99_999))
            ->setFecGeneracion($referenceDate)
            ->setFecComunicacion($issueDate)
            ->setCompany(Parties::summaryIssuer($summary->object('issuer')))
            ->setDetails($details);

        return new Mapped($model, $model->getName(), [], ['/*/sac:VoidedDocumentsLine' => count($lines)]);
    }

    /**
     * reference_date is the issue date of the documents being reported;
     * issue_date is the day the summary itself is generated.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private static function dates(Input $summary): array
    {
        $referenceDate = $summary->date('reference_date');
        $issueDate = $summary->date('issue_date');

        if ($issueDate < $referenceDate) {
            throw InvalidInput::content($summary->pointer('issue_date'), 'invalid_summary_date', 'El resumen no puede generarse antes que los comprobantes que informa.');
        }

        return [$referenceDate, $issueDate];
    }

    /** A credit note of a boleta must name the boleta it corrects; nothing else may. */
    private static function reference(Input $line, string $type): ?Document
    {
        if ($type !== DocumentMapper::CREDIT_NOTE) {
            if ($line->has('reference')) {
                throw InvalidInput::shape($line->pointer('reference'), 'Sólo una nota de crédito lleva comprobante de referencia.');
            }

            return null;
        }

        $reference = $line->object('reference')->only('type', 'id');

        return (new Document)
            ->setTipoDoc($reference->oneOf('type', [DocumentMapper::RECEIPT]))
            ->setNroDoc($reference->code('id', self::RECEIPT_ID, 'Se esperaba serie-número de boleta, p. ej. B001-25.'));
    }

    /**
     * Sets the line amounts and returns the XML nodes that must carry them.
     *
     * @return array<string, BigDecimal>
     */
    private static function lineTotals(Input $totals, SummaryDetail $detail): array
    {
        $totals->only('taxable', 'exempt', 'unaffected', 'igv', 'total');
        $taxable = $totals->decimal('taxable', 2);
        $exempt = $totals->decimal('exempt', 2);
        $unaffected = $totals->decimal('unaffected', 2);
        $igv = $totals->decimal('igv', 2);
        $total = $totals->decimal('total', 2);
        $sum = $taxable->plus($exempt)->plus($unaffected)->plus($igv);

        if (! $total->isEqualTo($sum)) {
            throw InvalidInput::content($totals->pointer('total'), 'inconsistent_amounts', "No coincide con gravado + exonerado + inafecto + IGV: se esperaba {$sum}.");
        }

        if ($taxable->isZero() && ! $igv->isZero()) {
            throw InvalidInput::content($totals->pointer('igv'), 'inconsistent_amounts', 'No hay IGV sin operaciones gravadas.');
        }

        $detail->setTotal((float) (string) $total)
            ->setMtoOperGravadas($taxable->isZero() ? null : (float) (string) $taxable)
            ->setMtoOperExoneradas($exempt->isZero() ? null : (float) (string) $exempt)
            ->setMtoOperInafectas($unaffected->isZero() ? null : (float) (string) $unaffected)
            ->setMtoIGV((float) (string) $igv);

        $nodes = [
            '/sac:TotalAmount' => $total,
            '/cac:TaxTotal[cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme/cbc:ID=\'1000\']/cbc:TaxAmount' => $igv,
        ];

        foreach (['01' => $taxable, '02' => $exempt, '03' => $unaffected] as $instruction => $amount) {
            if (! $amount->isZero()) {
                $nodes["/sac:BillingPayment[cbc:InstructionID='{$instruction}']/cbc:PaidAmount"] = $amount;
            }
        }

        return $nodes;
    }
}
