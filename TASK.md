# TASK.md — vertical activo

Un vertical por sesión. Un criterio sin test (o sin evidencia beta, cuando
hay red) no cuenta como hecho. Ley: `docs/SPEC.md`. Hechos: `docs/STATUS.md`.

| Campo                | Valor                                            |
| -------------------- | ------------------------------------------------ |
| Fase                 | **Fase 1 cerrada** (plan V2)                     |
| Siguiente            | Fase 2: base del core (repo Rust, no este)       |
| Última actualización | 2026-10-22                                       |

## Fase 1 — motor SEE (cerrada)

**Frase:** un JSON de factura entra, sale el XML firmado, y **ese mismo
XML** recibe CDR 0 en beta. Lo mismo para boleta → RC → ticket, NC 07, baja
RA y consulta de CDR.

- [x] Contrato v1 estricto (`Input`, JSON Pointer, montos como string).
- [x] cpe.v1 → Greenter: 01 contado/crédito, 03 mixta 10/20/30, 07.
- [x] summary.v1 → RC (con NC de boleta) y RA.
- [x] Firma única + auditoría de montos + verificación de firma al enviar.
- [x] Cliente SOAP propio: TLS verificado, Clave SOL escapada, faults
      clasificados sin `(int)`.
- [x] Goldens deterministas de los 6 ejemplos.
- [x] Beta real: 01, 07, RC y RA con CDR 0 (ver `STATUS.md`).

## Cola del motor (se abre cuando el core la necesite)

| Item                    | Por qué / cuándo                                                                                         |
| ----------------------- | -------------------------------------------------------------------------------------------------------- |
| ~~`.pfx` → PEM~~        | Hecho 2026-10-29: `/v1/certificates/import`, `deploy/openssl.cnf` (provider `legacy`), probado con un `.pfx` moderno y uno RC2-40 hechos del certificado de prueba. Falta abrir uno real de una CA peruana (con el primer emisor). |
| ~~Nombre del adquirente en RC~~ | Hecho 2026-11-02, necesario para core F10: `customer.name` → `RegistrationName` antes de firmar (RS 000048-2026, anexos VII/X); golden revisado, 73 tests / 450 aserciones. XML nuevo contra SUNAT: sin verificar. |
| Endpoint por emisor     | OSE para PRICOS ≥ 300 UIT (v1.x): `target{ose_url}` validado.                                            |
| GRE remitente `09`      | Fase 6: REST + OAuth; el token lo guarda el core y viaja por request.                                    |
| Smoke de producción     | Fase 3: con emisor de tercera categoría real, fuera de CI.                                               |
