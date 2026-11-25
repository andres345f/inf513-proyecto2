# FEDER Web · Proyecto 2 de INF513

Aplicación académica del grupo 29 para comercializar productos de comunidades y productores. Incluye un catálogo público y cuatro contextos con sesión. Permite seguir una venta desde la reserva del lote hasta el cobro, la entrega, la factura y una posible devolución. Los datos distribuidos son ficticios.

Para revisar el sistema en pareja, empiece por la [guía de flujos y pendientes](docs/GUIA_FLUJOS_Y_PENDIENTES_FEDER_P2.md). Contiene cuentas de ejemplo, 14 recorridos, nueve comprobaciones negativas, tres casos contables y un acta en blanco. Para consultar una pantalla o profundizar en una regla, vea el [manual de usuario](docs/MANUAL_USUARIO.md) o la [documentación técnica](docs/DOCUMENTACION_TECNICA.md).

## Requisitos

- PHP 8.2 o posterior compatible con el lockfile, Composer 2 y extensiones habituales de Laravel, incluidas PDO con `pdo_pgsql`, GD con JPEG y PNG, Intl, Mbstring y XML. Las pruebas rápidas también utilizan `pdo_sqlite`.
- PostgreSQL con servidor local en marcha y herramientas `psql`, `createuser` y `createdb`. Las instrucciones se pueden seguir con PostgreSQL 18.
- Node.js 20 desde 20.19 o una versión compatible desde 22.12, con npm. Vite compila React y copia los recursos del catálogo y de la marca al directorio público de Laravel.

Descargue y extraiga el proyecto o clónelo con la URL HTTPS de este repositorio. Abra una terminal en la carpeta que contiene `backend/`, `frontend/` y `db/`. Los comandos siguientes usan el servidor local y el puerto convencional de PostgreSQL. Si su servicio escucha en otro puerto, cambie ese valor tanto en los comandos como en su configuración.

## 1. Crear una base exclusiva de demostración

Elija una base **nueva y vacía**. En el ejemplo se llama `feder_p2_demo` y su propietario es `feder_demo`. Ejecute los dos primeros comandos con el administrador **local** de PostgreSQL (sustituya `postgres` si utiliza otro nombre). `createuser` solicita una contraseña propia para el nuevo rol. Si ya tiene un rol local dedicado a esta práctica, puede usarlo como propietario y omitir el primer comando.

```sh
createuser -h 127.0.0.1 -p 5432 -U postgres --pwprompt feder_demo
createdb -h 127.0.0.1 -p 5432 -U postgres -O feder_demo feder_p2_demo
psql -X -h 127.0.0.1 -p 5432 -U feder_demo -d feder_p2_demo -c "SELECT current_database(), current_user, inet_server_port();"
```

Compruebe el nombre de base, el usuario y el puerto que devuelve la consulta. En esa base **recién creada** cargue, en este orden, el esquema aprobado, el catálogo ficticio y los datos web. Si `createdb` indica que la base ya existe, elija un nombre nuevo antes de continuar.

```sh
psql -X -h 127.0.0.1 -p 5432 -U feder_demo -d feder_p2_demo -v ON_ERROR_STOP=1 -f db/01_schema.sql -f db/02_datos_dominio.sql -f db/03_datos_web.sql
```

Para abrir una demostración ya preparada, continúe en **Iniciar la aplicación**. La carga SQL se hace una sola vez por base. Laravel trabaja con el esquema distribuido y bloquea las migraciones.

## 2. Configurar Laravel y las cuentas

Desde la raíz del proyecto:

```sh
cd backend
php -r "if (!file_exists('.env')) copy('.env.example', '.env');"
```

Edite `backend/.env`. Use `APP_ENV=local`, `APP_URL=http://127.0.0.1:8000`, `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1` y los valores de `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` de la base creada. Deje el correo en `MAIL_MAILER=log`. Para practicar con QR local, establezca `FEDER_QR_SIMULADO=true`. Los cobros y reintegros de esta demostración actualizan los estados de la aplicación. Use únicamente medios y datos ficticios.

Desde `backend/`, instale las dependencias fijadas, compruebe las extensiones, genere la clave de **esta instalación** e inicialice las contraseñas de la semilla:

```sh
composer install
composer check-platform-reqs
php artisan key:generate
php artisan feder:restablecer-contrasenas --solo-demostracion --confirmar
```

El último comando genera una contraseña aleatoria distinta por cuenta que conserve la marca inicial de demostración. Indica la ruta del archivo privado nuevo bajo `storage/app/private/credenciales/`. Consúltelo localmente, entregue a cada participante solo su clave y retire el archivo cuando termine la entrega. Las direcciones ficticias y los roles se encuentran en la [tabla de cuentas](docs/GUIA_FLUJOS_Y_PENDIENTES_FEDER_P2.md#roles). El dominio `.example` no recibe correo.

## 3. Compilar el frontend

Desde `backend/`, pase al proyecto React. `npm ci` utiliza el lockfile incluido.

```sh
cd ../frontend
npm ci
npm run build:laravel
```

La compilación integrada escribe en `backend/public/app/`. Se sirven desde Laravel la aplicación, los dos logos y los recursos de impresión. El navegador accede a la API del mismo origen.

## 4. Iniciar la aplicación

En una terminal abierta en `backend/`:

```sh
php artisan serve --host=127.0.0.1 --port=8000
```

En otra terminal, también en `backend/`, mantenga en marcha las tareas de vencimiento y abastecimiento:

```sh
php artisan schedule:list
php artisan schedule:work
```

Abra [FEDER en el navegador](http://127.0.0.1:8000/) y [el estado de la API](http://127.0.0.1:8000/api/ping). Si eligió otro puerto HTTP, adapte ambas direcciones y `APP_URL`. Detenga los procesos con Ctrl+C en sus propias terminales. Al volver a abrir la demostración, repita únicamente el arranque.

## 5. Comprobar la instalación

1. Sin sesión, abra **Catálogo**, busque «totaí» y revise una ficha. Cambie **Apariencia** y compruebe que la marca usa letras claras u oscuras según el fondo.
2. Entre con una cuenta ficticia multirrol. En el menú de la persona, abra **Trabajar como → Comprar como cliente**. Debe llegar al catálogo con el contexto Cliente y conservar la misma identidad.
3. Como titular de una factura ficticia ya emitida, abra **Mis facturas**, su detalle y **Imprimir o guardar PDF**. En el detalle de un pedido use **Imprimir resumen del pedido**. En el diálogo elija **Guardar como PDF** y compruebe que el logo conserva las letras oscuras sobre papel blanco, también si la web está en modo nocturno. Use la cuenta Cliente de la semilla con historial o prepare en esta base un pedido ficticio de la guía.
4. Para los recorridos que crean pedidos, cobran, devuelven o cambian permisos, utilice la base de aceptación y las personas distintas que indica la [guía](docs/GUIA_FLUJOS_Y_PENDIENTES_FEDER_P2.md#recorridos).

En `backend/`, con PDO SQLite habilitado, ejecute las pruebas unitarias y de característica. En `frontend/`, ejecute las pruebas de interfaz y el análisis estático:

```sh
php artisan test
```

```sh
npm test
npm run lint
```

### Integración con PostgreSQL

La suite de `backend/phpunit.integracion.xml` escribe datos y requiere **otro clúster local desechable** y una base distinta, `feder_p2_demo_test`. Cree esa base, compruebe su identidad y cargue los tres SQL en el orden del paso 1, cambiando tanto el puerto como el destino. Mantenga las credenciales en su estado de semilla para estas pruebas. Configure las variables exclusivamente en una terminal de pruebas, sin modificar la configuración de la demostración.

La suite comprueba que la copia de código carece de archivos de credenciales en `backend/storage/app/private/credenciales/`. Ejecútela en un checkout de pruebas limpio o retire el archivo después de entregar las claves de demostración. El rol de pruebas debe poder consultar `SHOW data_directory` en el servidor desechable para que la suite coteje el directorio físico. El administrador local de ese clúster puede otorgarle la lectura de configuración necesaria.

En PowerShell, sustituya los valores entre ángulos por los de **ese clúster de pruebas**. Use el rol de pruebas como propietario de su base.

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'pgsql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '<puerto del clúster desechable>'
$env:DB_DATABASE = 'feder_p2_demo_test'
$env:DB_USERNAME = '<rol del clúster desechable>'
$env:DB_PASSWORD = [System.Net.NetworkCredential]::new('', (Read-Host 'Contraseña del rol de pruebas' -AsSecureString)).Password
$env:FEDER_TEST_PGPORT = $env:DB_PORT
$env:FEDER_TEST_PGDATA = '<directorio de datos absoluto del clúster desechable>'
php vendor/bin/phpunit -c phpunit.integracion.xml
```

Ejecute el último comando en `backend/`. En otros intérpretes, exporte los mismos nombres de variables solo en la sesión de pruebas. Cierre esa terminal al terminar.

## Estructura

| Directorio | Contenido |
|---|---|
| `backend/` | API Laravel, servicios de dominio, autenticación, tareas programadas y pruebas PHP |
| `frontend/` | React, estilos, imágenes públicas del catálogo, logos, vistas imprimibles y pruebas de interfaz |
| `db/` | Esquema y datos ficticios de muestra |
| `docs/` | Manual de usuario, documentación técnica y guía de aceptación |

La instalación descrita es local. La conexión con servicios de pago, los trámites fiscales y la operación en un servidor institucional requieren sus propias validaciones. Consulte las [decisiones pendientes](docs/GUIA_FLUJOS_Y_PENDIENTES_FEDER_P2.md#pendientes).
