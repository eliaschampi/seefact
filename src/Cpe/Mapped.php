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
 * whose XML disagrees with any of them.
 */
final readonly class Mapped
{
    /**
     * @param  array<string, BigDecimal>  $amounts  XPath → exact amount
     * @param  array<string, int>  $counts  XPath → exact node count
     */
    public function __construct(
        public DocumentInterface $model,
        public string $filename,
        public array $amounts,
        public array $counts,
    ) {}
}
