# see-php — protocolo de ejecución

Gobierna cómo se cambia el código. La ley vive en `docs/SPEC.md`; el cable,
en `docs/CONTRACT.md`.

## 1. Lectura obligatoria

1. `README.md`
2. `AGENTS.md`
3. `docs/SPEC.md`
4. `docs/CONTRACT.md`
5. `docs/STATUS.md`
6. `TASK.md`
7. el código y los tests actuales

Antes de tocar plantillas, firma o transporte, lee el código de Greenter en
`vendor/greenter/` (`xml/src/Xml/Templates`, `ws/src/Ws/Services`). Su
comportamiento real manda; su documentación no.

## 2. Restricciones

1. **Sin estado.** Nada de base de datos, colas, sesiones ni disco de
   negocio. Si algo necesita recordar, es del core.
2. **Firmar una vez.** Ninguna ruta regenera un XML ya firmado. `/send` sólo
   transporta bytes que se verifican antes de salir.
3. **Códigos SUNAT como texto.** Nunca `(int)` sobre un código. Interpretar
   un código es trabajo del core; aquí sólo se clasifica el *outcome*.
4. **Secretos.** Certificado y Clave SOL llevan `#[SensitiveParameter]`,
   viven un request y jamás se escriben en un log, un error ni el disco.
5. **Contrato estricto.** Todo campo pasa por `Input`: claves desconocidas
   rechazadas, montos como string, errores con JSON Pointer.
6. **Cada monto que se imprime se audita.** Un campo nuevo que imprima un
   monto declara su XPath en `Mapped`.
7. **Forma.** `declare(strict_types=1)`, clases `final`/`readonly`, sin
   framework, sin capas especulativas (repositorios, servicios genéricos,
   DTO que renombran arrays). Greenter sólo se importa en `src/Cpe` y
   `src/Sunat`.
8. **Copy.** Identificadores en inglés, mensajes en español.

## 3. Pruebas

- CI y `composer check` **nunca** llaman a SUNAT. Lo externo se prueba con
  el `SunatClient` falso de `tests/TestCase.php`.
- Un ejemplo nuevo del contrato va en `docs/examples/` y, con él, su golden.
  Los goldens se regeneran sólo a propósito (`UPDATE_GOLDEN=1 composer
  test`), y el diff se revisa como un cambio fiscal.
- Una regla aprendida de SUNAT (como el 2992) entra con su test, su fila en
  `SPEC.md` §4 y su evidencia en `STATUS.md`.
- `bin/see smoke:beta` es la aceptación con red. Va a beta (sin efecto
  tributario), es manual y su resultado se anota en `STATUS.md`.

## 4. Cierre

```bash
composer format
composer check     # pint --test · phpstan max · phpunit
```

No declarar nada terminado con un check en rojo. Después de un cambio de
contrato: actualizar `CONTRACT.md`, los ejemplos, los goldens y `STATUS.md`
en el mismo cambio.
