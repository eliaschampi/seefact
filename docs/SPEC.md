# see-php — especificación

Ley permanente del motor SEE. Hechos verificados: [`STATUS.md`](STATUS.md).
Contrato de cable: [`CONTRACT.md`](CONTRACT.md). Ejecución: `TASK.md`.

El código manda sobre este archivo. Este archivo manda sobre `TASK.md`.

---

## 1. Producto

see-php es el motor SEE de V2, sin estado. Recibe un comprobante ya
calculado y devuelve su XML UBL 2.1 firmado. Recibe ese XML firmado y lo
transporta a SUNAT. Consulta tickets y CDR. Tiene un solo llamador de
confianza: el core (Rust), por socket local.

**Es:** mapper `cpe.v1`/`summary.v1` → Greenter, firma XMLDSig, SOAP
`billService`/`billConsultService`, lectura de CDR, clasificación honesta
de cada respuesta y la conversión única de un `.pfx` al PEM con que firma.

**No es:** dueño de correlativos, estados, reintentos, colas,
almacenamiento, credenciales persistidas, IGV, PDF, tenants ni API
pública. Todo eso vive en el core.

## 2. Frontera con el core (no negociable)

| core (Rust)                                                  | see-php                                                   |
| ------------------------------------------------------------ | --------------------------------------------------------- |
| tenants, emisores, credenciales cifradas                     | recibe certificado y Clave SOL por request; no guarda     |
| sella el PEM que recibe al importar el `.pfx`                | abre el `.pfx` una vez y devuelve el PEM; no guarda       |
| `TaxMath`: único dueño del IGV y del redondeo                | verifica que cada total sea la suma exacta de sus líneas  |
| correlativo (`FOR UPDATE`), máquina CPE, idempotencia        | —                                                         |
| persistir XML firmado + sha256 (write-once), CDR, tickets    | devuelve bytes finales y su sha256                        |
| cola, reintentos, RC diario, plazos, consulta tras timeout   | ejecuta una llamada SOAP por request                      |
| interpretar códigos SUNAT (aceptado, observado, rechazado)   | devuelve los códigos tal cual, como string                |

Reglas:

1. **Firmar una vez.** `/sign` devuelve los bytes finales. `/send` sólo
   acepta XML firmado, verifica la firma y deriva el nombre SUNAT del
   propio XML. No existe "regenerar"; `$see->send($model)` está prohibido.
2. **Sin estado.** Sin base de datos, sin colas y sin disco de negocio (el
   único disco es la caché de plantillas Twig compiladas). Los secretos
   viven en memoria durante un request, con `#[SensitiveParameter]`, y
   nunca llegan al log.
3. **Códigos como texto.** Nunca `(int)` sobre un código SUNAT. Toda
   respuesta es exactamente una de: `cdr`, `ticket`, `status`, `consult`,
   `fault`, `transport_error` o `invalid_response`
   ([CONTRACT §3](CONTRACT.md)).
4. **Sólo un fault con código SUNAT es veredicto.** Un fault cuenta como
   respuesta de SUNAT únicamente si trae un código de 4 dígitos en
   `faultcode` (`soap-env:Client.0111`) o si su `faultstring` es exactamente
   ese código. Lo demás (HTTP, TLS, DNS, HTML) es `transport_error`: SUNAT
   pudo o no recibir el documento.
5. **El HTTP status describe el trabajo del servicio.** El veredicto de
   SUNAT viaja en el cuerpo: un rechazo es un 200 con su código.
6. **TLS siempre verificado.** No hay interruptor inseguro.
7. **Dinero como texto decimal**, con ≤ 15 dígitos significativos (exacto
   en un double). Además, el XML renderizado se audita nodo por nodo contra
   lo que declaró el mapper.
8. **El texto se protege según el contexto XML donde cae.** Dentro de CDATA
   se rechazan `]]>` y los caracteres de control. Fuera de CDATA se escapa.

## 3. Stack y forma

| Capa     | Pin                                                                    |
| -------- | ---------------------------------------------------------------------- |
| PHP      | `^8.5` (producción 8.5.12) · ext dom, libxml, openssl, soap, zlib      |
| SEE      | `greenter/lite` 5.3 (modelos, plantillas Twig, firma, WSDL, zip, CDR)  |
| Decimal  | `brick/math` 0.18                                                      |
| Runtime  | PHP-FPM detrás de Nginx en socket unix. Sin framework.                 |
| Calidad  | PHPUnit 12 · PHPStan `max` · Pint (preset laravel + `strict_types`)    |

```
public/index.php      front controller (FPM)
bin/see               diagnose · smoke:beta (operador; nunca CI)
src/Kernel.php        rutas, auth, sobre de errores, log
src/Input/            lector estricto con JSON Pointer
src/Cpe/              mappers, certificado, firma, auditoría, identidad del XML
src/Sunat/            entornos, Clave SOL, WS-Security, cliente SOAP, Reply
docs/examples/        ejemplos del contrato = fixtures de los goldens
tests/Golden/         XML firmado byte a byte de cada ejemplo
```

- `declare(strict_types=1)` y clases `final`/`readonly`. Esto es distinto
  de Aeduca/fact: aquí no hay runtime Laravel que respetar, y la coerción
  escalar es justo el bug que este motor debe impedir.
- Identificadores en inglés; mensajes al operador en español.
- Greenter se importa sólo en `src/Cpe` y `src/Sunat`.

### Lo que se corrige de Greenter

| Greenter 5.3                                                        | see-php                                                       |
| ------------------------------------------------------------------- | ------------------------------------------------------------- |
| `SoapClient` por defecto: `verify_peer` en `false`                  | TLS verificado                                                |
| `WSSESecurityHeader` interpola la contraseña sin escapar            | `WsSecurityHeader` escapa                                     |
| `getErrorFromFault` toma dígitos de cualquier texto                 | regla 4                                                       |
| `ExtService`/`ConsultCdrService` hacen `(int)` del código           | códigos como texto                                            |
| `BillSender` convierte un CDR vacío en un `Error` genérico          | `invalid_response` con los bytes como evidencia               |
| plantillas con `autoescape` apagado                                 | `Input` + `Xml::escape` según el contexto                     |
| modelos con `float`; la impresión depende de `precision`            | dígitos acotados + `precision=-1` local + auditoría           |
| la plantilla de NC no imprime `LineExtensionAmount`                 | la auditoría exige sólo lo que se imprime                     |

Se usan sus modelos, plantillas, firma, WSDL locales, `ZipFly` y
`DomCdrReader`. Sus senders no se usan.

## 4. SUNAT — conocimiento para el core

Fuente original: `fact/docs/SPEC.md` §3 (verificado el 2026-08-23),
ampliada con las verificaciones en beta de [`STATUS.md`](STATUS.md).

**SEE del Contribuyente.** SUNAT autentica al **emisor** (RUC, Clave SOL y
certificado), no al programador. Para producción, el emisor necesita:
calidad de emisor electrónico, RUC activo y habido, y renta de tercera
categoría. Un RUC NRUS no sirve de laboratorio: no emite factura y su
boleta va por SEE-SOL.

**Modelo legal de V2.** Si el software firma con su propio certificado en
nombre de terceros, es un PSE: requiere registro ante SUNAT (RS
199-2015/SUNAT; homologación eliminada en 2022; ISO 27001 por confirmar).
Por eso V2 usa el modelo A: cada emisor sube su propio certificado y crea un
usuario secundario SOL con perfil mínimo de envío. Validarlo con un
tributarista antes de cobrar. Los PRICOS con ingresos ≥ 300 UIT emiten vía
OSE (RS 044-2019): de ahí el endpoint por emisor de v1.x.

**Laboratorio.** Beta: usuario `{RUC}MODDATOS`, clave `MODDATOS`; acepta
certificados no registrados; no tiene efecto tributario; no cubre GRE.
Homologación es otro host y no se usa.

| Uso                | URL                                                                    |
| ------------------ | ---------------------------------------------------------------------- |
| CPE beta           | `https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService`         |
| CPE producción     | `https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService`           |
| Consulta de CDR    | `https://e-factura.sunat.gob.pe/ol-it-wsconscpegem/billConsultService` |

**Alcance v1**

| Cat. | Uso                    | Valores                                                     |
| ---- | ---------------------- | ----------------------------------------------------------- |
| 01   | tipo de documento      | `01` `03` `07`; resumen `RC`, baja `RA`                     |
| 06   | identidad              | `0` `1` (DNI) `4` (CE) `6` (RUC con dígito verificador) `7` |
| 07   | afectación IGV         | `10` `20` `30`                                              |
| 09   | motivo de NC           | `01` `02` `06` `07` `09` (estructura de líneas estándar)    |
| 51   | tipo de operación      | `0101`                                                      |
| 03   | unidad                 | 2–3 alfanuméricos (`NIU`, `ZZ`, `KGM`…)                     |
| 52   | leyendas               | opcionales; 4 dígitos (`1000` = monto en letras)            |

Moneda `PEN` o `USD`. Factura: adquirente con RUC y forma de pago
obligatoria (contado, o crédito con cuotas que vencen después de la
emisión). Una NC usa la letra de serie del comprobante que corrige.

**Nombres.** `{ruc}-{tipo}-{serie}-{número}`. Resúmenes:
`{ruc}-{RC|RA}-{Ymd}-{n}`, donde `Ymd` es la fecha en que se genera el
resumen. `reference_date` es la fecha de emisión de los documentos que
informa.

**Lo que el core hace con cada respuesta**

| Outcome / código                             | Significado                                   | El core…                                      |
| -------------------------------------------- | --------------------------------------------- | --------------------------------------------- |
| `cdr` `0` sin notas                          | aceptado                                      | `accepted`                                    |
| `cdr` `0` con notas, o `≥ 4000`              | aceptado con observaciones                    | `accepted_with_observations`                  |
| `cdr` 2000–3999                              | rechazado; el correlativo queda usado         | `rejected`; corregir con otro número          |
| `fault` 0100–1999                            | excepción: SUNAT no lo registró               | corregir la causa; reenviar el **mismo** XML  |
| `fault` 2000–3999                            | rechazo                                       | `rejected`                                    |
| `transport_error` / `invalid_response`       | resultado desconocido                         | `unknown` → consultar CDR antes de reenviar   |
| `status` `98` / `0` / `99` / otro            | en proceso / terminó / con errores / error    | reencolar / leer CDR / leer CDR / `unknown`   |

Nunca se toma un segundo correlativo por un timeout. Antes de producción,
contrastar estos rangos con la guía SUNAT vigente.

**Reglas aprendidas en beta.** `2992`: cada línea del RC exige la tasa del
IGV (`cbc:Percent`), aunque la boleta no tenga operaciones gravadas. Por eso
`igv_percent` es obligatorio en `summary.v1`.

**RC vigente desde agosto de 2026.** La [RS 000048-2026/SUNAT,
anexos VII y X](https://www.sunat.gob.pe/legislacion/superin/2026/anexo-000048-2026.pdf)
añade el nombre del adquirente cuando exista en la boleta (campo 9-A,
`cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName`,
hasta 250 caracteres). `summary.v1` lo recibe en `customer.name`; no se
infiere de su tipo de documento. Greenter 5.3 carece de ese campo:
`SummaryMapper` completa su DOM antes de firmarlo, sin modificar el XML ya
firmado ni duplicar la plantilla.

**Plazos (los configura el core, no son constantes).** Factura: 3 días
(R.S. 000003-2023/SUNAT). RC: hasta el 7.º día (RS 000048-2026/SUNAT).
Contrastar antes de producción.

## 5. Calidad

`composer check` = `pint --test` + PHPStan `max` + PHPUnit (`failOnWarning`,
`failOnDeprecation` para código propio). Los goldens son deterministas:
RSA PKCS#1 v1.5 firma igual con la misma entrada, así que un diff significa
que el CPE cambió; se regeneran a propósito con `UPDATE_GOLDEN=1`. CI nunca
llama a SUNAT; `bin/see smoke:beta` es manual.
