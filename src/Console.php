<?php

declare(strict_types=1);

namespace See;

use Composer\InstalledVersions;
use DateTimeImmutable;
use DateTimeZone;
use OpenSSLCertificateSigningRequest;
use RuntimeException;
use See\Cpe\Certificate;
use See\Cpe\DocumentMapper;
use See\Cpe\SignedDocument;
use See\Cpe\Signer;
use See\Cpe\SummaryMapper;
use See\Input\Input;
use See\Sunat\Environment;
use See\Sunat\Reply;
use See\Sunat\SolCredentials;
use See\Sunat\SunatClient;

/**
 * bin/see: operator commands. Never part of CI.
 *
 *   diagnose     runtime, extensions, configuration; no network
 *   smoke:beta   signs the contract examples and sends them to SUNAT beta
 */
final class Console
{
    private const string EXAMPLES = __DIR__.'/../docs/examples/';

    /** @param list<string> $argv */
    public static function run(array $argv): int
    {
        return match ($argv[1] ?? '') {
            'diagnose' => self::diagnose(),
            'smoke:beta' => self::smokeBeta(),
            default => self::usage(),
        };
    }

    private static function usage(): int
    {
        self::line('Uso: bin/see diagnose | bin/see smoke:beta');

        return 64;
    }

    private static function diagnose(): int
    {
        $missing = array_values(array_filter(['dom', 'libxml', 'openssl', 'soap', 'zlib'], static fn (string $extension): bool => ! extension_loaded($extension)));
        $cache = getenv('SEE_TWIG_CACHE');
        $cacheDirectory = $cache === false ? dirname(__DIR__).'/var/cache/twig' : $cache;
        $token = getenv('SEE_API_TOKEN');
        $ca = ini_get('openssl.cafile') ?: openssl_get_cert_locations()['default_cert_file'];

        self::line('see-php diagnose (sin red)');
        self::row('PHP', PHP_VERSION);
        self::row('Extensiones', $missing === [] ? 'dom libxml openssl soap zlib' : 'FALTAN: '.implode(' ', $missing));
        self::row('Greenter', (string) InstalledVersions::getPrettyVersion('greenter/lite'));
        self::row('Contrato', Kernel::CONTRACT);
        self::row('Token', is_string($token) && strlen($token) >= 32 ? 'configurado' : 'AUSENTE: SEE_API_TOKEN, mínimo 32 caracteres');
        self::row('Twig cache', $cacheDirectory === '' ? 'desactivado' : $cacheDirectory.(is_writable(is_dir($cacheDirectory) ? $cacheDirectory : dirname($cacheDirectory)) ? ' (escribible)' : ' (NO escribible)'));
        self::row('CA TLS', $ca);
        self::row('Beta', Environment::Beta->billService());
        self::row('Producción', Environment::Production->billService());
        self::row('Consulta CDR', (string) Environment::Production->consultService());

        return $missing === [] ? 0 : 1;
    }

    /**
     * The phase-1 acceptance run: factura and nota de crédito by sendBill;
     * boletas by resumen diario and the factura's baja by comunicación de
     * baja, both through ticket. Beta has no tax effect and
     * accepts an unregistered certificate, so an ephemeral one is created.
     * This is the only code in the service that reads a SUNAT code as a
     * verdict, and only to print it.
     */
    private static function smokeBeta(): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone(Input::LIMA));
        $ruc = self::env('SEE_BETA_RUC', '20000000001');
        $sol = new SolCredentials($ruc, 'MODDATOS', self::env('SEE_BETA_PASSWORD', 'MODDATOS'));
        $certificate = Certificate::fromPem(self::ephemeralPem($ruc), $now, '/certificate');
        $signer = Signer::create(null);
        $sunat = SunatClient::soap(10, 45);
        $number = random_int(1_000, 9_999_999);

        self::line('see-php smoke:beta → '.Environment::Beta->billService());
        self::line("RUC {$ruc} · correlativo {$number} · sin efecto tributario · certificado efímero");

        $invoice = self::signDocument($signer, $certificate, 'document-01-cash', $ruc, $number, $now);
        $ok = self::report('01', $invoice, $sunat->sendBill(Environment::Beta, $sol, $invoice->filename, $invoice->xml));

        $note = self::signDocument($signer, $certificate, 'document-07', $ruc, $number, $now);
        $ok = self::report('07', $note, $sunat->sendBill(Environment::Beta, $sol, $note->filename, $note->xml)) && $ok;

        $daily = self::signSummary($signer, $certificate, 'summary-rc', [
            '/issuer/ruc' => $ruc,
            '/reference_date' => $now->format('Y-m-d'),
            '/issue_date' => $now->format('Y-m-d'),
            '/lines/0/id' => 'B001-'.$number,
            '/lines/1/id' => 'BC01-'.$number,
            '/lines/1/reference/id' => 'B001-'.$number,
        ]);
        $ok = self::summaryRoundTrip($sunat, $sol, 'RC', $daily) && $ok;

        $voided = self::signSummary($signer, $certificate, 'summary-ra', [
            '/issuer/ruc' => $ruc,
            '/reference_date' => $now->format('Y-m-d'),
            '/issue_date' => $now->format('Y-m-d'),
            '/lines/0/number' => $number,
        ]);

        return self::summaryRoundTrip($sunat, $sol, 'RA', $voided) && $ok ? 0 : 1;
    }

    /** sendSummary, then poll the ticket while SUNAT says 98 (in process). */
    private static function summaryRoundTrip(SunatClient $sunat, SolCredentials $sol, string $label, SignedDocument $summary): bool
    {
        $reply = $sunat->sendSummary(Environment::Beta, $sol, $summary->filename, $summary->xml);
        $ticket = $reply->body['ticket'] ?? null;

        if (! self::report($label, $summary, $reply) || ! is_string($ticket)) {
            return false;
        }

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            sleep(2);
            $status = $sunat->ticketStatus(Environment::Beta, $sol, $ticket);

            if ($status->sunatCode !== '98') {
                return self::report($label, $summary, $status);
            }
        }

        self::line("✗ {$label} ticket {$ticket} sigue en proceso (98) tras 20 s.");

        return false;
    }

    private static function signDocument(Signer $signer, Certificate $certificate, string $example, string $ruc, int $number, DateTimeImmutable $now): SignedDocument
    {
        $changes = ['/issuer/ruc' => $ruc, '/number' => $number, '/issued_at' => $now->format(DATE_RFC3339)];

        if ($example === 'document-07') {
            $changes['/reference/number'] = $number;
        }

        $document = self::patch(self::example($example), $changes);

        return $signer->sign(DocumentMapper::map(Input::fromJson(json_encode($document, JSON_THROW_ON_ERROR))), $certificate);
    }

    /** @param array<string, mixed> $changes JSON Pointer → value */
    private static function signSummary(Signer $signer, Certificate $certificate, string $example, array $changes): SignedDocument
    {
        $summary = self::patch(self::example($example), $changes);

        return $signer->sign(SummaryMapper::map(Input::fromJson(json_encode($summary, JSON_THROW_ON_ERROR))), $certificate);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, mixed>  $changes  JSON Pointer → value
     * @return array<array-key, mixed>
     */
    private static function patch(array $data, array $changes): array
    {
        foreach ($changes as $pointer => $value) {
            $data = self::put($data, explode('/', ltrim($pointer, '/')), $value);
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private static function put(array $data, array $keys, mixed $value): array
    {
        $key = array_shift($keys);

        if ($key === null) {
            return $data;
        }

        $index = ctype_digit($key) ? (int) $key : $key;

        if ($keys === []) {
            $data[$index] = $value;

            return $data;
        }

        $child = $data[$index] ?? null;

        if (! is_array($child)) {
            throw new RuntimeException("El ejemplo no tiene el campo {$key}.");
        }

        $data[$index] = self::put($child, $keys, $value);

        return $data;
    }

    private static function report(string $label, SignedDocument $document, Reply $reply): bool
    {
        $body = $reply->toArray();
        $cdr = is_array($body['cdr'] ?? null) ? $body['cdr'] : [];
        $code = $reply->sunatCode ?? '-';
        $accepted = match ($reply->outcome) {
            'cdr' => self::accepted($code),
            'status' => $code === '0' && self::accepted(is_string($cdr['code'] ?? null) ? $cdr['code'] : ''),
            'ticket' => true,
            default => false,
        };
        $cdrText = 'cdr '.(is_string($cdr['code'] ?? null) ? $cdr['code'] : '-').' '.(is_string($cdr['description'] ?? null) ? $cdr['description'] : '');
        $detail = match ($reply->outcome) {
            'cdr' => $cdrText,
            'status' => 'ticket '.$code.' · '.$cdrText,
            'ticket' => 'ticket '.(is_string($body['ticket'] ?? null) ? $body['ticket'] : ''),
            default => $reply->outcome.' '.json_encode($body['fault'] ?? $body['error'] ?? null, JSON_UNESCAPED_UNICODE),
        };

        self::line(sprintf('%s %s %-34s %s', $accepted ? '✓' : '✗', $label, $document->filename, $detail));

        return $accepted;
    }

    /** Accepted (0) or accepted with observations (4000+); fail-closed on anything else. */
    private static function accepted(string $code): bool
    {
        return $code === '0' || (preg_match('/^\d{4}$/D', $code) === 1 && (int) $code >= 4000);
    }

    /** @return array<array-key, mixed> */
    private static function example(string $name): array
    {
        $data = json_decode((string) file_get_contents(self::EXAMPLES.$name.'.json'), true, 32, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException("Ejemplo no válido: {$name}.");
        }

        return $data;
    }

    private static function ephemeralPem(string $ruc): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        if ($key === false) {
            throw new RuntimeException('OpenSSL no pudo generar la clave efímera.');
        }

        // openssl_csr_new takes the key by reference; keep $key's own type intact.
        $signingKey = $key;
        $request = openssl_csr_new(['commonName' => $ruc.' see-php beta smoke'], $signingKey, ['digest_alg' => 'sha256']);

        if (! $request instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('OpenSSL no pudo generar la solicitud de certificado.');
        }

        $certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);

        if ($certificate === false || ! openssl_pkey_export($key, $keyPem) || ! openssl_x509_export($certificate, $certificatePem)
            || ! is_string($keyPem) || ! is_string($certificatePem)) {
            throw new RuntimeException('OpenSSL no pudo exportar el certificado efímero.');
        }

        return $keyPem.$certificatePem;
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function row(string $label, string $value): void
    {
        self::line('  '.mb_str_pad($label, 14).$value);
    }

    private static function line(string $text): void
    {
        fwrite(STDOUT, $text.PHP_EOL);
    }
}
