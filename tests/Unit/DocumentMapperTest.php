<?php

declare(strict_types=1);

namespace See\Tests\Unit;

use Closure;
use Greenter\Model\Sale\FormaPagos\FormaPagoCredito;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Note;
use See\Cpe\DocumentMapper;
use See\Input\Input;
use See\Input\InvalidInput;
use See\Tests\TestCase;

final class DocumentMapperTest extends TestCase
{
    public function test_maps_a_cash_invoice_and_names_it_like_sunat(): void
    {
        $mapped = DocumentMapper::map(self::input(self::example('document-01-cash')));

        self::assertInstanceOf(Invoice::class, $mapped->model);
        self::assertSame('20000000001-01-F001-1', $mapped->filename);
        self::assertSame(204.80, $mapped->model->getMtoImpVenta());
        self::assertNull($mapped->model->getMtoOperExoneradas(), 'Sin líneas exoneradas no se declara el tributo.');
        self::assertSame(['/*/cac:InvoiceLine' => 2], $mapped->counts);
    }

    public function test_credit_terms_carry_the_pending_amount_and_installments(): void
    {
        $mapped = DocumentMapper::map(self::input(self::example('document-01-credit')));
        $invoice = $mapped->model;

        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertInstanceOf(FormaPagoCredito::class, $invoice->getFormaPago());
        self::assertSame(3630.0, $invoice->getFormaPago()->getMonto());
        self::assertCount(2, $invoice->getCuotas() ?? []);
    }

    public function test_credit_note_follows_the_series_of_the_document_it_corrects(): void
    {
        $mapped = DocumentMapper::map(self::input(self::example('document-07')));

        self::assertInstanceOf(Note::class, $mapped->model);
        self::assertSame('F001-1', $mapped->model->getNumDocfectado());
        self::assertSame('20000000001-07-FC01-1', $mapped->filename);

        self::assertError('/series', self::example('document-07'), static fn (array &$d) => $d['series'] = 'BC01');
    }

    public function test_totals_must_be_the_exact_sum_of_the_lines(): void
    {
        $error = self::assertError('/totals/igv', self::example('document-01-cash'), static fn (array &$d) => $d['totals']['igv'] = '31.25');

        self::assertSame('inconsistent_amounts', $error->errorCode);
        self::assertStringContainsString('31.24', $error->getMessage());
    }

    public function test_total_must_be_sale_value_plus_igv(): void
    {
        self::assertError('/totals/total', self::example('document-01-cash'), static fn (array &$d) => $d['totals']['total'] = '204.81');
    }

    public function test_invoice_requires_a_ruc_customer(): void
    {
        $error = self::assertError('/customer/doc_type', self::example('document-01-cash'), static function (array &$d): void {
            $d['customer']['doc_type'] = '1';
            $d['customer']['doc_number'] = '45678912';
        });

        self::assertSame('invoice_requires_ruc', $error->errorCode);
    }

    public function test_series_must_match_the_document_type(): void
    {
        self::assertError('/series', self::example('document-01-cash'), static fn (array &$d) => $d['series'] = 'B001');
        self::assertError('/series', self::example('document-03-mixed'), static fn (array &$d) => $d['series'] = 'F001');
    }

    public function test_exempt_and_unaffected_lines_carry_no_igv(): void
    {
        self::assertError('/lines/2/igv', self::example('document-03-mixed'), static fn (array &$d) => $d['lines'][2]['igv'] = '1.00');
        self::assertError('/lines/3/unit_price', self::example('document-03-mixed'), static fn (array &$d) => $d['lines'][3]['unit_price'] = '177.00');
    }

    public function test_taxed_lines_need_a_rate_and_a_base_equal_to_the_sale_value(): void
    {
        self::assertError('/lines/0/igv_percent', self::example('document-01-cash'), static fn (array &$d) => $d['lines'][0]['igv_percent'] = '0');
        self::assertError('/lines/0/igv_base', self::example('document-01-cash'), static fn (array &$d) => $d['lines'][0]['igv_base'] = '152.38');
    }

    public function test_installments_cannot_exceed_the_total_or_fall_due_on_issue_day(): void
    {
        self::assertError('/payment/installments', self::example('document-01-credit'), static fn (array &$d) => $d['payment']['installments'][1]['amount'] = '1815.01');
        self::assertError('/payment/installments/0/due_date', self::example('document-01-credit'), static fn (array &$d) => $d['payment']['installments'][0]['due_date'] = '2026-10-22');
    }

    public function test_invoice_must_state_its_payment_terms(): void
    {
        self::assertError('/payment', self::example('document-01-cash'), static function (array &$d): void {
            unset($d['payment']);
        });
    }

    public function test_issuer_and_customer_rucs_are_check_digit_validated(): void
    {
        self::assertError('/issuer/ruc', self::example('document-01-cash'), static fn (array &$d) => $d['issuer']['ruc'] = '20123456789');
        self::assertError('/customer/doc_number', self::example('document-01-cash'), static fn (array &$d) => $d['customer']['doc_number'] = '20512333798');
    }

    public function test_fields_of_another_document_type_are_refused(): void
    {
        self::assertError('/reference', self::example('document-01-cash'), static fn (array &$d) => $d['reference'] = []);
        self::assertError('/payment', self::example('document-07'), static fn (array &$d) => $d['payment'] = ['method' => 'cash']);
    }

    /** @param array<string, mixed> $document */
    private static function input(array $document): Input
    {
        return Input::fromJson(json_encode($document, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  Closure(array<string, mixed>&): mixed  $change
     */
    private static function assertError(string $pointer, array $document, Closure $change): InvalidInput
    {
        $change($document);

        try {
            DocumentMapper::map(self::input($document));
        } catch (InvalidInput $exception) {
            self::assertSame($pointer, $exception->pointer, $exception->getMessage());

            return $exception;
        }

        self::fail("Se esperaba un error en {$pointer}.");
    }
}
