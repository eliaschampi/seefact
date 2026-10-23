<?php

declare(strict_types=1);

namespace See\Sunat;

/**
 * SUNAT SEE-Del Contribuyente hosts. Verified 2026-08-23 against
 * orientacion.sunat.gob.pe and Greenter 5.3 (see docs/SPEC.md).
 *
 * Homologación is a different host and is not offered: beta covers the
 * structural lab, production has tax effect. OSE hosts come with the
 * per-issuer endpoint contract (v1.x).
 */
enum Environment: string
{
    case Beta = 'beta';
    case Production = 'production';

    public function billService(): string
    {
        return match ($this) {
            self::Beta => 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService',
            self::Production => 'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService',
        };
    }

    /** The CDR consult service exists only in production. */
    public function consultService(): ?string
    {
        return match ($this) {
            self::Beta => null,
            self::Production => 'https://e-factura.sunat.gob.pe/ol-it-wsconscpegem/billConsultService',
        };
    }
}
