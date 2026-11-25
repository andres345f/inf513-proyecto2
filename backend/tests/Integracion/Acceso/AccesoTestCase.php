<?php

namespace Tests\Integracion\Acceso;

use App\Models\Usuario;
use App\Repositories\UsuarioRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Integracion\IntegracionTestCase;

/**
 * Apoyo de las pruebas de acceso, cuentas, permisos y productores.
 *
 * Las cuentas con nombre propio son las de los datos de demostración (db/02 y db/03). Ninguna prueba usa la contraseña
 * de demostración: las sesiones se emiten con tokenDe() y las contraseñas que hacen falta se generan en la propia prueba.
 */
abstract class AccesoTestCase extends IntegracionTestCase
{
    protected const ADMIN = 'administracion@feder.example';
    protected const PERSONAL = 'almacen.carla.mendez@feder.example';
    /** PERSONAL_FEDER + CLIENTE. */
    protected const MULTIRROL = 'ventas.jorge.suarez@feder.example';
    /** PRODUCTOR + CLIENTE; productor 1: producción 11 EN_CURSO con el lote 11 ya comprado (compra 5). */
    protected const PRODUCTORA = 'marcelina.tomicha@feder.example';
    /** PRODUCTOR + CLIENTE; productor 3: compra 3 RECIBIDA_PARCIAL y producción 12 PLANIFICADA. */
    protected const PRODUCTOR_B = 'benedicto.paz@feder.example';
    /** Solo PRODUCTOR; productor 4: lotes 13 y 28 con oferta libre. */
    protected const PRODUCTORA_C = 'silveria.masai@feder.example';
    protected const CLIENTE = 'daniela.flores@feder.example';
    protected const CLIENTE_B = 'marco.antelo@feder.example';

    /** Petición JSON a la API con el token indicado (o sin sesión). No deja cabeceras por defecto entre peticiones. */
    protected function api(string $metodo, string $uri, array $datos = [], ?string $token = null, array $cabeceras = []): TestResponse
    {
        if ($token !== null) {
            $cabeceras = ['Authorization' => 'Bearer '.$token] + $cabeceras;
        }

        return $this->json($metodo, $uri, $datos, $cabeceras);
    }

    /** Contraseña de prueba generada al vuelo: cumple la política (letras y números) y no se guarda en ningún archivo. */
    protected function contrasenaFicticia(): string
    {
        return 'Px'.bin2hex(random_bytes(7)).'4k';
    }

    /**
     * Crea una cuenta de prueba con los roles indicados (concesiones VIGENTE).
     *
     * @param  list<string>  $roles
     */
    protected function crearCuenta(array $roles = ['CLIENTE'], ?string $contrasena = null, bool $activo = true): Usuario
    {
        $usuario = Usuario::query()->create([
            'correo' => 'prueba.'.bin2hex(random_bytes(5)).'@feder.example',
            'password_hash' => Hash::make($contrasena ?? $this->contrasenaFicticia()),
            'nombres' => 'Prueba',
            'apellidos' => 'Integracion',
            'ci' => random_int(900000000, 999999999),
            'telefono' => random_int(70000000, 79999999),
            'activo' => $activo,
            'fecha_aceptacion_terminos' => now(),
        ]);

        foreach ($roles as $codigo) {
            app(UsuarioRepository::class)->concederRolVigente($usuario->id, $this->rolId($codigo), 'Cuenta de prueba');
        }

        return $usuario->refresh();
    }

    /** Ficha de productor APROBADA y activa para una cuenta de prueba; devuelve su id. */
    protected function crearFichaProductor(Usuario $usuario): int
    {
        $id = (int) DB::table('productor')->insertGetId([
            'usuario_id' => $usuario->id,
            'comunidad_agricola_id' => (int) DB::table('comunidad_agricola')->where('activa', true)->min('id'),
            'estado_solicitud' => 'APROBADA',
            'fecha_solicitud' => DB::raw('now()'),
            'fecha_revision' => DB::raw('now()'),
            'fecha_afiliacion' => DB::raw('current_date'),
            'ubicacion_actividad' => 'Parcela de prueba de integración',
            'activo' => true,
        ]);
        DB::table('productor')->where('id', $id)->update(['codigo_productor' => sprintf('PRUEBA-%06d', $id)]);

        return $id;
    }

    protected function rolId(string $codigo): int
    {
        return (int) DB::table('rol')->where('codigo', $codigo)->value('id');
    }

    protected function usuarioId(string $correo): int
    {
        return (int) DB::table('usuario')->whereRaw('lower(correo) = ?', [mb_strtolower($correo)])->value('id');
    }

    /** Administradores que cuentan para R102: cuenta activa con concesión ADMINISTRADOR vigente. */
    protected function administradoresActivos(): int
    {
        return (int) DB::table('usuario_rol as ur')->join('usuario as u', 'u.id', '=', 'ur.usuario_id')
            ->where('ur.rol_id', $this->rolId('ADMINISTRADOR'))->where('ur.estado', 'VIGENTE')->where('u.activo', true)
            ->distinct()->count('ur.usuario_id');
    }

    /** Última fila de bitácora de una entidad (y, si se indica, de un registro concreto). */
    protected function ultimaBitacora(string $entidad, ?int $entidadId = null): ?object
    {
        $fila = DB::table('bitacora')->where('entidad', $entidad)
            ->when($entidadId !== null, fn ($q) => $q->where('entidad_id', $entidadId))
            ->orderByDesc('id')->first();
        if ($fila) {
            $fila->detalle = json_decode((string) $fila->detalle, true);
        }

        return $fila;
    }

    /** Permisos guardados de un rol (lectura directa de la fila PERMISO_ROL). */
    protected function permisosGuardados(string $rol): array
    {
        $parametros = DB::table('confirmacion_operacion')->where('comando', 'PERMISO_ROL')->whereNull('usuario_id')
            ->whereRaw("parametros->>'rol' = ?", [$rol])->orderBy('id')->value('parametros');

        return json_decode((string) $parametros, true)['permisos'] ?? [];
    }

    /** Cambia los permisos de un rol directamente en la base (simula una matriz ya editada). */
    protected function fijarPermisos(string $rol, array $permisos): void
    {
        DB::table('confirmacion_operacion')->where('comando', 'PERMISO_ROL')->whereNull('usuario_id')
            ->whereRaw("parametros->>'rol' = ?", [$rol])
            ->update(['parametros' => json_encode(['rol' => $rol, 'permisos' => array_values($permisos)])]);
    }
}
