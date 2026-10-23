<?php

declare(strict_types=1);

namespace See\Support;

use DOMDocument;
use DOMXPath;

/**
 * The few XML operations the service performs outside Greenter.
 */
final class Xml
{
    public const array NAMESPACES = [
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'sac' => 'urn:sunat:names:specification:ubl:peru:schema:xsd:SunatAggregateComponents-1',
        'ds' => 'http://www.w3.org/2000/09/xmldsig#',
    ];

    /**
     * Escapes a value Greenter prints as element text without CDATA.
     * Its templates run with autoescape off, so "&" in a district name
     * would otherwise produce a malformed document.
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Parses without network access or warnings; null when not well-formed. */
    public static function load(string $xml): ?DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        return $loaded && $document->documentElement !== null ? $document : null;
    }

    public static function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);

        foreach (self::NAMESPACES as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }

    /** Text of the single node at $path, or null when there is not exactly one. */
    public static function single(DOMXPath $xpath, string $path): ?string
    {
        $nodes = $xpath->query($path);

        if ($nodes === false || $nodes->length !== 1) {
            return null;
        }

        return $nodes->item(0)?->nodeValue;
    }
}
