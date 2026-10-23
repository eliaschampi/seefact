<?php

declare(strict_types=1);

namespace See\Http;

final readonly class Response
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public array $body,
        public array $headers = [],
    ) {}

    public static function error(int $status, string $code, string $message, ?string $pointer = null): self
    {
        return new self($status, ['error' => ['code' => $code, 'message' => $message, 'pointer' => $pointer]]);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [...$this->headers, $name => $value]);
    }

    public function json(): string
    {
        return json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function emit(): void
    {
        $json = $this->json();
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        foreach ($this->headers as $name => $value) {
            header($name.': '.$value);
        }

        echo $json;
    }
}
