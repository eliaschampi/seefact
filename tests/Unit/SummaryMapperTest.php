<?php

declare(strict_types=1);

namespace See\Tests\Unit;

use Closure;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Voided\Voided;
use See\Cpe\SummaryMapper;
use See\Input\Input;
use See\Input\InvalidInput;
use See\Tests\TestCase;

final class SummaryMapperTest extends TestCase
{
    public function test_daily_summary_is_named_by_its_generation_day(): void
    {
        $mapped = SummaryMapper::map(self::input(self::example('summary-rc')));

        self::assertInstanceOf(Summary::class, $mapped->model);
        self::assertSame('20000000001-RC-20261022-1', $mapped->filename);
        self::assertSame(['/*/sac:SummaryDocumentsLine' => 2], $mapped->counts);
    }

    public function test_voided_communication_is_named_by_its_communication_day(): void
    {
        $mapped = SummaryMapper::map(self::input(self::example('summary-ra')));

        self::assertInstanceOf(Voided::class, $mapped->model);
        self::assertSame('20000000001-RA-20261023-1', $mapped->filename);
    }

    public function test_line_total_must_add_up(): void
    {
        self::assertError('/lines/0/totals/total', self::example('summary-rc'), static fn (array &$s) => $s['lines'][0]['totals']['total'] = '318.51');
    }

    public function test_only_a_credit_note_carries_a_reference(): void
    {
        self::assertError('/lines/0/reference', self::example('summary-rc'), static fn (array &$s) => $s['lines'][0]['reference'] = ['type' => '03', 'id' => 'B001-9']);
        self::assertError('/lines/1/reference', self::example('summary-rc'), static function (array &$s): void {
            unset($s['lines'][1]['reference']);
        });
    }

    public function test_every_line_states_the_igv_rate_sunat_2992(): void
    {
        self::assertError('/lines/1/igv_percent', self::example('summary-rc'), static function (array &$s): void {
            unset($s['lines'][1]['igv_percent']);
        });
    }

    public function test_a_summary_cannot_predate_its_documents(): void
    {
        self::assertError('/issue_date', self::example('summary-rc'), static fn (array &$s) => $s['issue_date'] = '2026-10-21');
    }

    public function test_voided_communication_is_for_invoice_series_only(): void
    {
        self::assertError('/lines/0/series', self::example('summary-ra'), static fn (array &$s) => $s['lines'][0]['series'] = 'B001');
    }

    /** @param array<string, mixed> $summary */
    private static function input(array $summary): Input
    {
        return Input::fromJson(json_encode($summary, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  Closure(array<string, mixed>&): mixed  $change
     */
    private static function assertError(string $pointer, array $summary, Closure $change): void
    {
        $change($summary);

        try {
            SummaryMapper::map(self::input($summary));
        } catch (InvalidInput $exception) {
            self::assertSame($pointer, $exception->pointer, $exception->getMessage());

            return;
        }

        self::fail("Se esperaba un error en {$pointer}.");
    }
}
