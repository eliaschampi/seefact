# see-php — contrato v1

JSON sobre HTTP, sólo desde el core, por socket local. Leyes del contrato:
[`SPEC.md`](SPEC.md) §2.

## 1. Transporte

- `Authorization: Bearer <SEE_API_TOKEN>` (mínimo 32 caracteres) en todo,
  salvo `GET /v1/health`.
- `Content-Type: application/json`. Cuerpo máximo: `SEE_MAX_BODY_BYTES`
  (8 MiB por defecto).
- `X-Request-Id` opcional (`[A-Za-z0-9._-]{1,128}`). Se devuelve siempre y
  correlaciona el log.
- Montos y cantidades **siempre como string** decimal no negativo (un
  número JSON se rechaza). Fechas `YYYY-MM-DD`; instantes RFC 3339 con zona
  (`2026-10-22T10:15:00-05:00`), normalizados a America/Lima.
- Un campo desconocido es un error: no se ignora nada en silencio.

| Ruta                       | Hace                                              |
| -------------------------- | ------------------------------------------------- |
| `GET /v1/health`           | `{"status":"ok","contract":"v1"}`; 503 sin token  |
| `POST /v1/documents/sign`  | 01/03/07 → XML firmado                            |
| `POST /v1/documents/send`  | XML firmado → `sendBill` → CDR                    |
| `POST /v1/summaries/sign`  | RC/RA → XML firmado                               |
| `POST /v1/summaries/send`  | XML firmado → `sendSummary` → ticket              |
| `POST /v1/tickets/status`  | `getStatus(ticket)`                               |
| `POST /v1/cdr/status`      | `getStatusCdr` (sólo producción)                  |
| `POST /v1/certificates/import` | `.pfx`/`.p12` + contraseña → PEM sin contraseña |

## 2. Firmar

```json
{ "certificate": "-----BEGIN PRIVATE KEY-----…-----END CERTIFICATE-----", "document": { … } }
```

`certificate`: un PEM sin contraseña con la clave RSA y su certificado. Se
rechaza si la clave no corresponde al certificado o si está fuera de
vigencia. Para RC/RA, la clave del cuerpo es `summary` en lugar de
`document`.

Respuesta 200:

```json
{
    "filename": "20000000001-01-F001-1",
    "xml_base64": "…",
    "xml_sha256": "…",
    "digest_value": "…"
}
```

`digest_value` es el valor resumen (DigestValue) que lleva el QR de la
representación impresa. El core persiste `xml_base64` decodificado junto con
su sha256: esos bytes son el CPE.

### `document` (cpe.v1)

Ejemplos completos y verificados por los goldens:
[`document-01-cash`](examples/document-01-cash.json),
[`document-01-credit`](examples/document-01-credit.json),
[`document-03-mixed`](examples/document-03-mixed.json),
[`document-07`](examples/document-07.json).

| Campo            | Regla                                                                          |
| ---------------- | ------------------------------------------------------------------------------ |
| `type`           | `01` · `03` · `07`                                                             |
| `series`         | 01 `F…`, 03 `B…`; 07 lleva la letra del documento que corrige (4 caracteres)   |
| `number`         | entero 1…99 999 999                                                            |
| `issued_at`      | RFC 3339 con zona                                                              |
| `due_date`       | opcional (01/03), ≥ emisión                                                    |
| `currency`       | `PEN` · `USD`                                                                  |
| `operation_type` | 01/03: `0101`                                                                  |
| `issuer`         | `ruc`, `legal_name`, `trade_name?`, `address{establishment_code, ubigeo, department, province, district, urbanization?, line}` |
| `customer`       | `doc_type` (0, 1, 4, 6, 7), `doc_number`, `name`, `address?`. Una factura exige 6 |
| `payment`        | 01 obligatorio; 03 opcional; 07 no aplica. `{"method":"cash"}` o `{"method":"credit","installments":[{"amount","due_date"}]}` |
| `reference`      | sólo 07: `type` (01/03), `series`, `number`, `reason_code` (Cat. 09 v1), `reason` |
| `lines[]`        | 1…1000; ver abajo                                                              |
| `totals`         | `taxable`, `exempt`, `unaffected`, `igv`, `sale_value`, `total`               |
| `legends[]`      | opcional; `{code, value}`                                                      |

Una **línea** lleva `code?`, `sunat_code?` (UNSPSC de 8 dígitos),
`description` (admite `\n`), `unit_code`, `quantity`, `unit_value` (sin
IGV), `unit_price` (con IGV), `sale_value`, `tax_affectation` (10/20/30),
`igv_base`, `igv_percent` e `igv`.

Comprobaciones (exactas, al céntimo):

- `igv_base` = `sale_value` (v1 no tiene ISC ni descuentos).
- Una línea 10 tiene tasa > 0. Una línea 20/30 tiene `igv` 0 y
  `unit_price` = `unit_value`.
- `taxable` = Σ base de las líneas 10; `exempt` y `unaffected` = Σ valor de
  las líneas 20 y 30; `igv` = Σ IGV; `sale_value` = Σ valor de venta;
  `total` = `sale_value` + `igv`.
- Las cuotas suman ≤ `total` y cada una vence después de la emisión.

see-php no recalcula el IGV desde la tasa: eso es del core. Sí garantiza que
el documento es coherente y que el XML imprime exactamente estos montos.

### `summary` (summary.v1)

[`summary-rc`](examples/summary-rc.json) · [`summary-ra`](examples/summary-ra.json)

- **RC:** `number` (1…99 999 por día), `reference_date` (emisión de las
  boletas), `issue_date` (generación, ≥ `reference_date`), `currency`,
  `issuer{ruc, legal_name}` y `lines[]` (1…500). Cada línea lleva `type`
  (03/07), `id` (`B001-25`), `customer{doc_type, doc_number}`, `status`
  (1 adicionar · 2 modificar · 3 anular), `reference{type: "03", id}` (sólo
  si es 07), `totals{taxable, exempt, unaffected, igv, total}` e
  `igv_percent` (obligatorio, regla SUNAT 2992).
- **RA:** `number`, `reference_date`, `issue_date`, `issuer` y `lines[]`
  con `type` (01/07), `series` (`F…`), `number` y `reason` (≤ 100).

## 2b. Importar un certificado

```json
{ "pkcs12_base64": "…", "password": "…" }
```

El `.pfx`/`.p12` tal como lo entrega la entidad certificadora (≤ 64 KiB) y su
contraseña. Se abre una sola vez: el core sella el PEM que devuelve y lo
manda en cada `/sign`. Pasa las mismas comprobaciones que un `certificate`
de `/sign` (RSA, clave ↔ certificado, vigencia).

Respuesta 200:

```json
{
    "certificate": "-----BEGIN PRIVATE KEY-----…-----END CERTIFICATE-----",
    "subject": "/C=PE/O=…/CN=…",
    "not_after": "2046-10-17T08:19:41-05:00",
    "sha256": "…"
}
```

`subject` es el titular tal como lo imprime OpenSSL; `sha256`, la huella del
certificado en hex. Los `.pfx` protegidos con RC2-40 necesitan el provider
`legacy` de OpenSSL (`deploy/openssl.cnf`); si el servidor no lo tiene, la
respuesta es 500 y la causa va al log (`bin/see diagnose` lo avisa antes).

## 3. Enviar y consultar

```json
{ "environment": "beta", "sol": { "ruc": "20000000001", "user": "MODDATOS", "password": "…" }, "xml_base64": "…" }
```

Antes de llamar a SUNAT, el servicio exige que el XML esté bien formado,
sea del tipo que acepta la ruta, esté firmado y su firma verifique. También
exige que el RUC de la Clave SOL sea el del emisor del XML. Si un byte
cambió después de firmar, se responde `xml_signature_invalid`: así no se
quema el correlativo con un rechazo 2335.

- `/v1/tickets/status`: `{environment, sol, ticket}`.
- `/v1/cdr/status`: `{environment: "production", sol, document{ruc, type, series, number}}`.

Respuesta 200: siempre `outcome`, más los campos que le corresponden. Las
rutas de envío añaden `filename` y `xml_sha256`.

| `outcome`          | Campos                                                                          |
| ------------------ | ------------------------------------------------------------------------------- |
| `cdr`              | `cdr{code, description, notes[], reference_id, zip_base64}`                     |
| `ticket`           | `ticket`                                                                        |
| `status`           | `status_code`, `cdr` \| null, `cdr_error{message, raw_base64}` \| null          |
| `consult`          | `status_code`, `status_message`, `cdr` \| null, `cdr_error` \| null             |
| `fault`            | `fault{code, message, detail, faultcode}`                                       |
| `transport_error`  | `error{message}`                                                                |
| `invalid_response` | `error{message}`, `raw_base64` \| null                                          |

Qué hace el core con cada uno: [`SPEC.md`](SPEC.md) §4.

## 4. Errores del servicio

```json
{ "error": { "code": "inconsistent_amounts", "message": "No coincide con la suma de las líneas: se esperaba 31.24.", "pointer": "/document/totals/igv" } }
```

| HTTP | `code`                                                                                                                      |
| ---- | --------------------------------------------------------------------------------------------------------------------------- |
| 400  | `malformed_json`, `invalid_input`                                                                                           |
| 401  | `unauthorized`                                                                                                              |
| 404  | `not_found`                                                                                                                 |
| 405  | `method_not_allowed`                                                                                                        |
| 413  | `payload_too_large`                                                                                                         |
| 415  | `unsupported_media_type`                                                                                                    |
| 422  | `pkcs12_invalid`, `pkcs12_password`, `inconsistent_amounts`, `invoice_requires_ruc`, `invalid_due_date`, `invalid_summary_date`, `certificate_invalid`, `certificate_key_mismatch`, `certificate_expired`, `certificate_not_yet_valid`, `xml_invalid`, `xml_unsupported`, `xml_not_signed`, `xml_signature_invalid`, `sol_ruc_mismatch`, `consult_not_available` |
| 500  | `internal_error` (causa sólo en el log)                                                                                     |
| 503  | `not_configured`                                                                                                            |

Ningún 4xx funciona si se reenvía igual. Un 5xx no consumió nada en SUNAT,
salvo en `/send`, donde el core debe tratarlo como `unknown`.
