# see-php — instrucciones para cualquier agente (léelas enteras)

Motor SEE (SUNAT) **sin estado** de V2. Firma y transporta comprobantes para
un único llamador de confianza: el core en Rust (`~/Documents/core-rs`). Es
la única pieza PHP del sistema. Aceptado contra SUNAT beta el 2026-10-22:
factura (01), nota de crédito (07), resumen diario (RC) y comunicación de
baja (RA), todos con CDR 0.

## Al empezar

1. Lee `AGENTS.md`, `docs/SPEC.md` (la ley), `docs/CONTRACT.md` (el cable) y
   `TASK.md` (la cola: convertir `.pfx` → PEM, endpoint OSE por emisor, GRE).
2. Antes de tocar plantillas, la firma o el transporte, lee el código real de
   Greenter en `vendor/greenter/`. Su comportamiento manda, no su
   documentación.

## Decisiones que NO se revierten

- **Sin estado**: nada de base de datos, colas ni disco de negocio. Los
  correlativos, los estados, los reintentos y el almacenamiento son del core.
- **Firmar una sola vez**: `/sign` devuelve los bytes finales. `/send` sólo
  acepta un XML firmado, verifica la firma y toma el nombre del propio XML.
  Nunca se regenera un XML (`$see->send($model)` está prohibido).
- **Códigos SUNAT como texto**, nunca `(int)`. Sólo un fault con código de 4
  dígitos es veredicto de SUNAT; lo demás es `transport_error`, y el core debe
  consultar antes de reenviar.
- **Transporte propio**, no los senders de Greenter: con ellos TLS no se
  verifica, la Clave SOL no se escapa y los dígitos se sacan de cualquier
  texto (ver la tabla de `SPEC.md` §3).
- **Montos como texto**, con ≤ 15 dígitos significativos. Cada monto impreso
  se audita contra el mapper; el texto se escapa según el contexto XML donde
  cae.
- **Secretos** (certificado, Clave SOL) sólo en memoria, con
  `#[SensitiveParameter]`, nunca en logs.

## Prohibido

Agregar un framework, una base de datos o estado; debilitar o borrar tests o
goldens para que pasen (un golden se regenera a propósito, con
`UPDATE_GOLDEN=1`, y su diff se revisa como un cambio fiscal); llamar a SUNAT
desde CI o desde los tests; ampliar el alcance sin un ítem en `TASK.md`.

## Checks

`composer check` (pint, PHPStan nivel `max`, PHPUnit) en verde antes de cada
commit. La aceptación con red es manual: `bin/see smoke:beta`, que va a beta,
sin efecto tributario. Un commit por ítem; si algo choca con `SPEC.md`,
detente y pregunta al usuario.
