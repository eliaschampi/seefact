<?php

declare(strict_types=1);

namespace See;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use See\Cpe\Certificate;
use See\Cpe\DocumentMapper;
use See\Cpe\Parties;
use See\Cpe\Signer;
use See\Cpe\SummaryMapper;
use See\Cpe\XmlIdentity;
use See\Http\Request;
use See\Http\Response;
use See\Input\Input;
use See\Input\InvalidInput;
use See\Sunat\Environment;
use See\Sunat\SolCredentials;
use See\Sunat\SunatClient;
use SensitiveParameter;
use Throwable;

/**
 * HTTP surface of the engine: one trusted caller (the core), eight routes,
 * JSON in and out. Contract: docs/CONTRACT.md.
 *
 * The HTTP status describes this service's own work. SUNAT's verdict travels
 * in the body: a rejected invoice is a 200 whose outcome carries the code.
 */
final class Kernel
{
    public const string CONTRACT = 'v1';

    private const array ROUTES = [
        '/v1/health' => 'GET',
        '/v1/documents/sign' => 'POST',
        '/v1/documents/send' => 'POST',
        '/v1/summaries/sign' => 'POST',
        '/v1/summaries/send' => 'POST',
        '/v1/tickets/status' => 'POST',
        '/v1/cdr/status' => 'POST',
        '/v1/certificates/import' => 'POST',
    ];

    private const int MIN_TOKEN_LENGTH = 32;

    /**
     * @param  Closure(): DateTimeImmutable  $clock
     * @param  Closure(string): void  $log  one JSON line per request; never bodies
     */
    public function __construct(
        #[SensitiveParameter] private readonly ?string $token,
        private readonly Signer $signer,
        private readonly SunatClient $sunat,
        private readonly Closure $clock,
        private readonly Closure $log,
        private readonly int $maxBodyBytes = 8_388_608,
    ) {}

    public static function fromEnvironment(): self
    {
        $token = getenv('SEE_API_TOKEN');
        $cache = getenv('SEE_TWIG_CACHE');

        return new self(
            is_string($token) && strlen($token) >= self::MIN_TOKEN_LENGTH ? $token : null,
            Signer::create($cache === false ? dirname(__DIR__).'/var/cache/twig' : $cache),
            SunatClient::soap(self::env('SEE_SOAP_CONNECT_TIMEOUT', 10, 1, 60), self::env('SEE_SOAP_TIMEOUT', 45, 5, 300)),
            static fn (): DateTimeImmutable => new DateTimeImmutable,
            static function (string $line): void {
                error_log($line);
            },
            self::env('SEE_MAX_BODY_BYTES', 8_388_608, 65_536, 67_108_864),
        );
    }

    public function handle(Request $request): Response
    {
        $started = hrtime(true);
        $requestId = self::requestId($request->header('x-request-id'));

        try {
            $response = $this->dispatch($request);
        } catch (InvalidInput $exception) {
            $response = Response::error($exception->status, $exception->errorCode, $exception->getMessage(), $exception->pointer);
        } catch (Throwable $exception) {
            ($this->log)(self::json([
                'service' => 'see-php',
                'level' => 'error',
                'request_id' => $requestId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'at' => $exception->getFile().':'.$exception->getLine(),
            ]));
            $response = Response::error(500, 'internal_error', 'Error interno del servicio.');
        }

        if ($request->path !== '/v1/health') {
            ($this->log)(self::json([
                'service' => 'see-php',
                'request_id' => $requestId,
                'method' => $request->method,
                'path' => $request->path,
                'status' => $response->status,
                'ms' => round((hrtime(true) - $started) / 1e6, 1),
                'outcome' => $response->body['outcome'] ?? null,
                'sunat_code' => self::sunatCode($response->body),
                'error' => self::dig($response->body, 'error', 'code'),
            ]));
        }

        return $response->withHeader('X-Request-Id', $requestId);
    }

    private function dispatch(Request $request): Response
    {
        $method = self::ROUTES[$request->path] ?? null;

        if ($method === null) {
            return Response::error(404, 'not_found', 'Ruta desconocida.');
        }

        if ($request->method !== $method) {
            return Response::error(405, 'method_not_allowed', "Use {$method}.")->withHeader('Allow', $method);
        }

        if ($this->token === null) {
            return Response::error(503, 'not_configured', 'SEE_API_TOKEN no está configurado (mínimo '.self::MIN_TOKEN_LENGTH.' caracteres).');
        }

        if ($request->path === '/v1/health') {
            return new Response(200, ['status' => 'ok', 'contract' => self::CONTRACT]);
        }

        if (! hash_equals('Bearer '.$this->token, $request->header('authorization') ?? '')) {
            return Response::error(401, 'unauthorized', 'Token ausente o inválido.');
        }

        if (! str_starts_with(strtolower($request->header('content-type') ?? ''), 'application/json')) {
            return Response::error(415, 'unsupported_media_type', 'Envíe Content-Type: application/json.');
        }

        if (strlen($request->body) > $this->maxBodyBytes) {
            return Response::error(413, 'payload_too_large', "El cuerpo supera {$this->maxBodyBytes} bytes.");
        }

        $input = Input::fromJson($request->body);

        return new Response(200, match ($request->path) {
            '/v1/documents/sign' => $this->signDocument($input),
            '/v1/documents/send' => $this->sendDocument($input),
            '/v1/summaries/sign' => $this->signSummary($input),
            '/v1/summaries/send' => $this->sendSummary($input),
            '/v1/tickets/status' => $this->ticketStatus($input),
            '/v1/certificates/import' => $this->importCertificate($input),
            default => $this->cdrStatus($input),
        });
    }

    /** @return array<string, mixed> */
    private function signDocument(Input $input): array
    {
        $input->only('certificate', 'document');
        $mapped = DocumentMapper::map($input->object('document'));

        return $this->signer->sign($mapped, $this->certificate($input))->toArray();
    }

    /** @return array<string, mixed> */
    private function signSummary(Input $input): array
    {
        $input->only('certificate', 'summary');
        $mapped = SummaryMapper::map($input->object('summary'));

        return $this->signer->sign($mapped, $this->certificate($input))->toArray();
    }

    /**
     * A .pfx/.p12 and its password → the passwordless PEM the core seals and
     * sends back with each signature, plus what it shows without opening it.
     *
     * @return array<string, string>
     */
    private function importCertificate(Input $input): array
    {
        $input->only('pkcs12_base64', 'password');
        $certificate = Certificate::fromPkcs12(
            $input->base64('pkcs12_base64', 65_536),
            $input->secret('password', 1_024),
            ($this->clock)(),
            $input->pointer('pkcs12_base64'),
            $input->pointer('password'),
        );

        return [
            'certificate' => $certificate->pem,
            'subject' => $certificate->subject,
            'not_after' => $certificate->validTo->setTimezone(new DateTimeZone(Input::LIMA))->format(DATE_RFC3339),
            'sha256' => $certificate->sha256,
        ];
    }

    /** @return array<string, mixed> */
    private function sendDocument(Input $input): array
    {
        [$environment, $sol, $xml, $identity] = $this->outgoing($input, XmlIdentity::DOCUMENTS);
        $reply = $this->sunat->sendBill($environment, $sol, $identity->filename, $xml);

        return [...$reply->toArray(), 'filename' => $identity->filename, 'xml_sha256' => $identity->sha256];
    }

    /** @return array<string, mixed> */
    private function sendSummary(Input $input): array
    {
        [$environment, $sol, $xml, $identity] = $this->outgoing($input, XmlIdentity::SUMMARIES);
        $reply = $this->sunat->sendSummary($environment, $sol, $identity->filename, $xml);

        return [...$reply->toArray(), 'filename' => $identity->filename, 'xml_sha256' => $identity->sha256];
    }

    /** @return array<string, mixed> */
    private function ticketStatus(Input $input): array
    {
        $input->only('environment', 'sol', 'ticket');

        return $this->sunat->ticketStatus(
            self::environment($input),
            SolCredentials::from($input->object('sol')),
            $input->code('ticket', '[A-Za-z0-9-]{1,64}', 'Ticket SUNAT no válido.'),
        )->toArray();
    }

    /** @return array<string, mixed> */
    private function cdrStatus(Input $input): array
    {
        $input->only('environment', 'sol', 'document');
        $environment = self::environment($input);

        if ($environment->consultService() === null) {
            throw InvalidInput::content($input->pointer('environment'), 'consult_not_available', 'SUNAT sólo ofrece la consulta de CDR en producción.');
        }

        $sol = SolCredentials::from($input->object('sol'));
        $document = $input->object('document')->only('ruc', 'type', 'series', 'number');

        return $this->sunat->cdrStatus(
            $environment,
            $sol,
            Parties::ruc($document, 'ruc'),
            $document->oneOf('type', [DocumentMapper::INVOICE, DocumentMapper::RECEIPT, DocumentMapper::CREDIT_NOTE]),
            $document->code('series', '[FB][A-Z0-9]{3}', 'Serie electrónica de 4 caracteres.'),
            $document->integer('number', 1, 99_999_999),
        )->toArray();
    }

    /**
     * What every send shares: where, who, and the exact signed bytes, whose
     * SUNAT name is read from the XML itself.
     *
     * @param  list<string>  $roots
     * @return array{Environment, SolCredentials, string, XmlIdentity}
     */
    private function outgoing(Input $input, array $roots): array
    {
        $input->only('environment', 'sol', 'xml_base64');
        $environment = self::environment($input);
        $sol = SolCredentials::from($input->object('sol'));
        $xml = $input->base64('xml_base64', $this->maxBodyBytes);
        $identity = XmlIdentity::of($xml, $roots, $input->pointer('xml_base64'));

        if ($identity->ruc !== $sol->ruc) {
            throw InvalidInput::content($input->pointer('sol').'/ruc', 'sol_ruc_mismatch', 'La Clave SOL no pertenece al emisor del XML.');
        }

        return [$environment, $sol, $xml, $identity];
    }

    private function certificate(Input $input): Certificate
    {
        return Certificate::fromPem($input->secret('certificate', 65_536), ($this->clock)(), $input->pointer('certificate'));
    }

    private static function environment(Input $input): Environment
    {
        return Environment::from($input->oneOf('environment', [Environment::Beta->value, Environment::Production->value]));
    }

    private static function requestId(?string $given): string
    {
        return $given !== null && preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $given) === 1 ? $given : bin2hex(random_bytes(8));
    }

    /** @param array<string, mixed> $body */
    private static function sunatCode(array $body): ?string
    {
        return self::dig($body, 'cdr', 'code')
            ?? self::dig($body, 'fault', 'code')
            ?? self::dig($body, 'status_code');
    }

    /** @param array<string, mixed> $body */
    private static function dig(array $body, string ...$path): ?string
    {
        $value = $body;

        foreach ($path as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $fields */
    private static function json(array $fields): string
    {
        return (string) json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function env(string $name, int $default, int $min, int $max): int
    {
        $value = getenv($name);

        if (! is_string($value) || preg_match('/^\d{1,9}$/D', $value) !== 1) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
