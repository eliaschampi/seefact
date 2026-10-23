<?php

declare(strict_types=1);

namespace See\Cpe;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Greenter\Model\Sale\Cuota;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\FormaPagos\FormaPagoCredito;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;
use See\Input\Input;
use See\Input\InvalidInput;
use See\Support\Xml;

/**
 * cpe.v1 → Greenter Invoice (01 factura, 03 boleta) or Note (07 crédito).
 *
 * The core owns tax math. This mapper owns the contract's structure and
 * proves the payload is internally consistent (every total is the exact sum
 * of its lines) before Greenter renders UBL. It also records which XML nodes
 * must carry which amounts, so the signer audits the rendered document
 * instead of trusting Greenter's float models.
 */
final class DocumentMapper
{
    public const string INVOICE = '01';

    public const string RECEIPT = '03';

    public const string CREDIT_NOTE = '07';

    private const string TAXED = '10';

    private const string EXEMPT = '20';

    private const string UNAFFECTED = '30';

    /** Catálogo 09 reasons whose credit note keeps the standard line structure. */
    private const array CREDIT_REASONS = ['01', '02', '06', '07', '09'];

    private const array COMMON_KEYS = ['type', 'series', 'number', 'issued_at', 'currency', 'issuer', 'customer', 'lines', 'totals', 'legends'];

    private const int MAX_NUMBER = 99_999_999;

    private const int MAX_LINES = 1000;

    /** @var array<string, BigDecimal> */
    private array $amounts = [];

    /** @var array<string, int> */
    private array $counts = [];

    private function __construct() {}

    public static function map(Input $document): Mapped
    {
        return (new self)->build($document);
    }

    private function build(Input $document): Mapped
    {
        $type = $document->oneOf('type', [self::INVOICE, self::RECEIPT, self::CREDIT_NOTE]);
        $isNote = $type === self::CREDIT_NOTE;
        $document->only(...self::COMMON_KEYS, ...($isNote ? ['reference'] : ['operation_type', 'due_date', 'payment']));

        $issuedAt = $document->dateTime('issued_at');
        $currency = $document->oneOf('currency', ['PEN', 'USD']);
        $client = Parties::customer($document->object('customer'));

        if ($type === self::INVOICE && $client->getTipoDoc() !== '6') {
            throw InvalidInput::content($document->pointer('customer').'/doc_type', 'invoice_requires_ruc', 'La factura exige un adquirente con RUC (tipo 6).');
        }

        $sale = $isNote ? $this->creditNote($document) : $this->invoice($document, $type, $issuedAt);
        $lines = $this->lines($document->objects('lines', 1, self::MAX_LINES), $isNote);
        $total = $this->totals($document->object('totals'), $sale, $lines);

        if ($sale instanceof Invoice) {
            $this->payment($document, $sale, $type, $issuedAt, $currency, $total);
        }

        $sale->setUblVersion('2.1')
            ->setTipoDoc($type)
            ->setCorrelativo((string) $document->integer('number', 1, self::MAX_NUMBER))
            ->setFechaEmision($issuedAt)
            ->setTipoMoneda($currency)
            ->setCompany(Parties::issuer($document->object('issuer')))
            ->setClient($client)
            ->setDetails($lines['details'])
            ->setLegends($this->legends($document));

        return new Mapped($sale, $sale->getName(), $this->amounts, $this->counts);
    }

    private function invoice(Input $document, string $type, DateTimeImmutable $issuedAt): Invoice
    {
        $dueDate = $document->optionalDate('due_date');

        if ($dueDate !== null && $dueDate->format('Y-m-d') < $issuedAt->format('Y-m-d')) {
            throw InvalidInput::content($document->pointer('due_date'), 'invalid_due_date', 'El vencimiento no puede ser anterior a la emisión.');
        }

        $prefix = $type === self::INVOICE ? 'F' : 'B';
        $invoice = new Invoice;
        $invoice->setTipoOperacion($document->oneOf('operation_type', ['0101']))
            ->setFecVencimiento($dueDate);
        $invoice->setSerie($document->code('series', $prefix.'[A-Z0-9]{3}', "La serie de este comprobante empieza con {$prefix} y tiene 4 caracteres."));

        return $invoice;
    }

    private function creditNote(Input $document): Note
    {
        $reference = $document->object('reference')->only('type', 'series', 'number', 'reason_code', 'reason');
        $prefix = $reference->oneOf('type', [self::INVOICE, self::RECEIPT]) === self::INVOICE ? 'F' : 'B';
        $series = $reference->code('series', $prefix.'[A-Z0-9]{3}', 'Serie no válida para el comprobante referido.');

        $note = new Note;
        $note->setTipDocAfectado($prefix === 'F' ? self::INVOICE : self::RECEIPT)
            ->setNumDocfectado($series.'-'.$reference->integer('number', 1, self::MAX_NUMBER))
            ->setCodMotivo($reference->oneOf('reason_code', self::CREDIT_REASONS))
            ->setDesMotivo(Xml::escape($reference->text('reason', 250)));
        $note->setSerie($document->code('series', $prefix.'[A-Z0-9]{3}', "La nota de crédito usa una serie que empieza con {$prefix}, como el comprobante que corrige."));

        return $note;
    }

    /**
     * @param  list<Input>  $lines
     * @return array{details: list<SaleDetail>, taxed: ?BigDecimal, exempt: ?BigDecimal, unaffected: ?BigDecimal, igv: BigDecimal, sale_value: BigDecimal}
     */
    private function lines(array $lines, bool $isNote): array
    {
        $element = $isNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';
        $quantityElement = $isNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity';
        $sums = ['taxed' => null, 'exempt' => null, 'unaffected' => null];
        $igvTotal = BigDecimal::zero();
        $saleValueTotal = BigDecimal::zero();
        $details = [];

        foreach ($lines as $index => $line) {
            $line->only('code', 'sunat_code', 'description', 'unit_code', 'quantity', 'unit_value', 'unit_price', 'sale_value', 'tax_affectation', 'igv_base', 'igv_percent', 'igv');

            $affectation = $line->oneOf('tax_affectation', [self::TAXED, self::EXEMPT, self::UNAFFECTED]);
            $quantity = self::positive($line, 'quantity', 10);
            $unitValue = self::positive($line, 'unit_value', 10);
            $unitPrice = self::positive($line, 'unit_price', 10);
            $saleValue = self::positive($line, 'sale_value', 2);
            $igvBase = $line->decimal('igv_base', 2);
            $igvPercent = $line->decimal('igv_percent', 2, 3);
            $igv = $line->decimal('igv', 2);

            if (! $igvBase->isEqualTo($saleValue)) {
                throw InvalidInput::content($line->pointer('igv_base'), 'inconsistent_amounts', 'En v1 la base del IGV es el valor de venta de la línea.');
            }

            if ($affectation === self::TAXED && ! $igvPercent->isPositive()) {
                throw InvalidInput::content($line->pointer('igv_percent'), 'inconsistent_amounts', 'Una línea gravada exige tasa de IGV.');
            }

            if ($affectation !== self::TAXED && ! $igv->isZero()) {
                throw InvalidInput::content($line->pointer('igv'), 'inconsistent_amounts', 'Una línea exonerada o inafecta no lleva IGV.');
            }

            if ($affectation !== self::TAXED && ! $unitPrice->isEqualTo($unitValue)) {
                throw InvalidInput::content($line->pointer('unit_price'), 'inconsistent_amounts', 'Sin IGV, el precio unitario es el valor unitario.');
            }

            $bucket = match ($affectation) {
                self::TAXED => 'taxed',
                self::EXEMPT => 'exempt',
                default => 'unaffected',
            };
            $sums[$bucket] = ($sums[$bucket] ?? BigDecimal::zero())->plus($saleValue);
            $igvTotal = $igvTotal->plus($igv);
            $saleValueTotal = $saleValueTotal->plus($saleValue);

            $details[] = (new SaleDetail)
                ->setCodProducto($line->optionalCode('code', '[A-Za-z0-9._\/-]{1,30}'))
                ->setCodProdSunat($line->optionalCode('sunat_code', '\d{8}', 'El código de producto SUNAT tiene 8 dígitos.'))
                ->setDescripcion($line->text('description', 500, multiline: true))
                ->setUnidad($line->code('unit_code', '[A-Z0-9]{2,3}', 'Unidad de medida del Catálogo 03.'))
                ->setCantidad(self::float($quantity))
                ->setMtoValorUnitario(self::float($unitValue))
                ->setMtoPrecioUnitario(self::float($unitPrice))
                ->setMtoValorVenta(self::float($saleValue))
                ->setTipAfeIgv($affectation)
                ->setMtoBaseIgv(self::float($igvBase))
                ->setPorcentajeIgv(self::float($igvPercent))
                ->setIgv(self::float($igv))
                ->setTotalImpuestos(self::float($igv));

            $path = '/*/'.$element.'['.($index + 1).']';
            $this->amounts[$path.'/'.$quantityElement] = $quantity;
            $this->amounts[$path.'/cbc:LineExtensionAmount'] = $saleValue;
            $this->amounts[$path.'/cac:PricingReference/cac:AlternativeConditionPrice/cbc:PriceAmount'] = $unitPrice;
            $this->amounts[$path.'/cac:TaxTotal/cbc:TaxAmount'] = $igv;
            $this->amounts[$path.'/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount'] = $igvBase;
            $this->amounts[$path.'/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxAmount'] = $igv;
            $this->amounts[$path.'/cac:Price/cbc:PriceAmount'] = $unitValue;
        }

        $this->counts['/*/'.$element] = count($lines);

        return ['details' => $details, ...$sums, 'igv' => $igvTotal, 'sale_value' => $saleValueTotal];
    }

    /**
     * Declared totals must equal the sums of the lines, to the cent.
     *
     * @param  array{taxed: ?BigDecimal, exempt: ?BigDecimal, unaffected: ?BigDecimal, igv: BigDecimal, sale_value: BigDecimal}  $lines
     */
    private function totals(Input $totals, Invoice|Note $sale, array $lines): BigDecimal
    {
        $totals->only('taxable', 'exempt', 'unaffected', 'igv', 'sale_value', 'total');
        $total = $lines['sale_value']->plus($lines['igv']);
        $expected = [
            'taxable' => $lines['taxed'] ?? BigDecimal::zero(),
            'exempt' => $lines['exempt'] ?? BigDecimal::zero(),
            'unaffected' => $lines['unaffected'] ?? BigDecimal::zero(),
            'igv' => $lines['igv'],
            'sale_value' => $lines['sale_value'],
            'total' => $total,
        ];

        foreach ($expected as $key => $sum) {
            if (! $totals->decimal($key, 2)->isEqualTo($sum)) {
                throw InvalidInput::content($totals->pointer($key), 'inconsistent_amounts', "No coincide con la suma de las líneas: se esperaba {$sum}.");
            }
        }

        $sale->setMtoOperGravadas(self::optionalFloat($lines['taxed']))
            ->setMtoOperExoneradas(self::optionalFloat($lines['exempt']))
            ->setMtoOperInafectas(self::optionalFloat($lines['unaffected']))
            ->setMtoIGV(self::float($lines['igv']))
            ->setTotalImpuestos(self::float($lines['igv']))
            ->setMtoImpVenta(self::float($total));
        $sale->setValorVenta(self::float($lines['sale_value']));
        $sale->setSubTotal(self::float($total));

        // Greenter's credit note template prints only PayableAmount here.
        if ($sale instanceof Invoice) {
            $this->amounts['/*/cac:LegalMonetaryTotal/cbc:LineExtensionAmount'] = $lines['sale_value'];
            $this->amounts['/*/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'] = $total;
        }

        $this->amounts['/*/cac:LegalMonetaryTotal/cbc:PayableAmount'] = $total;
        $this->amounts['/*/cac:TaxTotal/cbc:TaxAmount'] = $lines['igv'];

        foreach (['taxed' => '1000', 'exempt' => '9997', 'unaffected' => '9998'] as $bucket => $scheme) {
            if ($lines[$bucket] !== null) {
                $this->amounts["/*/cac:TaxTotal/cac:TaxSubtotal[cac:TaxCategory/cac:TaxScheme/cbc:ID='{$scheme}']/cbc:TaxableAmount"] = $lines[$bucket];
            }
        }

        return $total;
    }

    /** Factura must state cash or credit (with installments); boleta may. */
    private function payment(Input $document, Invoice $invoice, string $type, DateTimeImmutable $issuedAt, string $currency, BigDecimal $total): void
    {
        $payment = $type === self::INVOICE ? $document->object('payment') : $document->optionalObject('payment');

        if ($payment === null) {
            return;
        }

        $payment->only('method', 'installments');

        if ($payment->oneOf('method', ['cash', 'credit']) === 'cash') {
            if ($payment->has('installments')) {
                throw InvalidInput::shape($payment->pointer('installments'), 'Las cuotas sólo aplican al crédito.');
            }

            $invoice->setFormaPago(new FormaPagoContado);

            return;
        }

        $pending = BigDecimal::zero();
        $installments = [];

        foreach ($payment->objects('installments', 1, 60) as $index => $installment) {
            $installment->only('amount', 'due_date');
            $amount = self::positive($installment, 'amount', 2);
            $dueDate = $installment->date('due_date');

            if ($dueDate->format('Y-m-d') <= $issuedAt->format('Y-m-d')) {
                throw InvalidInput::content($installment->pointer('due_date'), 'invalid_due_date', 'Cada cuota vence después de la fecha de emisión.');
            }

            $pending = $pending->plus($amount);
            $installments[] = (new Cuota)->setMoneda($currency)->setMonto(self::float($amount))->setFechaPago($dueDate);
            $this->amounts[sprintf("/*/cac:PaymentTerms[cbc:PaymentMeansID='Cuota%03d']/cbc:Amount", $index + 1)] = $amount;
        }

        if ($pending->isGreaterThan($total)) {
            throw InvalidInput::content($payment->pointer('installments'), 'inconsistent_amounts', 'Las cuotas suman más que el total del comprobante.');
        }

        $invoice->setFormaPago(new FormaPagoCredito(self::float($pending), $currency))
            ->setCuotas($installments);
        $this->amounts["/*/cac:PaymentTerms[cbc:PaymentMeansID='Credito']/cbc:Amount"] = $pending;
    }

    /** @return list<Legend> */
    private function legends(Input $document): array
    {
        if (! $document->has('legends')) {
            return [];
        }

        $legends = [];

        foreach ($document->objects('legends', 1, 10) as $legend) {
            $legend->only('code', 'value');
            $legends[] = (new Legend)
                ->setCode($legend->code('code', '\d{4}', 'Código de leyenda del Catálogo 52.'))
                ->setValue($legend->text('value', 500));
        }

        return $legends;
    }

    private static function positive(Input $input, string $key, int $scale): BigDecimal
    {
        $value = $input->decimal($key, $scale);

        if (! $value->isPositive()) {
            throw InvalidInput::content($input->pointer($key), 'inconsistent_amounts', 'Debe ser mayor que cero.');
        }

        return $value;
    }

    /** Exact for every value Input accepts: at most 15 significant digits. */
    private static function float(BigDecimal $value): float
    {
        return (float) (string) $value;
    }

    private static function optionalFloat(?BigDecimal $value): ?float
    {
        return $value === null ? null : self::float($value);
    }
}
