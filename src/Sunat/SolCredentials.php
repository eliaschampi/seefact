<?php

declare(strict_types=1);

namespace See\Sunat;

use See\Input\Input;
use See\Input\InvalidInput;
use SensitiveParameter;

/**
 * Clave SOL of the issuer (ideally a secondary SOL user limited to CPE
 * sending). Held for one request; never logged, never echoed.
 */
final readonly class SolCredentials
{
    public function __construct(
        public string $ruc,
        public string $user,
        #[SensitiveParameter] public string $password,
    ) {}

    public static function from(Input $sol): self
    {
        $sol->only('ruc', 'user', 'password');
        $password = $sol->secret('password', 100);

        if (preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            throw InvalidInput::shape($sol->pointer('password'), 'Contiene caracteres de control.');
        }

        return new self(
            $sol->code('ruc', '\d{11}', 'El RUC tiene 11 dígitos.'),
            $sol->code('user', '[A-Za-z0-9]{1,30}', 'Usuario SOL alfanumérico.'),
            $password,
        );
    }

    /** SUNAT's SOAP username is the RUC followed by the SOL user. */
    public function username(): string
    {
        return $this->ruc.$this->user;
    }
}
