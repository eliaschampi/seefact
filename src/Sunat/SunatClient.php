<?php

declare(strict_types=1);

namespace See\Sunat;

use Closure;
use Greenter\Ws\Reader\DomCdrReader;
use Greenter\Ws\Reader\XmlReader;
use Greenter\Ws\Services\SoapClient;
use Greenter\Ws\Services\WsClientInterface;
use Greenter\Ws\Services\WsdlProvider;
use Greenter\Zip\ZipFly;
use See\Support\Xml;
use SoapFault;
use Throwable;
use UnexpectedValueException;

/**
 * SUNAT's SOAP services with an honest reading of every answer.
 *
 * Greenter's senders flatten replies into an Error object: they take digits
 * from any fault text ("SSL operation failed with code 1" becomes SUNAT
 * code "1"), cast ticket codes with (int), and build a SoapClient with TLS
 * verification disabled. This client keeps Greenter's WSDL, zip and CDR
 * reader, and owns transport and classification (see Reply).
 */
final class SunatClient
{
    /** @param Closure(string, string, SolCredentials): WsClientInterface $connect WSDL path, endpoint, credentials */
    public function __construct(private readonly Closure $connect) {}

    public static function soap(int $connectTimeout, int $readTimeout): self
    {
        return new self(static function (string $wsdl, string $endpoint, SolCredentials $sol) use ($connectTimeout, $readTimeout): WsClientInterface {
            // ext/soap waits for the response under default_socket_timeout;
            // connection_timeout only bounds the TCP/TLS connect.
            ini_set('default_socket_timeout', (string) $readTimeout);

            $client = new SoapClient($wsdl, [
                'stream_context' => stream_context_create(['ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ]]),
                'connection_timeout' => $connectTimeout,
                'cache_wsdl' => WSDL_CACHE_DISK,
                'exceptions' => true,
                'trace' => false,
                'user_agent' => 'see-php/1',
            ]);
            $client->setService($endpoint);
            $client->__setSoapHeaders(new WsSecurityHeader($sol->username(), $sol->password));

            return $client;
        });
    }

    /** 01, 03 and 07 sent one by one: SUNAT answers with the CDR. */
    public function sendBill(Environment $environment, SolCredentials $sol, string $filename, string $xml): Reply
    {
        return $this->call(WsdlProvider::getBillPath(), $environment->billService(), $sol, 'sendBill', [
            'fileName' => $filename.'.zip',
            'contentFile' => self::zip($filename.'.xml', $xml),
        ], static function (array $response): Reply {
            $zip = $response['applicationResponse'] ?? null;

            if (! is_string($zip) || $zip === '') {
                return Reply::invalidResponse('SUNAT respondió sin CDR (applicationResponse vacío).', null);
            }

            try {
                return Reply::cdr(self::readCdr($zip), $zip);
            } catch (Throwable $exception) {
                return Reply::invalidResponse('CDR ilegible: '.$exception->getMessage(), $zip);
            }
        });
    }

    /** RC and RA: SUNAT answers with a ticket to poll. */
    public function sendSummary(Environment $environment, SolCredentials $sol, string $filename, string $xml): Reply
    {
        return $this->call(WsdlProvider::getBillPath(), $environment->billService(), $sol, 'sendSummary', [
            'fileName' => $filename.'.zip',
            'contentFile' => self::zip($filename.'.xml', $xml),
        ], static function (array $response): Reply {
            $ticket = $response['ticket'] ?? null;

            if (! is_string($ticket) || trim($ticket) === '') {
                return Reply::invalidResponse('SUNAT respondió sin ticket.', null);
            }

            return Reply::ticket(trim($ticket));
        });
    }

    public function ticketStatus(Environment $environment, SolCredentials $sol, string $ticket): Reply
    {
        return $this->call(WsdlProvider::getBillPath(), $environment->billService(), $sol, 'getStatus', [
            'ticket' => $ticket,
        ], static function (array $response): Reply {
            $status = self::fields($response['status'] ?? null);
            $code = $status['statusCode'] ?? null;

            if (! is_string($code) || trim($code) === '') {
                return Reply::invalidResponse('SUNAT respondió sin statusCode.', null);
            }

            return Reply::status(trim($code), ...self::attachedCdr($status['content'] ?? null));
        });
    }

    /** billConsultService: what SUNAT holds for a document, with its CDR when available. */
    public function cdrStatus(Environment $environment, SolCredentials $sol, string $ruc, string $type, string $series, int $number): Reply
    {
        $endpoint = $environment->consultService()
            ?? throw new UnexpectedValueException('La consulta de CDR sólo existe en producción.');

        return $this->call(WsdlProvider::getConsultPath(), $endpoint, $sol, 'getStatusCdr', [
            'rucComprobante' => $ruc,
            'tipoComprobante' => $type,
            'serieComprobante' => $series,
            'numeroComprobante' => $number,
        ], static function (array $response): Reply {
            $status = self::fields($response['statusCdr'] ?? null);
            $code = $status['statusCode'] ?? null;
            $message = $status['statusMessage'] ?? '';

            if (! is_string($code) || trim($code) === '') {
                return Reply::invalidResponse('SUNAT respondió sin statusCode.', null);
            }

            return Reply::consult(trim($code), is_string($message) ? trim($message) : '', ...self::attachedCdr($status['content'] ?? null));
        });
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  Closure(array<string, mixed>): Reply  $read
     */
    private function call(string $wsdl, string $endpoint, SolCredentials $sol, string $operation, array $parameters, Closure $read): Reply
    {
        try {
            $response = ($this->connect)($wsdl, $endpoint, $sol)->call($operation, ['parameters' => $parameters]);
        } catch (SoapFault $fault) {
            return self::fault($fault);
        } catch (Throwable $exception) {
            return Reply::transportError($exception::class.': '.$exception->getMessage());
        }

        if (! is_object($response)) {
            return Reply::invalidResponse('SUNAT respondió sin cuerpo SOAP.', null);
        }

        return $read(self::fields($response));
    }

    /**
     * Only a fault that carries a four-digit SUNAT code is SUNAT's verdict:
     * "soap-env:Client.0111", or a bare Client/Server fault whose string is
     * the code. Everything else (HTTP, TLS, DNS, an HTML error page) is a
     * transport error: SUNAT may or may not have received the document.
     */
    private static function fault(SoapFault $fault): Reply
    {
        $faultCode = trim((string) $fault->faultcode);
        $message = trim($fault->getMessage());
        $detail = self::fields($fault->detail)['message'] ?? null;
        $detail = is_string($detail) && trim($detail) !== '' ? trim($detail) : null;

        if (preg_match('/^(?:[A-Za-z][\w.-]*:)?(?:Client|Server)\.(\d{4})$/D', $faultCode, $match) === 1) {
            return Reply::fault($match[1], $message, $detail, $faultCode);
        }

        if (preg_match('/^(?:[A-Za-z][\w.-]*:)?(?:Client|Server)$/D', $faultCode) === 1 && preg_match('/^\d{4}$/D', $message) === 1) {
            return Reply::fault($message, $detail ?? $message, $detail, $faultCode);
        }

        return Reply::transportError(ltrim($faultCode.': '.$message, ': '));
    }

    /** @return array{code: string, description: string, notes: list<string>, reference_id: ?string} */
    private static function readCdr(string $zip): array
    {
        $files = (new ZipFly)->decompress($zip, static fn (?string $name): bool => str_ends_with(strtolower((string) $name), '.xml'));
        $first = is_array($files) ? ($files[0] ?? null) : null;
        $xml = is_array($first) ? ($first['content'] ?? null) : null;

        if (! is_string($xml) || Xml::load($xml) === null) {
            throw new UnexpectedValueException('el ZIP no contiene un XML legible.');
        }

        $cdr = (new DomCdrReader(new XmlReader))->getCdrResponse($xml);
        $code = trim((string) $cdr?->getCode());

        if ($cdr === null || $code === '') {
            throw new UnexpectedValueException('el CDR no trae ResponseCode.');
        }

        $reference = $cdr->getId();

        return [
            'code' => $code,
            'description' => trim((string) $cdr->getDescription()),
            'notes' => array_values(array_map(trim(...), $cdr->getNotes() ?? [])),
            'reference_id' => $reference === null ? null : trim($reference),
        ];
    }

    /**
     * A CDR attached to a ticket or consult reply, or why it is unreadable.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, string>|null}
     */
    private static function attachedCdr(mixed $content): array
    {
        if (! is_string($content) || $content === '') {
            return [null, null];
        }

        try {
            return [self::readCdr($content) + ['zip_base64' => base64_encode($content)], null];
        } catch (Throwable $exception) {
            return [null, ['message' => 'CDR ilegible: '.$exception->getMessage(), 'raw_base64' => base64_encode($content)]];
        }
    }

    private static function zip(string $name, string $xml): string
    {
        return (string) (new ZipFly)->compress($name, $xml);
    }

    /**
     * Properties of a SOAP response object. Keys are normalized to strings:
     * PHP turns numeric property names into integer array keys.
     *
     * @return array<string, mixed>
     */
    private static function fields(mixed $value): array
    {
        $fields = [];

        foreach (is_object($value) ? get_object_vars($value) : [] as $name => $field) {
            $fields[(string) $name] = $field;
        }

        return $fields;
    }
}
