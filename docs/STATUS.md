# see-php — estado verificado

Sólo hechos que demuestran el código, los tests o la red. Ley:
[`SPEC.md`](SPEC.md). Contrato: [`CONTRACT.md`](CONTRACT.md).

**Última verificación:** 2026-11-19 · PHP 8.5.12 (macOS) · Greenter 5.3.0.

## Checks

`composer check` en verde: Pint, PHPStan nivel `max` (0 errores, incluidos
`bin/see` y `public/index.php`), PHPUnit con 76 tests / 460 aserciones, con
`failOnWarning` y `failOnDeprecation` activos para el código propio
(2026-11-19, PHP 8.5.12 con OpenSSL 4.1.0 y libxml 2.9.13).

## SUNAT beta, con red real (2026-10-22)

Corrida `bin/see smoke:beta`: RUC `20000000001`, `MODDATOS`, certificado
efímero autofirmado.

| Documento                     | Resultado                                                            |
| ----------------------------- | -------------------------------------------------------------------- |
| 01 `F001-2645698`             | `cdr 0` — La Factura numero F001-2645698, ha sido aceptada           |
| 07 `FC01-2645698`             | `cdr 0` — La Nota de Credito numero FC01-2645698, ha sido aceptada   |
| RC `RC-20261022-1` (03 + 07)  | ticket → `0` · `cdr 0` — El Resumen diario … ha sido aceptado        |
| RA `RA-20261022-1` (01)       | ticket → `0` · `cdr 0` — La Comunicacion de baja … ha sido aceptada  |

El primer RC fue rechazado con `fault 2992` ("Tasa del tributo faltante para
código 1000", línea 2). Desde entonces `igv_percent` es obligatorio por
línea y tiene test. La clasificación funcionó como se diseñó:
`soap-env:Client.2992` llegó como `fault`, con código y mensaje intactos.

**2026-11-18 (23:59, Lima).** 01 `F001-9335832` y 07 `FC01-9335832`:
`cdr 0`, aceptadas. RC y RA recibieron ticket, pero `getStatus` agotó los
45 s de lectura (`transport_error`). La causa estaba en SUNAT: el mismo
`getStatus` por `curl` respondió HTTP 500 a los 63,7 s con
`soap-env:Server.200` ("Failed to establish a backside connection"). RC y
RA con el código actual: sin verificar contra SUNAT.

## HTTP real

`php -S` + `public/index.php`: health 200 con `X-Request-Id` y
`Cache-Control: no-store`. Una firma 01 por HTTP tardó 70 ms en frío (sin
opcache) y salió idéntica byte a byte a su golden (2026-11-19: 68 ms, de
nuevo idéntica). Con token incorrecto responde 401; con un campo faltante,
400 con su JSON Pointer; con U+FFFF en una descripción, 400 en ese campo.

Volumen (CLI, sin opcache, 3 corridas, 2026-11-18): firmar una factura de
1000 líneas tarda 174–177 ms (antes 232–234) y un RC de 500 líneas 60–62 ms
(antes 80–82); el `/send` de esa factura, hasta antes de SUNAT, 63 ms
(antes 72–73). Los bytes firmados no cambiaron.

## Implementado

| Área         | Hecho                                                                                                                       |
| ------------ | --------------------------------------------------------------------------------------------------------------------------- |
| Contrato     | `Input` estricto: claves desconocidas rechazadas, montos como string con ≤ 15 dígitos, RFC 3339 → Lima, JSON Pointer         |
| Documentos   | 01 contado y crédito con cuotas, 03 con 10/20/30 y cantidad decimal, 07 con referencia y Cat. 09 v1                          |
| Resúmenes    | RC (03 y su NC, estados 1/2/3) y RA (01/07)                                                                                 |
| Identidad    | RUC con dígito verificador en emisor y adquirente; Cat. 06 `0 1 4 6 7`                                                      |
| Firma        | Certificado validado (RSA, clave↔cert, vigencia); firma única; DigestValue; sha256                                           |
| Auditoría    | cada monto impreso y cada conteo de líneas se compara con el mapper sobre el XML firmado, recorriendo las líneas una vez; un monto, una línea o un total que el XML no imprime se rechaza (test) |
| Envío        | XML bien formado, de la ruta, firmado y **verificado** sobre el mismo DOM que se parseó (un solo parseo); nombre derivado del XML; RUC SOL = emisor |
| Transporte   | WSDL locales de Greenter; TLS verificado; WS-Security escapado; timeouts de conexión y lectura                               |
| Respuestas   | `cdr` · `ticket` · `status` · `consult` · `fault` (sólo código de 4 dígitos) · `transport_error` · `invalid_response` con evidencia |
| Certificado  | `.pfx` moderno (AES) y heredado (RC2-40) → el mismo PEM; contraseña errada → `pkcs12_password`; sin el provider `legacy`, un RC2-40 falla ("unsupported") y `bin/see diagnose` lo marca INACTIVO; con `deploy/openssl.cnf`, se abre. El PEM importado firma |
| Resumen RC | `customer.name` opcional (hasta 250), exigido por el llamador cuando figure en la BVE (RS 000048-2026, anexos VII/X). El golden lleva el nombre y `&` escapado en `RegistrationName`; sólo cambiaron esos dos subárboles y XMLDSig, no los montos. Test de firma con `CLIENTES VARIOS` y una línea sin nombre; 251 caracteres y la secuencia `]]>` se rechazan en `/lines/0/customer/name`. Nuevo XML contra SUNAT beta/producción: sin verificar. |
| Operación    | log JSON por request (sin cuerpos ni secretos, probado), `X-Request-Id`, `bin/see diagnose`                                  |

## No implementado

Endpoint OSE por emisor, GRE `09`, consulta de CDR contra
producción real (probada sólo con dobles; beta no la ofrece), despliegue en
Debian (las plantillas de `deploy/` no se han ejecutado) y smoke con un
emisor de tercera categoría. Orden: `TASK.md`.
