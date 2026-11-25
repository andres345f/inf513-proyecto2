# FEDER Web · Proyecto 2 de INF513

Aplicación web para comercializar productos de comunidades y productores afiliados a FEDER. Incluye catálogo público y gestión por roles (cliente, productor, personal y administración): sigue una venta desde la reserva hasta el cobro, la entrega, la factura y la devolución. Datos ficticios de demostración.

## Requisitos
- PHP 8.2+ con `pdo_pgsql`, `gd`, `intl`, `mbstring`, `xml` · Composer 2
- PostgreSQL en marcha
- Node.js 20+ con npm

## 1. Base de datos
Crea una base PostgreSQL vacía y carga, en orden, el esquema y los datos (scripts SQL de la entrega):

```sh
psql -U postgres -d feder_p2_demo -v ON_ERROR_STOP=1 \
  -f db/01_schema.sql -f db/02_datos_dominio.sql -f db/03_datos_web.sql
```

## 2. Backend (Laravel)

```sh
cd backend
cp .env.example .env        # edita DB_DATABASE, DB_USERNAME y DB_PASSWORD
composer install
php artisan key:generate
php artisan feder:restablecer-contrasenas --solo-demostracion --confirmar
```

## 3. Frontend (React)

```sh
cd ../frontend
npm ci
npm run build:laravel
```

## 4. Iniciar
Desde `backend/`:

```sh
php artisan serve --host=127.0.0.1 --port=8000
```

App: http://127.0.0.1:8000 · API: http://127.0.0.1:8000/api/ping

## Estructura
| Directorio | Contenido |
|---|---|
| `backend/` | API Laravel: servicios, autenticación y tareas |
| `frontend/` | React: vistas, estilos e imágenes del catálogo |
| `docs/` | Manual de usuario, documentación técnica y guía |

---
**Grupo 29 · INF513 · FICCT – UAGRM**
