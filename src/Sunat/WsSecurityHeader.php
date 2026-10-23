<?php

declare(strict_types=1);

namespace See\Sunat;

use SensitiveParameter;
use SoapHeader;
use SoapVar;

/**
 * WS-Security UsernameToken for SUNAT's SOAP services.
 *
 * Same wire shape as Greenter's header, except the password is escaped:
 * Greenter interpolates it into raw XML, so a Clave SOL containing "<" or
 * "&" would corrupt the envelope.
 */
final class WsSecurityHeader extends SoapHeader
{
    private const string WSS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

    private const string PASSWORD_TEXT = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText';

    public function __construct(string $username, #[SensitiveParameter] string $password)
    {
        $passwordXml = sprintf(
            '<o:Password xmlns:o="%s" Type="%s">%s</o:Password>',
            self::WSS,
            self::PASSWORD_TEXT,
            htmlspecialchars($password, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
        );

        $token = new SoapVar([
            new SoapVar($username, XSD_STRING, null, null, 'Username', self::WSS),
            new SoapVar($passwordXml, XSD_ANYXML),
        ], SOAP_ENC_OBJECT, null, null, 'UsernameToken', self::WSS);

        parent::__construct(self::WSS, 'Security', new SoapVar([$token], SOAP_ENC_OBJECT), false);
    }
}
