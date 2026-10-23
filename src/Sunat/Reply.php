<?php

declare(strict_types=1);

namespace See\Sunat;

/**
 * SUNAT's answer, classified without interpretation of business meaning.
 *
 * Codes stay strings exactly as SUNAT sent them. Whether "0" is accepted,
 * "98" is pending or "0111" means a SOL profile problem is the core's
 * decision (docs/SPEC.md §4); this service only guarantees the outcome is
 * one of:
 *
 * - cdr, ticket, status, consult: SUNAT answered.
 * - fault: a SOAP fault that carries a SUNAT code.
 * - transport_error: no trustworthy answer. SUNAT may or may not have the
 *   document; the core consults before deciding anything.
 * - invalid_response: SUNAT answered with something unreadable. Raw bytes
 *   are returned as evidence.
 */
final readonly class Reply
{
    /** @param array<string, mixed> $body */
    private function __construct(
        public string $outcome,
        public ?string $sunatCode,
        public array $body,
    ) {}

    /** @param array{code: string, description: string, notes: list<string>, reference_id: ?string} $cdr */
    public static function cdr(array $cdr, string $zip): self
    {
        return new self('cdr', $cdr['code'], ['cdr' => $cdr + ['zip_base64' => base64_encode($zip)]]);
    }

    public static function ticket(string $ticket): self
    {
        return new self('ticket', null, ['ticket' => $ticket]);
    }

    /**
     * @param  array<string, mixed>|null  $cdr  parsed CDR, when SUNAT attached one
     * @param  array<string, string>|null  $cdrError  why an attached CDR could not be read
     */
    public static function status(string $statusCode, ?array $cdr, ?array $cdrError): self
    {
        return new self('status', $statusCode, ['status_code' => $statusCode, 'cdr' => $cdr, 'cdr_error' => $cdrError]);
    }

    /**
     * @param  array<string, mixed>|null  $cdr
     * @param  array<string, string>|null  $cdrError
     */
    public static function consult(string $statusCode, string $statusMessage, ?array $cdr, ?array $cdrError): self
    {
        return new self('consult', $statusCode, [
            'status_code' => $statusCode,
            'status_message' => $statusMessage,
            'cdr' => $cdr,
            'cdr_error' => $cdrError,
        ]);
    }

    public static function fault(string $code, string $message, ?string $detail, string $faultCode): self
    {
        return new self('fault', $code, ['fault' => [
            'code' => $code,
            'message' => $message,
            'detail' => $detail,
            'faultcode' => $faultCode,
        ]]);
    }

    public static function transportError(string $message): self
    {
        return new self('transport_error', null, ['error' => ['message' => $message]]);
    }

    public static function invalidResponse(string $message, ?string $raw): self
    {
        return new self('invalid_response', null, [
            'error' => ['message' => $message],
            'raw_base64' => $raw === null ? null : base64_encode($raw),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['outcome' => $this->outcome, ...$this->body];
    }
}
