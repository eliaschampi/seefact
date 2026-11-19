<?php

declare(strict_types=1);

namespace See\Cpe;

use Brick\Math\BigDecimal;
use Greenter\Model\DocumentInterface;

/**
 * A Greenter model plus the evidence its rendered XML must show.
 *
 * Greenter keeps amounts as floats. The mapper records, per XPath, the exact
 * decimal each node must print, and the signer refuses to return a document
 * whose XML disagrees with any of them. Line amounts are relative to their
 * line: the audit walks the lines once, because resolving `Line[n]` for each
 * amount costs libxml a scan of the siblings, quadratic in the line count.
 */
final readonly class Mapped
{
    /**
     * @param  array<string, BigDecimal>  $amounts  document XPath → exact amount
     * @param  string  $linePath  XPath of the line elements, in document order
     * @param  list<array<string, BigDecimal>>  $lines  one per line: XPath relative to it → exact amount
     * @param  array<int, string>  $summaryCustomerNames  RC line index → buyer name (Greenter 5.3 has no field)
     */
    public function __construct(
        public DocumentInterface $model,
        public string $filename,
        public array $amounts,
        public string $linePath,
        public array $lines,
        public array $summaryCustomerNames = [],
    ) {}
}
