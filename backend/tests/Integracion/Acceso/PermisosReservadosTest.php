<?php

namespace Tests\Integracion\Acceso;

use App\Services\ReporteService;

class PermisosReservadosTest extends AccesoTestCase
{
    public function test_no_se_puede_dar_la_gestion_de_usuarios_ni_de_permisos_a_otro_rol(): void
    {
        $admin = $this->tokenDe(self::ADMIN);

        foreach (['CLIENTE', 'PRODUCTOR', 'PERSONAL_FEDER', 'VISITANTE'] as $rol) {
            $antes = $this->permisosGuardados($rol);
            foreach (['usuarios.gestionar', 'permisos.gestionar'] as $reservado) {
                $respuesta = $this->api('PUT', "/api/admin/permisos/{$rol}", ['permisos' => [...$antes, $reservado]], $admin);

                $respuesta->assertStatus(422);
                $this->assertStringContainsString('ADMINISTRADOR', $respuesta->json('message'));
                $this->assertSame($antes, $this->permisosGuardados($rol), "La matriz de {$rol} cambió pese al rechazo.");
            }
        }
    }

    public function test_un_cliente_no_llega_a_ver_los_datos_de_todos_los_usuarios(): void
    {
        $admin = $this->tokenDe(self::ADMIN);
        $cliente = $this->tokenDe(self::CLIENTE);

        $this->api('PUT', '/api/admin/permisos/CLIENTE', ['permisos' => [...$this->permisosGuardados('CLIENTE'), 'usuarios.gestionar']], $admin)
            ->assertStatus(422);

        $this->api('GET', '/api/admin/usuarios', [], $cliente)->assertStatus(403);
    }

    public function test_el_administrador_conserva_sus_permisos_reservados_y_la_matriz_los_informa(): void
    {
        $admin = $this->tokenDe(self::ADMIN);
        $actuales = $this->permisosGuardados('ADMINISTRADOR');

        $this->api('PUT', '/api/admin/permisos/ADMINISTRADOR', ['permisos' => $actuales], $admin)->assertOk();
        $this->api('PUT', '/api/admin/permisos/ADMINISTRADOR', ['permisos' => array_values(array_diff($actuales, ['usuarios.gestionar']))], $admin)
            ->assertStatus(422);

        $matriz = $this->api('GET', '/api/admin/permisos', [], $admin)->assertOk();
        $this->assertSame(['usuarios.gestionar', 'permisos.gestionar'], $matriz->json('imprescindibles.ADMINISTRADOR'));
        $this->assertSame(['ADMINISTRADOR'], $matriz->json('reservados')['usuarios.gestionar']);
        $this->assertSame(['ADMINISTRADOR'], $matriz->json('reservados')['permisos.gestionar']);
    }

    public function test_los_limites_se_aplican_a_concesiones_antiguas_y_a_la_edicion(): void
    {
        $admin = $this->tokenDe(self::ADMIN);
        $personal = $this->tokenDe(self::PERSONAL);
        $antes = $this->permisosGuardados('PERSONAL_FEDER');
        $this->fijarPermisos('PERSONAL_FEDER', [...$antes, 'dashboard.ver', 'indicadores.ver', 'facturas.anular', 'carrito.usar']);
        $this->api('GET', '/api/panel/negocio', [], $personal)->assertForbidden();
        $permisos = $this->api('GET', '/api/auth/yo', [], $personal)->assertOk()->json('permisos');
        $this->assertSame($antes, $permisos);
        $this->api('GET', '/api/panel/negocio', [], $admin)->assertOk();
        foreach (['CLIENTE' => 'compras.gestionar', 'PRODUCTOR' => 'productos.gestionar', 'VISITANTE' => 'perfil.ver',
            'PERSONAL_FEDER' => 'facturas.anular', 'ADMINISTRADOR' => 'carrito.usar'] as $rol => $ajeno) {
            $this->api('PUT', "/api/admin/permisos/{$rol}", ['permisos' => [...$this->permisosGuardados($rol), $ajeno]], $admin)->assertUnprocessable();
        }
        $this->api('PUT', '/api/admin/permisos/CLIENTE', ['permisos' => ['desconocido.ver']], $admin)->assertUnprocessable();

        // CU8: el CSV del personal contiene importes por registro y cada fila identifica el registro.
        foreach (['ventas' => 'Pedido', 'pedidos' => 'Pedido', 'productos' => 'Código'] as $tipo => $columna) {
            $csv = $this->api('GET', "/api/reportes/{$tipo}", [], $personal)->assertOk()->streamedContent();
            $reporte = app(ReporteService::class)->generar($tipo, null, null);
            $indice = array_search($columna, $reporte['encabezados'], true);
            $this->assertNotFalse($indice);
            $this->assertStringContainsString($columna, $csv);
            $this->assertNotEmpty($reporte['filas']);
            foreach ($reporte['filas'] as $fila) {
                $this->assertNotEmpty($fila[$indice]);
            }
        }
    }

    public function test_actualizar_una_instalacion_una_vez_preserva_otras_revocaciones(): void
    {
        $admin = $this->tokenDe(self::ADMIN);
        $antes = array_values(array_diff($this->permisosGuardados('ADMINISTRADOR'), ['facturas.anular', 'reportes.ver']));
        $this->fijarPermisos('ADMINISTRADOR', $antes);
        $this->artisan('feder:habilitar-anulacion-facturas')->assertSuccessful();
        $this->assertSame([...$antes, 'facturas.anular'], $this->permisosGuardados('ADMINISTRADOR'));
        $this->assertNull($this->ultimaBitacora('PERMISO_ROL')->usuario_id);
        $this->api('PUT', '/api/admin/permisos/ADMINISTRADOR', ['permisos' => $antes], $admin)->assertOk();
        $this->artisan('feder:habilitar-anulacion-facturas')->assertSuccessful();
        $this->assertEqualsCanonicalizing($antes, $this->permisosGuardados('ADMINISTRADOR'));
    }

    public function test_la_actualizacion_respeta_una_revocacion_anterior_a_su_primera_ejecucion(): void
    {
        $antes = array_values(array_diff($this->permisosGuardados('ADMINISTRADOR'), ['facturas.anular']));
        $this->api('PUT', '/api/admin/permisos/ADMINISTRADOR', ['permisos' => $antes], $this->tokenDe(self::ADMIN))->assertOk();
        $this->artisan('feder:habilitar-anulacion-facturas')->assertSuccessful();
        $this->assertNotContains('facturas.anular', $this->permisosGuardados('ADMINISTRADOR'));
    }

    public function test_el_resto_de_la_matriz_sigue_siendo_editable(): void
    {
        $admin = $this->tokenDe(self::ADMIN);
        $antes = $this->permisosGuardados('PERSONAL_FEDER');

        // Quitar y devolver un permiso operativo al personal sigue funcionando como siempre.
        $sinInventario = array_values(array_diff($antes, ['inventario.ver']));
        $this->api('PUT', '/api/admin/permisos/PERSONAL_FEDER', ['permisos' => $sinInventario], $admin)->assertOk();
        $this->assertEqualsCanonicalizing($sinInventario, $this->permisosGuardados('PERSONAL_FEDER'));

        $this->api('PUT', '/api/admin/permisos/PERSONAL_FEDER', ['permisos' => $antes], $admin)->assertOk();
        $this->assertEqualsCanonicalizing($antes, $this->permisosGuardados('PERSONAL_FEDER'));
    }
}
