<?php

namespace Tests\Integracion\Acceso;

use App\Http\Controllers\Api\AdminUsuarioController;
use App\Http\Controllers\Api\OperacionProductorController;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionMethod;

/**
 * H-40 (parte de acceso y productores): las acciones que reciben datos validan con un FormRequest propio
 * (Controller → FormRequest → Service → Repository) y sus mensajes salen en español y por campo.
 */
class FormRequestsAccesoTest extends AccesoTestCase
{
    public function test_las_acciones_con_datos_declaran_un_form_request(): void
    {
        $acciones = [
            [AdminUsuarioController::class, 'index'],
            [AdminUsuarioController::class, 'store'],
            [AdminUsuarioController::class, 'activo'],
            [AdminUsuarioController::class, 'asignarRol'],
            [AdminUsuarioController::class, 'revocarRol'],
            [OperacionProductorController::class, 'cambiarEstadoProduccion'],
            [OperacionProductorController::class, 'resolverCambioComunidad'],
            [OperacionProductorController::class, 'rechazarPropuesta'],
        ];

        foreach ($acciones as [$clase, $metodo]) {
            $tipo = (new ReflectionMethod($clase, $metodo))->getParameters()[0]->getType()?->getName();
            $this->assertTrue(is_subclass_of((string) $tipo, FormRequest::class), "{$clase}::{$metodo} valida dentro del controlador (recibe {$tipo}).");
        }
    }

    public function test_inventario_productos_categorias_y_notificaciones_validan_por_campo(): void
    {
        $admin = $this->tokenDe(self::ADMIN);
        $this->api('GET', '/api/gestion/inventario?estado=ERRONEO', [], $admin)
            ->assertUnprocessable()->assertJsonPath('errors.estado.0', 'El estado elegido no es válido.');
        $this->api('GET', '/api/gestion/productos?stock_bajo=otro', [], $admin)
            ->assertUnprocessable()->assertJsonValidationErrors('stock_bajo');
        $this->api('POST', '/api/gestion/categorias', ['nombre' => 'A'], $admin)
            ->assertUnprocessable()->assertJsonPath('errors.nombre.0', 'El nombre debe tener al menos 3 caracteres.');
        $this->api('GET', '/api/notificaciones?pagina=0', [], $admin)
            ->assertUnprocessable()->assertJsonValidationErrors('pagina');
    }

    public function test_los_controladores_no_validan_en_linea(): void
    {
        foreach ([AdminUsuarioController::class, OperacionProductorController::class] as $clase) {
            $codigo = file_get_contents((new \ReflectionClass($clase))->getFileName());
            $this->assertStringNotContainsString('->validate(', $codigo, "{$clase} todavía llama a \$request->validate().");
        }
    }

    public function test_activar_o_desactivar_exige_el_campo_activo(): void
    {
        $cuenta = $this->crearCuenta(['CLIENTE']);
        $admin = $this->tokenDe(self::ADMIN);

        $this->api('PATCH', "/api/admin/usuarios/{$cuenta->id}/activo", [], $admin)
            ->assertStatus(422)->assertJsonPath('errors.activo.0', 'Indica si la cuenta queda activa.');
        $this->api('PATCH', "/api/admin/usuarios/{$cuenta->id}/activo", ['activo' => 'quizas'], $admin)
            ->assertStatus(422)->assertJsonValidationErrors(['activo']);
    }

    public function test_asignar_y_revocar_rol_validan_rol_y_motivo(): void
    {
        $cuenta = $this->crearCuenta(['CLIENTE']);
        $admin = $this->tokenDe(self::ADMIN);

        $this->api('POST', "/api/admin/usuarios/{$cuenta->id}/roles", [], $admin)
            ->assertStatus(422)->assertJsonPath('errors.rol.0', 'Elige el rol a asignar.');
        $this->api('POST', "/api/admin/usuarios/{$cuenta->id}/roles", ['rol' => 'SUPERUSUARIO'], $admin)
            ->assertStatus(422)->assertJsonPath('errors.rol.0', 'El rol elegido no existe.');
        $this->api('POST', "/api/admin/usuarios/{$cuenta->id}/roles/CLIENTE/revocar", [], $admin)
            ->assertStatus(422)->assertJsonPath('errors.motivo.0', 'Escribe el motivo de la revocación.');
        $this->api('POST', "/api/admin/usuarios/{$cuenta->id}/roles/CLIENTE/revocar", ['motivo' => 'no'], $admin)
            ->assertStatus(422)->assertJsonPath('errors.motivo.0', 'El motivo debe tener al menos 3 caracteres.');
    }

    public function test_el_listado_de_usuarios_valida_sus_filtros(): void
    {
        $admin = $this->tokenDe(self::ADMIN);

        $this->api('GET', '/api/admin/usuarios?rol=SUPERUSUARIO', [], $admin)
            ->assertStatus(422)->assertJsonPath('errors.rol.0', 'El rol elegido no existe.');
        $this->api('GET', '/api/admin/usuarios?pagina=0', [], $admin)->assertStatus(422)->assertJsonValidationErrors(['pagina']);
        $this->api('GET', '/api/admin/usuarios?rol=PRODUCTOR&activo=1&q=feder', [], $admin)->assertOk()->assertJsonStructure(['usuarios', 'paginacion']);
    }

    public function test_cambiar_el_estado_de_una_produccion_valida_el_estado(): void
    {
        $token = $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR');

        $this->api('POST', '/api/productor/producciones/11/estado', [], $token)
            ->assertStatus(422)->assertJsonPath('errors.estado.0', 'Indica el nuevo estado de la producción.');
        $this->api('POST', '/api/productor/producciones/11/estado', ['estado' => 'PLANIFICADA'], $token)
            ->assertStatus(422)->assertJsonPath('errors.estado.0', 'El estado elegido no es válido.');
    }

    public function test_rechazar_un_cambio_de_comunidad_exige_el_motivo_en_su_campo(): void
    {
        $personal = $this->tokenDe(self::PERSONAL);

        $this->api('POST', '/api/gestion/cambios-comunidad-productor/1', [], $personal)
            ->assertStatus(422)->assertJsonValidationErrors(['aprobar']);
        $this->api('POST', '/api/gestion/cambios-comunidad-productor/1', ['aprobar' => false], $personal)
            ->assertStatus(422)->assertJsonPath('errors.motivo.0', 'Escribe el motivo del rechazo.');
    }

    public function test_los_errores_de_una_produccion_nombran_los_campos_en_espanol(): void
    {
        $token = $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR');

        $respuesta = $this->api('POST', '/api/productor/producciones', [
            'fecha_inicio' => 'no-es-fecha', 'fecha_disponibilidad_estimada' => 'tampoco', 'ubicacion_produccion' => 'ab',
        ], $token)->assertStatus(422);

        $this->assertStringContainsString('fecha de inicio', $respuesta->json('errors.fecha_inicio.0'));
        $this->assertStringContainsString('fecha estimada de disponibilidad', $respuesta->json('errors.fecha_disponibilidad_estimada.0'));
        $this->assertStringContainsString('ubicación de la producción', $respuesta->json('errors.ubicacion_produccion.0'));
        $this->assertStringContainsString('cantidad estimada', $respuesta->json('errors.cantidad_estimada.0'));
        $this->assertStringNotContainsString('_', implode(' ', array_merge(...array_values($respuesta->json('errors')))));
    }

    public function test_aprobar_un_cambio_de_comunidad_no_exige_motivo(): void
    {
        $personal = $this->tokenDe(self::PERSONAL);

        // La validación deja pasar la aprobación sin motivo; la solicitud 999999 no existe y lo informa el servicio.
        $respuesta = $this->api('POST', '/api/gestion/cambios-comunidad-productor/999999', ['aprobar' => true], $personal);
        $this->assertNotSame(422, $respuesta->getStatusCode(), (string) $respuesta->getContent());
        $this->assertNull($respuesta->json('errors'));
    }

    public function test_rechazar_una_propuesta_de_producto_exige_un_motivo(): void
    {
        $personal = $this->tokenDe(self::PERSONAL);

        $this->api('POST', '/api/gestion/productos-propuestos/1/rechazar', [], $personal)
            ->assertStatus(422)->assertJsonPath('errors.motivo.0', 'Escribe el motivo del rechazo.');
        $this->api('POST', '/api/gestion/productos-propuestos/1/rechazar', ['motivo' => 'no'], $personal)
            ->assertStatus(422)->assertJsonValidationErrors(['motivo']);
    }
}
