<?php

declare(strict_types=1);

namespace See\Http;

final readonly class Request
{
    /** @param array<string, string> $headers lower-cased names */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers,
        public string $body,
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
            } elseif ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $name))] = $value;
            }
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);

        return new self(
            is_string($method) ? strtoupper($method) : 'GET',
            is_string($path) && $path !== '' ? $path : '/',
            $headers,
            (string) file_get_contents('php://input'),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
