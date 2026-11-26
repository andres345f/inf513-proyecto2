# Corrección del mapa del delivery — resuelta

Estado verificado el 26 de noviembre de 2026.

## Resultado

El mapa de **Checkout → Envío a domicilio** muestra correctamente las calles de
OpenStreetMap en producción:

`https://mail.tecnoweb.org.bo/inf513/grupo29sa/proyecto2/`

La corrección permanente está en la rama `main` del repositorio. El workflow
**Desplegar a TecnoWeb (FTP)** quedó desactivado manualmente para evitar que un
push sobrescriba el servidor de forma automática.

## Causas encontradas

El problema tuvo tres partes:

1. Leaflet podía calcular el tamaño mientras el paso del checkout aún se estaba
   mostrando. Se agregó `map.invalidateSize()` después de montar el componente.
2. La CSP de Apache solo permitía `https://*.tile.openstreetmap.org`, pero el
   mapa usa el dominio exacto `https://tile.openstreetmap.org`. El resultado era
   un mapa gris sin imágenes.
3. `Referrer-Policy: same-origin` impedía enviar un `Referer` a OpenStreetMap.
   Sus servidores rechazaban las tiles con `403 Access blocked`. Se cambió a
   `strict-origin-when-cross-origin`, que envía únicamente el origen y no la ruta
   completa del checkout.

La configuración de `backend/public/.htaccess` era especialmente importante:
Apache la aplicaba también a los archivos estáticos y sobrescribía la cabecera
generada por el middleware de Laravel.

## Archivos que deben conservar esta configuración

- `frontend/src/components/comercio/MapaUbicacion.jsx`
  - URL: `https://tile.openstreetmap.org/{z}/{x}/{y}.png`
  - recálculo con `invalidateSize()` después del montaje.
- `backend/app/Http/Middleware/AgregarCabecerasSeguridad.php`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `img-src` permite `https://tile.openstreetmap.org`.
- `backend/public/.htaccess`
  - debe mantener las mismas dos reglas anteriores porque sirve el frontend
    estático directamente mediante Apache.
- `backend/tests/Unit/CabecerasSeguridadTest.php`
  - verifica que el middleware y `.htaccess` no vuelvan a divergir.
- `backend/tests/Feature/EstadoSistemaTest.php`
  - verifica la cabecera HTTP entregada por Laravel.

## Verificación rápida

En el navegador:

1. Abrir el checkout y seleccionar **Envío a domicilio**.
2. Confirmar que se muestran las calles.
3. En Network, comprobar que las peticiones a
   `tile.openstreetmap.org/{z}/{x}/{y}.png` responden `200`.

Desde una terminal externa:

```bash
curl -sI https://mail.tecnoweb.org.bo/inf513/grupo29sa/proyecto2/app/ \
  | grep -Ei 'content-security-policy|referrer-policy'
```

La salida debe incluir:

```text
Referrer-Policy: strict-origin-when-cross-origin
https://tile.openstreetmap.org
```

## Despliegues futuros

- Compilar y copiar solamente `public/app` no modifica `.htaccess` ni estas
  cabeceras.
- Un despliegue completo debe tomar los archivos actuales de `main`; no se debe
  restaurar una copia antigua del backend.
- No reactivar el workflow FTP hasta revisarlo y probar que conserva
  `bootstrap/`, `.env`, `vendor/`, `storage/` y la configuración del servidor.
- Limpiar caché u OPcache no desconfigura el mapa; el riesgo es sobrescribir los
  archivos corregidos con versiones antiguas.
