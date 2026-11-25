<?php

namespace App\Support;

/**
 * Páginas de la aplicación web cuyas visitas se cuentan (pie de página y panel de accesos).
 *
 * Una página se reconoce si es una pantalla de la SPA (lista fija, espejo de frontend/src/routes/AppRouter.jsx)
 * o una entrada del menú guardado en la base (MENU_WEB). Cada una lleva el permiso que exige su pantalla;
 * null significa que es pública.
 *
 * Si se agrega una pantalla nueva al frontend que no esté en el menú, hay que añadirla aquí para que cuente.
 */
final class PaginasWeb
{
    /** Patrón => permiso de la pantalla. «{id}» es un identificador numérico. */
    private const PANTALLAS = [
        '/' => null,
        '/ingresar' => null,
        '/registro' => null,
        '/catalogo' => null,
        '/catalogo/{id}' => null,
        '/perfil' => 'perfil.ver',
        '/notificaciones' => 'notificaciones.ver',
        '/mi-cuenta' => 'mi_cuenta.ver',
        '/mi-solicitud' => 'mi_cuenta.ver',
        '/carrito' => 'carrito.usar',
        '/checkout' => 'carrito.usar',
        '/mis-pedidos' => 'pedidos.propios',
        '/mis-pedidos/{id}' => 'pedidos.propios',
        '/mis-pagos' => 'pagos.propios',
        '/metodos-pago' => 'metodos_pago.gestionar',
        '/planes-pago' => 'planes_pago.propios',
        '/mis-facturas' => 'facturas.propias',
        '/mis-facturas/{id}' => 'facturas.propias',
        '/mis-devoluciones' => 'devoluciones.propias',
        '/productor' => 'productor.panel',
        '/productor/ficha' => 'productor.ficha',
        '/productor/producciones' => 'producciones.propias',
        '/productor/producciones/{id}' => 'producciones.propias',
        '/productor/lotes' => 'producciones.propias',
        '/productor/propuestas' => 'producciones.propias',
        '/productor/ventas' => 'ventas_feder.propias',
        '/productor/indicadores' => 'indicadores.propios',
        '/operaciones' => 'operaciones.panel',
        '/panel' => 'dashboard.ver',
        '/indicadores' => 'indicadores.ver',
        '/reportes' => 'reportes.ver',
        '/gestion/pedidos' => 'pedidos.gestionar',
        '/gestion/pedidos/{id}' => 'pedidos.gestionar',
        '/gestion/pagos' => 'pagos.gestionar',
        '/gestion/entregas' => 'entregas.gestionar',
        '/gestion/devoluciones' => 'devoluciones.gestionar',
        '/gestion/productos' => 'productos.gestionar',
        '/gestion/categorias' => 'productos.gestionar',
        '/gestion/productores' => 'productores.gestionar',
        '/gestion/comunidades' => 'comunidades.gestionar',
        '/gestion/producciones' => 'producciones.gestionar',
        '/gestion/compras' => 'compras.gestionar',
        '/gestion/inventario' => 'inventario.ver',
        '/administracion' => 'administracion.panel',
        '/admin/usuarios' => 'usuarios.gestionar',
        '/admin/permisos' => 'permisos.gestionar',
        '/admin/bitacora' => 'bitacora.ver',
        '/admin/accesos' => 'accesos.ver',
    ];

    private function __construct()
    {
    }

    /** Forma única de una página: en minúsculas y sin barra final (salvo la portada). */
    public static function normalizar(string $pagina): string
    {
        $pagina = mb_strtolower(trim($pagina));

        return $pagina === '/' ? '/' : rtrim($pagina, '/');
    }

    /**
     * Busca la página entre las pantallas fijas y las entradas del menú.
     *
     * @param  iterable<array{ruta:string, permiso:string}>  $entradasMenu
     * @return array{pagina:string, permiso:?string}|null null si la página no existe en la aplicación
     */
    public static function resolver(string $pagina, iterable $entradasMenu = []): ?array
    {
        $pagina = self::normalizar($pagina);
        if ($pagina === '') {
            return null;
        }

        foreach (self::PANTALLAS as $patron => $permiso) {
            if (self::coincide($patron, $pagina)) {
                return ['pagina' => $pagina, 'permiso' => $permiso];
            }
        }

        foreach ($entradasMenu as $entrada) {
            if (self::normalizar((string) ($entrada['ruta'] ?? '')) === $pagina) {
                return ['pagina' => $pagina, 'permiso' => $entrada['permiso'] ?? null];
            }
        }

        return null;
    }

    private static function coincide(string $patron, string $pagina): bool
    {
        if (! str_contains($patron, '{id}')) {
            return $patron === $pagina;
        }

        $expresion = '#^'.str_replace('\{id\}', '[0-9]{1,18}', preg_quote($patron, '#')).'$#';

        return preg_match($expresion, $pagina) === 1;
    }
}
