<?php

namespace App\Support;

/** Límite de la matriz dinámica. Cada solicitud utiliza exclusivamente el rol activo. */
final class PoliticaPermisos
{
    public const ROLES = ['VISITANTE', 'CLIENTE', 'PRODUCTOR', 'PERSONAL_FEDER', 'ADMINISTRADOR'];

    private const PUBLICOS = ['inicio.ver', 'catalogo.ver'];

    private const CUENTA = ['perfil.ver', 'notificaciones.ver'];

    private const CLIENTE = [
        'mi_cuenta.ver', 'carrito.usar', 'pedidos.propios', 'pagos.propios', 'metodos_pago.gestionar',
        'planes_pago.propios', 'facturas.propias', 'devoluciones.propias',
    ];

    private const PRODUCTOR = [
        'productor.panel', 'productor.ficha', 'producciones.propias', 'ventas_feder.propias', 'indicadores.propios',
    ];

    private const OPERACIONES = [
        'operaciones.panel', 'pedidos.gestionar', 'pagos.gestionar', 'entregas.gestionar', 'devoluciones.gestionar',
        'productos.gestionar', 'productores.gestionar', 'comunidades.gestionar', 'producciones.gestionar',
        'compras.gestionar', 'inventario.ver', 'reportes.ver',
    ];

    private const ADMINISTRACION = [
        'dashboard.ver', 'indicadores.ver', 'administracion.panel', 'usuarios.gestionar', 'permisos.gestionar',
        'bitacora.ver', 'accesos.ver', 'facturas.anular',
    ];

    public static function admisibles(string $rol): array
    {
        return match ($rol) {
            'VISITANTE' => self::PUBLICOS,
            'CLIENTE' => [...self::PUBLICOS, ...self::CUENTA, ...self::CLIENTE],
            'PRODUCTOR' => [...self::PUBLICOS, ...self::CUENTA, ...self::PRODUCTOR],
            'PERSONAL_FEDER' => [...self::PUBLICOS, ...self::CUENTA, ...self::OPERACIONES],
            'ADMINISTRADOR' => [...self::PUBLICOS, ...self::CUENTA, ...self::OPERACIONES, ...self::ADMINISTRACION],
            default => [],
        };
    }

    public static function rolesPorPermiso(): array
    {
        $roles = [];
        foreach (self::ROLES as $rol) {
            foreach (self::admisibles($rol) as $permiso) {
                $roles[$permiso][] = $rol;
            }
        }

        return $roles;
    }

    public static function efectivos(string $rol, array $concedidos): array
    {
        return array_values(array_unique(array_intersect($concedidos, self::admisibles($rol))));
    }
}
