<?php

declare(strict_types=1);

namespace See\Cpe;

/**
 * The final bytes of a CPE. The core persists them with their sha256 and
 * every send, now or in a retry, uses exactly these bytes.
 */
final readonly class SignedDocument
{
    public function __construct(
        public string $filename,
        public string $xml,
        public string $sha256,
        public string $digestValue,
    ) {}

    /** @return array{filename: string, xml_base64: string, xml_sha256: string, digest_value: string} */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'xml_base64' => base64_encode($this->xml),
            'xml_sha256' => $this->sha256,
            'digest_value' => $this->digestValue,
        ];
    }
}
