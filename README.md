# see-php

Motor SEE (SUNAT) sin estado para V2. Firma y transporta comprobantes
electrónicos (`01`, `03`, `07`, RC, RA) para un core de confianza escrito en
Rust. Es la única pieza PHP del sistema; existe porque Greenter (UBL 2.1,
XMLDSig, SOAP) es el ecosistema maduro para SUNAT.

**Stack:** PHP 8.5 · Greenter `lite` 5.3 · brick/math · PHP-FPM · sin framework

| Fuente             | Autoridad                                     |
| ------------------ | --------------------------------------------- |
| `AGENTS.md`        | Protocolo de ejecución                        |
| `docs/SPEC.md`     | Ley: frontera con el core, invariantes, SUNAT |
| `docs/CONTRACT.md` | Contrato de cable v1                          |
| `docs/STATUS.md`   | Hechos verificados                            |
| `TASK.md`          | Siguiente vertical                            |

Origen: el conocimiento SUNAT de `~/Documents/fact` (NecoFact, `docs/SPEC.md`
§3 y `app/Support/Sunat`), separado aquí del producto.

## Arranque

```bash
composer install
export SEE_API_TOKEN=$(openssl rand -hex 24)
composer serve          # http://127.0.0.1:8090 (servidor embebido, sólo dev)
composer check          # pint --test · phpstan max · phpunit
bin/see diagnose        # runtime y configuración, sin red
bin/see smoke:beta      # aceptación con red real contra SUNAT beta (manual)
```

```bash
curl -s http://127.0.0.1:8090/v1/health
# {"status":"ok","contract":"v1"}
```

## Configuración (entorno)

| Variable                   | Default            | Uso                                              |
| -------------------------- | ------------------ | ------------------------------------------------ |
| `SEE_API_TOKEN`            | —                  | Bearer del core, mínimo 32 caracteres; sin él, 503 |
| `SEE_TWIG_CACHE`           | `var/cache/twig`   | Plantillas compiladas; vacío la desactiva        |
| `SEE_SOAP_CONNECT_TIMEOUT` | `10`               | Segundos para conectar con SUNAT                 |
| `SEE_SOAP_TIMEOUT`         | `45`               | Segundos esperando la respuesta SOAP             |
| `SEE_MAX_BODY_BYTES`       | `8388608`          | Tope del cuerpo JSON                             |
| `SEE_BETA_RUC`             | `20000000001`      | Sólo `smoke:beta`                                |
| `SEE_BETA_PASSWORD`        | `MODDATOS`         | Sólo `smoke:beta`                                |

## Producción

PHP-FPM con un pool dedicado detrás de Nginx, escuchando en un socket unix
que sólo alcanza el core: [`deploy/php-fpm.conf`](deploy/php-fpm.conf) y
[`deploy/nginx.conf`](deploy/nginx.conf). Requisitos:

- `opcache.enable=1` y `opcache.validate_timestamps=0`, con releases
  inmutables (el mismo patrón de `docs/.deploy` en Faztore y Coedula).
- `openssl.cafile` apuntando al bundle de CA del sistema. TLS no se puede
  desactivar.
- `composer install --no-dev --classmap-authoritative`.
