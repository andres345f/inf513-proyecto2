<?php

namespace Tests\Integracion\Acceso;

use Illuminate\Support\Facades\DB;

/**
 * H-18 · R073/R101: la ficha del productor sigue a su concesión PRODUCTOR (se desactiva al revocarla, en la misma
 * transacción, y vuelve al devolver el rol). R004: FEDER solo compra a un productor habilitado y de un producto activo.
 */
class ProductorRolYCompraTest extends AccesoTestCase
{
    /** Productora C (productor 4): lote 13 con 30 unidades libres de su oferta. */
    private const LOTE = 13;

    public function test_revocar_productor_desactiva_la_ficha_en_la_misma_operacion(): void
    {
        $usuarioId = $this->usuarioId(self::PRODUCTORA_C);
        $token = $this->tokenDe(self::PRODUCTORA_C);

        $this->api('POST', "/api/admin/usuarios/{$usuarioId}/roles/PRODUCTOR/revocar", ['motivo' => 'Incumplimiento del convenio'], $this->tokenDe(self::ADMIN))
            ->assertOk();

        $ficha = DB::table('productor')->where('usuario_id', $usuarioId)->first();
        $this->assertFalse((bool) $ficha->activo, 'La ficha del productor siguió activa tras revocar el rol.');
        $this->assertSame('APROBADA', $ficha->estado_solicitud);
        $this->assertNotNull($ficha->codigo_productor);

        $bitacora = $this->ultimaBitacora('PRODUCTOR', (int) $ficha->id);
        $this->assertSame('MODIFICAR', $bitacora->accion);
        $this->assertTrue($bitacora->detalle['activo_anterior']);
        $this->assertFalse($bitacora->detalle['activo_nuevo']);
        $this->assertStringContainsString('Incumplimiento del convenio', $bitacora->detalle['motivo']);

        $this->api('GET', '/api/productor/producciones', [], $token)->assertStatus(401);
    }

    public function test_no_se_compra_a_un_productor_sin_rol_vigente_y_sus_lotes_dejan_de_ofrecerse(): void
    {
        $personal = $this->tokenDe(self::PERSONAL);
        $usuarioId = $this->usuarioId(self::PRODUCTORA_C);
        $this->assertContains(self::LOTE, array_column($this->api('GET', '/api/gestion/lotes-ofertados', [], $personal)->json('lotes'), 'id'));

        $this->api('POST', "/api/admin/usuarios/{$usuarioId}/roles/PRODUCTOR/revocar", ['motivo' => 'Baja voluntaria'], $this->tokenDe(self::ADMIN))->assertOk();

        $compras = DB::table('compra_feder')->count();
        $respuesta = $this->api('POST', '/api/gestion/compras', $this->compra(self::LOTE), $personal);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('no está habilitado', $respuesta->json('message'));
        $this->assertSame($compras, DB::table('compra_feder')->count());
        $this->assertNotContains(self::LOTE, array_column($this->api('GET', '/api/gestion/lotes-ofertados', [], $personal)->json('lotes'), 'id'));
    }

    public function test_tampoco_se_compra_si_la_ficha_quedo_activa_pero_la_concesion_ya_no_esta_vigente(): void
    {
        // Datos anteriores a la corrección: rol revocado con la ficha todavía activa.
        $usuarioId = $this->usuarioId(self::PRODUCTORA_C);
        DB::table('usuario_rol')->where('usuario_id', $usuarioId)->where('rol_id', $this->rolId('PRODUCTOR'))->where('estado', 'VIGENTE')
            ->update(['estado' => 'REVOCADA', 'fecha_revocacion' => DB::raw('now()'), 'revocado_por_id' => $this->usuarioId(self::ADMIN), 'motivo_revocacion' => 'Dato previo a la corrección']);
        $this->assertTrue((bool) DB::table('productor')->where('usuario_id', $usuarioId)->value('activo'));

        $this->api('POST', '/api/gestion/compras', $this->compra(self::LOTE), $this->tokenDe(self::PERSONAL))->assertStatus(422);
    }

    public function test_no_se_compra_a_un_productor_con_la_cuenta_desactivada(): void
    {
        $usuarioId = $this->usuarioId(self::PRODUCTORA_C);
        $this->api('PATCH', "/api/admin/usuarios/{$usuarioId}/activo", ['activo' => false], $this->tokenDe(self::ADMIN))->assertOk();

        $respuesta = $this->api('POST', '/api/gestion/compras', $this->compra(self::LOTE), $this->tokenDe(self::PERSONAL));

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('no está habilitado', $respuesta->json('message'));
    }

    public function test_no_se_compra_un_lote_de_un_producto_inactivo(): void
    {
        $productoId = (int) DB::table('lote_produccion as l')->join('produccion as pr', 'pr.id', '=', 'l.produccion_id')->where('l.id', self::LOTE)->value('pr.producto_id');
        DB::table('producto')->where('id', $productoId)->update(['activo' => false]);
        $compras = DB::table('compra_feder')->count();

        $respuesta = $this->api('POST', '/api/gestion/compras', $this->compra(self::LOTE), $this->tokenDe(self::PERSONAL));

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('inactivo', $respuesta->json('message'));
        $this->assertSame($compras, DB::table('compra_feder')->count());
        $this->assertNotContains(self::LOTE, array_column($this->api('GET', '/api/gestion/lotes-ofertados', [], $this->tokenDe(self::PERSONAL))->json('lotes'), 'id'));
    }

    public function test_devolver_el_rol_rehabilita_la_ficha_y_la_compra_vuelve_a_ser_posible(): void
    {
        $admin = $this->tokenDe(self::ADMIN);
        $usuarioId = $this->usuarioId(self::PRODUCTORA_C);
        $this->api('POST', "/api/admin/usuarios/{$usuarioId}/roles/PRODUCTOR/revocar", ['motivo' => 'Suspensión temporal'], $admin)->assertOk();

        $this->api('POST', "/api/admin/usuarios/{$usuarioId}/roles", ['rol' => 'PRODUCTOR'], $admin)->assertOk();

        $ficha = DB::table('productor')->where('usuario_id', $usuarioId)->first();
        $this->assertTrue((bool) $ficha->activo, 'La ficha no se rehabilitó al devolver el rol PRODUCTOR.');
        $bitacora = $this->ultimaBitacora('PRODUCTOR', (int) $ficha->id);
        $this->assertFalse($bitacora->detalle['activo_anterior']);
        $this->assertTrue($bitacora->detalle['activo_nuevo']);

        $this->api('POST', '/api/gestion/compras', $this->compra(self::LOTE), $this->tokenDe(self::PERSONAL))->assertCreated();
        $this->api('GET', '/api/productor/producciones', [], $this->tokenDe(self::PRODUCTORA_C))->assertOk();
    }

    public function test_asignar_productor_a_quien_no_tiene_una_ficha_aprobada_no_inventa_una_ficha(): void
    {
        $cliente = $this->crearCuenta(['CLIENTE']);

        $this->api('POST', "/api/admin/usuarios/{$cliente->id}/roles", ['rol' => 'PRODUCTOR'], $this->tokenDe(self::ADMIN))->assertOk();

        $this->assertSame(0, DB::table('productor')->where('usuario_id', $cliente->id)->count());
    }

    public function test_revocar_otro_rol_no_toca_la_ficha_del_productor(): void
    {
        $usuarioId = $this->usuarioId(self::PRODUCTORA);

        $this->api('POST', "/api/admin/usuarios/{$usuarioId}/roles/CLIENTE/revocar", ['motivo' => 'Solo vende, ya no compra'], $this->tokenDe(self::ADMIN))->assertOk();

        $this->assertTrue((bool) DB::table('productor')->where('usuario_id', $usuarioId)->value('activo'));
    }

    public function test_la_compra_a_un_productor_habilitado_sigue_funcionando(): void
    {
        $respuesta = $this->api('POST', '/api/gestion/compras', $this->compra(self::LOTE, 5), $this->tokenDe(self::PERSONAL));

        $respuesta->assertCreated();
        $compraId = (int) $respuesta->json('id');
        $this->assertSame('REGISTRADA', DB::table('compra_feder')->where('id', $compraId)->value('estado'));
        $this->assertSame(1, DB::table('detalle_compra')->where('compra_id', $compraId)->where('lote_origen_id', self::LOTE)->count());
    }

    private function compra(int $loteId, float $cantidad = 2): array
    {
        return [
            'lineas' => [['lote_id' => $loteId, 'cantidad' => $cantidad, 'precio' => 40]],
            'modalidad_traslado' => 'RECOJO_FEDER',
        ];
    }
}
