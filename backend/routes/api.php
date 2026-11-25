<?php

use App\Http\Controllers\Api\AdminPermisoController;
use App\Http\Controllers\Api\AdminUsuarioController;
use App\Http\Controllers\Api\AutenticacionController;
use App\Http\Controllers\Api\BusquedaController;
use App\Http\Controllers\Api\CarritoController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\ConsultaController;
use App\Http\Controllers\Api\DevolucionController;
use App\Http\Controllers\Api\EstadisticaController;
use App\Http\Controllers\Api\FacturaController;
use App\Http\Controllers\Api\GestionEntregaController;
use App\Http\Controllers\Api\GestionPagoController;
use App\Http\Controllers\Api\GestionPedidoController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\MetodoPagoController;
use App\Http\Controllers\Api\NotificacionController;
use App\Http\Controllers\Api\OperacionProductorController;
use App\Http\Controllers\Api\PagoController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\ProductorController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\SistemaController;
use App\Http\Controllers\Api\VisitaController;
use Illuminate\Support\Facades\Route;

/*
| API REST de FEDER Web. Prefijo: /api
| - auth:api      → exige un token de sesión válido.
| - permiso:xxx   → matriz de acceso según el rol activo (o VISITANTE sin sesión).
*/

Route::get('ping', [SistemaController::class, 'ping']);

// CU1 · Acceso
Route::prefix('auth')->controller(AutenticacionController::class)->group(function () {
    Route::post('login', 'login')->middleware('throttle:login');
    Route::post('registro', 'registro')->middleware('throttle:registro');

    Route::middleware('auth:api')->group(function () {
        Route::get('yo', 'yo');
        Route::post('rol-activo', 'cambiarRol');
        Route::post('logout', 'logout');
        Route::put('perfil', 'actualizarPerfil')->middleware('permiso:perfil.ver');
        // H-02: cambiar la propia contraseña. Solo exige sesión (no depende de la matriz de permisos).
        Route::put('contrasena', 'cambiarContrasena')->middleware('throttle:contrasena');
    });
});

// Navegación común
Route::get('menu', [MenuController::class, 'index']);
Route::post('visitas', [VisitaController::class, 'store'])->middleware('throttle:visitas');
Route::get('buscar', [BusquedaController::class, 'index'])->middleware(['permiso:catalogo.ver', 'throttle:busqueda']);

// CU4 · Catálogo público (también para visitantes)
Route::prefix('catalogo')->controller(CatalogoController::class)->middleware('permiso:catalogo.ver')->group(function () {
    Route::get('/', 'index');
    Route::get('filtros', 'filtros');
    Route::get('{producto}', 'show')->whereNumber('producto');
});

// CU5 · Carrito y confirmación de pedidos (cliente)
Route::middleware(['auth:api', 'permiso:carrito.usar'])->group(function () {
    Route::get('carrito', [CarritoController::class, 'index']);
    Route::post('carrito', [CarritoController::class, 'store'])->middleware('sin-cuota-vencida');
    Route::patch('carrito/{producto}', [CarritoController::class, 'update'])->whereNumber('producto');
    Route::delete('carrito/{producto}', [CarritoController::class, 'destroy'])->whereNumber('producto');
    Route::post('carrito/cotizar-envio', [CarritoController::class, 'cotizarEnvio'])->middleware('throttle:busqueda');
    Route::get('carrito/direccion', [CarritoController::class, 'direccion'])->middleware('throttle:busqueda');
    Route::post('pedidos', [PedidoController::class, 'store'])->middleware('sin-cuota-vencida');
});

// CU5 y CU6 · Mis pedidos y pagos (cliente)
Route::middleware(['auth:api', 'permiso:pedidos.propios'])->prefix('mis-pedidos')->controller(PedidoController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('{pedido}', 'show')->whereNumber('pedido');
    Route::post('{pedido}/cancelar', 'cancelar')->whereNumber('pedido');
    Route::post('{pedido}/pagar', 'pagar')->whereNumber('pedido')->middleware('permiso:pagos.propios');
});

Route::middleware(['auth:api', 'permiso:pagos.propios'])->prefix('mis-pagos')->controller(PagoController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('{pago}', 'show')->whereNumber('pago');
    Route::post('{pago}/simular-qr', 'simularQr')->whereNumber('pago');
});

Route::post('gestion/facturas/{factura}/anulacion', [FacturaController::class, 'anular'])
    ->middleware(['auth:api', 'permiso:facturas.anular'])->whereNumber('factura');

Route::middleware(['auth:api', 'permiso:facturas.propias'])->prefix('mis-facturas')->controller(FacturaController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('{factura}', 'show')->whereNumber('factura');
});

Route::get('planes-pago', [PagoController::class, 'planes'])->middleware(['auth:api', 'permiso:planes_pago.propios']);

Route::middleware(['auth:api', 'permiso:metodos_pago.gestionar'])->prefix('metodos-pago')->controller(MetodoPagoController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::delete('{metodo}', 'destroy')->whereNumber('metodo');
    Route::post('{metodo}/predeterminado', 'predeterminado')->whereNumber('metodo');
});

// CU4 · Mantenimiento del catálogo (Personal de FEDER y administrador)
Route::middleware(['auth:api', 'permiso:productos.gestionar'])->prefix('gestion')->controller(ProductoController::class)->group(function () {
    Route::get('productos', 'index');
    Route::post('productos', 'store');
    Route::put('productos/{producto}', 'update')->whereNumber('producto');
    Route::get('categorias', 'categorias');
    Route::post('categorias', 'guardarCategoria');
    Route::put('categorias/{categoria}', 'guardarCategoria')->whereNumber('categoria');
});

// CU2 · Productores y comunidades agrícolas
Route::middleware('auth:api')->prefix('gestion')->controller(ProductorController::class)->group(function () {
    Route::middleware('permiso:productores.gestionar')->group(function () {
        Route::get('productores', 'index');
        Route::post('productores/{productor}/aprobar', 'aprobar')->whereNumber('productor');
        Route::post('productores/{productor}/rechazar', 'rechazar')->whereNumber('productor');
    });
    Route::middleware('permiso:comunidades.gestionar')->group(function () {
        Route::get('comunidades', 'comunidades');
        Route::post('comunidades', 'guardarComunidad');
        Route::put('comunidades/{comunidad}', 'guardarComunidad')->whereNumber('comunidad');
    });
});
Route::middleware(['auth:api', 'permiso:productores.gestionar'])->controller(OperacionProductorController::class)->group(function () {
    Route::get('gestion/cambios-comunidad-productor', 'cambiosComunidad');
    Route::post('gestion/cambios-comunidad-productor/{solicitud}', 'resolverCambioComunidad')->whereNumber('solicitud');
});
Route::get('productor/ficha', [ProductorController::class, 'miFicha'])->middleware(['auth:api', 'permiso:productor.ficha']);
Route::put('productor/ficha', [OperacionProductorController::class, 'actualizarFicha'])->middleware(['auth:api', 'permiso:productor.ficha']);
Route::middleware(['auth:api', 'permiso:perfil.ver'])->controller(ProductorController::class)->group(function () {
    Route::get('comunidades-disponibles', 'comunidadesDisponibles');
    Route::get('productor/solicitud', 'estadoSolicitud');
    Route::post('productor/solicitud', 'solicitar');
});

Route::middleware(['auth:api', 'permiso:producciones.propias'])->prefix('productor')->controller(OperacionProductorController::class)->group(function () {
    Route::get('producciones/opciones', 'opcionesProduccion');
    Route::post('producciones', 'crearProduccion');
    Route::get('producciones/{produccion}', 'detalleProduccion')->whereNumber('produccion');
    Route::put('producciones/{produccion}', 'actualizarProduccion')->whereNumber('produccion');
    Route::post('producciones/{produccion}/estado', 'cambiarEstadoProduccion')->whereNumber('produccion');
    Route::post('producciones/{produccion}/lotes', 'crearLote')->whereNumber('produccion');
    Route::put('producciones/{produccion}/lotes/{lote}', 'actualizarLote')->whereNumber('produccion')->whereNumber('lote');
    Route::post('lotes/{lote}/retirar', 'retirarLote')->whereNumber('lote');
    Route::get('productos-propuestos', 'propuestasProductor');
    Route::post('productos-propuestos', 'proponerProducto');
});
Route::get('productor/ventas/{compra}', [OperacionProductorController::class, 'venta'])
    ->whereNumber('compra')->middleware(['auth:api', 'permiso:ventas_feder.propias']);

Route::middleware(['auth:api', 'permiso:compras.gestionar'])->prefix('gestion')->controller(OperacionProductorController::class)->group(function () {
    Route::get('lotes-ofertados', 'lotesOfertados');
    Route::post('compras', 'crearCompra');
    Route::get('compras/{compra}', 'compra')->whereNumber('compra');
    Route::post('compras/{compra}/recibir', 'recibirCompra')->whereNumber('compra');
    Route::post('compras/{compra}/pagar', 'pagarCompra')->whereNumber('compra');
});
Route::middleware(['auth:api', 'permiso:productos.gestionar'])->prefix('gestion/productos-propuestos')->controller(OperacionProductorController::class)->group(function () {
    Route::get('/', 'propuestasGestion');
    Route::post('{propuesta}/aprobar', 'aprobarPropuesta')->whereNumber('propuesta');
    Route::post('{propuesta}/rechazar', 'rechazarPropuesta')->whereNumber('propuesta');
});

// CU4 · Inventario (consulta) · CU3/CU7 · Portal del productor
Route::middleware('auth:api')->controller(ConsultaController::class)->group(function () {
    Route::get('gestion/inventario', 'inventario')->middleware('permiso:inventario.ver');
    Route::get('gestion/producciones', 'gestionProducciones')->middleware('permiso:producciones.gestionar');
    Route::get('gestion/compras', 'gestionCompras')->middleware('permiso:compras.gestionar');
    Route::get('productor/producciones', 'producciones')->middleware('permiso:producciones.propias');
    Route::get('productor/lotes', 'lotesProductor')->middleware('permiso:producciones.propias');
    Route::get('productor/ventas', 'ventas')->middleware('permiso:ventas_feder.propias');
    Route::get('productor/indicadores', 'ventas')->middleware('permiso:indicadores.propios');
});

// CU1 · Administración de usuarios y roles
Route::middleware(['auth:api', 'permiso:usuarios.gestionar'])->prefix('admin/usuarios')->controller(AdminUsuarioController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::patch('{usuario}/activo', 'activo')->whereNumber('usuario');
    Route::post('{usuario}/roles', 'asignarRol')->whereNumber('usuario');
    Route::post('{usuario}/roles/{rol}/revocar', 'revocarRol')->whereNumber('usuario')->where('rol', '[A-Za-z_]+');
});

// CU1 · Permisos por rol (definen también el menú de cada rol)
Route::middleware(['auth:api', 'permiso:permisos.gestionar'])->prefix('admin/permisos')->controller(AdminPermisoController::class)->group(function () {
    Route::get('/', 'index');
    Route::put('{rol}', 'update')->where('rol', '[A-Z_]+');
});

// CU7 y CU8 · Panel de negocio y accesos; CU1 · bitácora
Route::middleware('auth:api')->controller(EstadisticaController::class)->group(function () {
    Route::get('panel/negocio', 'negocio')->middleware('permiso:dashboard.ver,indicadores.ver');
    Route::get('panel/accesos', 'accesos')->middleware('permiso:accesos.ver');
    Route::get('admin/bitacora', 'bitacora')->middleware('permiso:bitacora.ver');
});

// CU8 · Reportes descargables
Route::get('reportes/{tipo}', [ReporteController::class, 'descargar'])
    ->whereIn('tipo', ['ventas', 'pedidos', 'productos'])->middleware(['auth:api', 'permiso:reportes.ver']);

// CU6 · Notificaciones dentro de la web (todos los roles con sesión)
Route::middleware(['auth:api', 'permiso:notificaciones.ver'])->prefix('notificaciones')->controller(NotificacionController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('contador', 'contador');
    Route::post('leer-todas', 'leerTodas');
    Route::post('{notificacion}/leer', 'leer')->whereNumber('notificacion');
});

// CU6 · Procesamiento de pedidos (Personal de FEDER y administrador)
// CU6 · Devoluciones: el cliente solicita y el personal resuelve
Route::middleware('auth:api')->controller(DevolucionController::class)->group(function () {
    Route::middleware('permiso:devoluciones.propias')->group(function () {
        Route::get('mis-devoluciones', 'misDevoluciones');
        Route::post('mis-devoluciones', 'solicitar');
    });
    Route::middleware('permiso:devoluciones.gestionar')->prefix('gestion/devoluciones')->group(function () {
        Route::get('/', 'index');
        Route::post('{devolucion}/aprobar', 'aprobar')->whereNumber('devolucion');
        Route::post('{devolucion}/rechazar', 'rechazar')->whereNumber('devolucion');
        Route::post('{devolucion}/completar', 'completar')->whereNumber('devolucion');
    });
});

Route::middleware(['auth:api', 'permiso:entregas.gestionar'])->prefix('gestion/entregas')->controller(GestionEntregaController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('{pedido}/avanzar', 'avanzar')->whereNumber('pedido');
});

Route::middleware(['auth:api', 'permiso:pagos.gestionar'])->prefix('gestion/pagos')->controller(GestionPagoController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('{pago}/confirmar', 'confirmar')->whereNumber('pago');
    Route::post('{pago}/rechazar', 'rechazar')->whereNumber('pago');
});

Route::middleware(['auth:api', 'permiso:pedidos.gestionar'])->prefix('gestion/pedidos')->controller(GestionPedidoController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('{pedido}', 'show')->whereNumber('pedido');
    Route::post('{pedido}/avanzar', 'avanzar')->whereNumber('pedido');
    Route::post('{pedido}/abastecer', 'abastecer')->whereNumber('pedido');
    Route::post('{pedido}/cancelar', 'cancelar')->whereNumber('pedido');
});
