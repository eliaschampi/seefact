<?php

declare(strict_types=1);

namespace See\Cpe;

use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use See\Input\Input;
use See\Input\InvalidInput;
use See\Support\RucNumber;
use See\Support\Xml;

/**
 * Issuer and customer identities shared by documents and summaries.
 */
final class Parties
{
    /** Catálogo 06 types accepted in v1. */
    public const array IDENTITY_TYPES = ['0', '1', '4', '6', '7'];

    public static function ruc(Input $input, string $key): string
    {
        $ruc = $input->code($key, '\d{11}', 'El RUC tiene 11 dígitos.');

        if (! RucNumber::isValid($ruc)) {
            throw InvalidInput::shape($input->pointer($key), 'El dígito verificador del RUC no es válido.');
        }

        return $ruc;
    }

    /** Issuer of a 01/03/07, with the registered establishment SUNAT validates. */
    public static function issuer(Input $issuer): Company
    {
        $issuer->only('ruc', 'legal_name', 'trade_name', 'address');
        $address = $issuer->object('address')
            ->only('establishment_code', 'ubigeo', 'department', 'province', 'district', 'urbanization', 'line');
        $urbanization = $address->optionalText('urbanization', 30);

        $location = (new Address)
            ->setCodLocal($address->code('establishment_code', '\d{4}', 'El código de establecimiento SUNAT tiene 4 dígitos.'))
            ->setUbigueo($address->code('ubigeo', '\d{6}', 'El ubigeo tiene 6 dígitos.'))
            ->setDepartamento(Xml::escape($address->text('department', 30)))
            ->setProvincia(Xml::escape($address->text('province', 30)))
            ->setDistrito(Xml::escape($address->text('district', 30)))
            ->setUrbanizacion($urbanization === null ? null : Xml::escape($urbanization))
            ->setDireccion($address->text('line', 200))
            ->setCodigoPais('PE');

        return (new Company)
            ->setRuc(self::ruc($issuer, 'ruc'))
            ->setRazonSocial($issuer->text('legal_name', 1500))
            ->setNombreComercial($issuer->optionalText('trade_name', 1500))
            ->setAddress($location);
    }

    /** Issuer of an RC/RA: Greenter prints only RUC and legal name there. */
    public static function summaryIssuer(Input $issuer): Company
    {
        $issuer->only('ruc', 'legal_name');

        return (new Company)
            ->setRuc(self::ruc($issuer, 'ruc'))
            ->setRazonSocial($issuer->text('legal_name', 1500));
    }

    public static function customer(Input $customer): Client
    {
        $customer->only('doc_type', 'doc_number', 'name', 'address');
        [$type, $number] = self::identity($customer);
        $line = $customer->optionalText('address', 200);

        return (new Client)
            ->setTipoDoc($type)
            ->setNumDoc($number)
            ->setRznSocial($customer->text('name', 1500))
            ->setAddress($line === null ? null : (new Address)->setDireccion($line)->setCodigoPais('PE'));
    }

    /** @return array{string, string} Catálogo 06 type and document number */
    public static function identity(Input $party): array
    {
        $type = $party->oneOf('doc_type', self::IDENTITY_TYPES);
        $pattern = match ($type) {
            '1' => '\d{8}',
            '6' => '\d{11}',
            '4', '7' => '[A-Za-z0-9]{1,12}',
            default => '[A-Za-z0-9-]{1,15}',
        };
        $number = $party->code('doc_number', $pattern, 'Número no válido para el tipo de documento.');

        if ($type === '6' && ! RucNumber::isValid($number)) {
            throw InvalidInput::shape($party->pointer('doc_number'), 'El dígito verificador del RUC no es válido.');
        }

        return [$type, $number];
    }
}
