<?php

namespace Tests\Integracion\Acceso;

use Illuminate\Support\Facades\DB;

/**
 * H-10 · R040: los datos de origen de un lote que ya figura en una compra de FEDER (elaboración, vencimiento y cantidad
 * producida) no los cambia su productor por la API. Conserva el ajuste de su oferta sin bajar de lo comprado (R039).
 * Los lotes se registran en producciones EN_CURSO o TERMINADA.
 */
class LoteCompradoTest extends AccesoTestCase
{
    /** Lote 11 (producción 11 EN_CURSO de la productora): 30 de sus 40 unidades están en la compra 5. */
    private const PRODUCCION = 11;
    private const LOTE = 11;

    public function test_el_productor_no_edita_por_la_api_un_lote_ya_comprado(): void
    {
        $token = $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR');
        $antes = (array) DB::table('lote_produccion')->where('id', self::LOTE)->first();
        $bitacora = DB::table('bitacora')->where('entidad', 'LOTE_PRODUCCION')->count();

        $respuesta = $this->api('PUT', '/api/productor/producciones/'.self::PRODUCCION.'/lotes/'.self::LOTE, [
            'fecha_elaboracion' => '2026-11-05', 'fecha_vencimiento' => '2029-12-31',
            'cantidad_producida' => 999, 'cantidad_ofrecida' => 40, 'precio_ofrecido' => 99.99,
        ], $token);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('compra', mb_strtolower($respuesta->json('message')));
        $this->assertSame($antes, (array) DB::table('lote_produccion')->where('id', self::LOTE)->first());
        $this->assertSame($bitacora, DB::table('bitacora')->where('entidad', 'LOTE_PRODUCCION')->count());
    }

    public function test_cada_dato_de_trazabilidad_queda_congelado_por_separado(): void
    {
        $token = $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR');
        $antes = (array) DB::table('lote_produccion')->where('id', self::LOTE)->first();
        $actual = ['fecha_elaboracion' => '2026-11-05', 'fecha_vencimiento' => '2027-11-05', 'cantidad_producida' => 40, 'cantidad_ofrecida' => 40, 'precio_ofrecido' => 60];

        $cambios = [
            ['fecha_elaboracion' => '2026-11-06'],
            ['fecha_vencimiento' => '2027-11-06'],
            ['fecha_vencimiento' => null],
            ['cantidad_producida' => 60],
        ];
        foreach ($cambios as $cambio) {
            $respuesta = $this->api('PUT', '/api/productor/producciones/'.self::PRODUCCION.'/lotes/'.self::LOTE, array_merge($actual, $cambio), $token);

            $respuesta->assertStatus(422);
            $this->assertStringContainsString('compra', mb_strtolower($respuesta->json('message')), json_encode($cambio));
        }

        $this->assertSame($antes, (array) DB::table('lote_produccion')->where('id', self::LOTE)->first());
    }

    public function test_el_productor_conserva_el_ajuste_de_su_oferta_sin_bajar_de_lo_comprado(): void
    {
        // R039: tras la compra el titular solo ajusta lo que ofrece (cantidad y precio pedido para lo que aún no se compró).
        $token = $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR');
        $actual = ['fecha_elaboracion' => '2026-11-05', 'fecha_vencimiento' => '2027-11-05', 'cantidad_producida' => 40];
        $precioComprado = DB::table('detalle_compra')->where('lote_origen_id', self::LOTE)->value('precio_unitario');

        $this->api('PUT', '/api/productor/producciones/'.self::PRODUCCION.'/lotes/'.self::LOTE, $actual + ['cantidad_ofrecida' => 35, 'precio_ofrecido' => 75], $token)
            ->assertOk();

        $lote = DB::table('lote_produccion')->where('id', self::LOTE)->first();
        $this->assertEquals(35, (float) $lote->cantidad_ofrecida);
        $this->assertEquals(75, (float) $lote->precio_ofrecido);
        $this->assertSame('DISPONIBLE', $lote->estado);
        $this->assertSame(['2026-11-05', '2027-11-05'], [$lote->fecha_elaboracion, $lote->fecha_vencimiento]);
        $this->assertEquals(40, (float) $lote->cantidad_producida);
        // El precio ya pactado en la compra no cambia: la historia se conserva.
        $this->assertSame($precioComprado, DB::table('detalle_compra')->where('lote_origen_id', self::LOTE)->value('precio_unitario'));
        $bitacora = $this->ultimaBitacora('LOTE_PRODUCCION', self::LOTE);
        $this->assertEquals(['anterior' => 40.0, 'nuevo' => 35.0], $bitacora->detalle['cambios']['cantidad_ofrecida']);
        $this->assertEquals(['anterior' => 60.0, 'nuevo' => 75.0], $bitacora->detalle['cambios']['precio_ofrecido']);
        $this->assertTrue($bitacora->detalle['tiene_compras']);

        // Ofrecer justo lo comprado deja el lote AGOTADO; ofrecer menos se rechaza.
        $this->api('PUT', '/api/productor/producciones/'.self::PRODUCCION.'/lotes/'.self::LOTE, $actual + ['cantidad_ofrecida' => 30, 'precio_ofrecido' => 75], $token)->assertOk();
        $this->assertSame('AGOTADO', DB::table('lote_produccion')->where('id', self::LOTE)->value('estado'));
        $this->api('PUT', '/api/productor/producciones/'.self::PRODUCCION.'/lotes/'.self::LOTE, $actual + ['cantidad_ofrecida' => 29, 'precio_ofrecido' => 75], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['cantidad_ofrecida']);
    }

    public function test_un_lote_con_una_compra_cancelada_conserva_su_historial_y_sus_datos_de_origen(): void
    {
        // Lote 10: su única línea de compra (compra 4) se canceló por completo; el detalle_compra sigue existiendo.
        $antes = (array) DB::table('lote_produccion')->where('id', 10)->first();

        $this->api('PUT', '/api/productor/producciones/10/lotes/10', [
            'fecha_elaboracion' => '2026-10-10', 'fecha_vencimiento' => '2030-01-01',
            'cantidad_producida' => 120, 'cantidad_ofrecida' => 60, 'precio_ofrecido' => 13.5,
        ], $this->tokenDe(self::PRODUCTOR_B, 'PRODUCTOR'))->assertStatus(422);

        $this->assertSame($antes, (array) DB::table('lote_produccion')->where('id', 10)->first());
    }

    public function test_la_api_distingue_los_lotes_con_compras(): void
    {
        $detalle = $this->api('GET', '/api/productor/producciones/'.self::PRODUCCION, [], $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR'))->assertOk();

        $lote = collect($detalle->json('lotes'))->firstWhere('id', self::LOTE);
        $this->assertFalse($lote['puede_editar']);
        $this->assertTrue($lote['tiene_compras']);
        $this->assertFalse($lote['puede_editar_datos_de_origen']);
    }
    public function test_un_lote_sin_compras_se_sigue_editando_y_la_bitacora_guarda_el_valor_anterior(): void
    {
        $token = $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR');
        $loteId = (int) $this->api('POST', '/api/productor/producciones/'.self::PRODUCCION.'/lotes', [
            'fecha_elaboracion' => '2026-11-01', 'fecha_vencimiento' => '2027-11-01',
            'cantidad_producida' => 20, 'cantidad_ofrecida' => 10, 'precio_ofrecido' => 55,
        ], $token)->assertCreated()->json('id');

        $this->api('PUT', '/api/productor/producciones/'.self::PRODUCCION."/lotes/{$loteId}", [
            'fecha_elaboracion' => '2026-11-01', 'fecha_vencimiento' => '2027-12-01',
            'cantidad_producida' => 20, 'cantidad_ofrecida' => 15, 'precio_ofrecido' => 58,
        ], $token)->assertOk();

        $lote = DB::table('lote_produccion')->where('id', $loteId)->first();
        $this->assertSame('2027-12-01', $lote->fecha_vencimiento);
        $this->assertEquals(15, (float) $lote->cantidad_ofrecida);
        $bitacora = $this->ultimaBitacora('LOTE_PRODUCCION', $loteId);
        $this->assertSame('MODIFICAR', $bitacora->accion);
        $this->assertEquals(['anterior' => '2027-11-01', 'nuevo' => '2027-12-01'], $bitacora->detalle['cambios']['fecha_vencimiento']);
        $this->assertEquals(['anterior' => 55.0, 'nuevo' => 58.0], $bitacora->detalle['cambios']['precio_ofrecido']);
    }

    public function test_no_se_registran_lotes_en_una_produccion_planificada(): void
    {
        // Producción 12 del productor B: PLANIFICADA (R040: solo EN_CURSO o TERMINADA).
        $respuesta = $this->api('POST', '/api/productor/producciones/12/lotes', [
            'cantidad_producida' => 10, 'cantidad_ofrecida' => 5, 'precio_ofrecido' => 20,
        ], $this->tokenDe(self::PRODUCTOR_B, 'PRODUCTOR'));

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('en curso', mb_strtolower($respuesta->json('message')));
        $this->assertSame(0, DB::table('lote_produccion')->where('produccion_id', 12)->count());
    }

    public function test_se_registran_lotes_en_una_produccion_terminada_pero_no_en_una_cancelada(): void
    {
        $token = $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR');
        // Producción 1 de la productora: TERMINADA (inicio 2026-02-10).
        $this->api('POST', '/api/productor/producciones/1/lotes', [
            'fecha_elaboracion' => '2026-03-05', 'cantidad_producida' => 10, 'cantidad_ofrecida' => 5, 'precio_ofrecido' => 15,
        ], $token)->assertCreated();

        DB::table('produccion')->where('id', 1)->update(['estado' => 'CANCELADA']);
        $this->api('POST', '/api/productor/producciones/1/lotes', [
            'fecha_elaboracion' => '2026-03-05', 'cantidad_producida' => 10, 'cantidad_ofrecida' => 5, 'precio_ofrecido' => 15,
        ], $token)->assertStatus(422);
    }

    public function test_el_detalle_de_la_produccion_indica_cuando_admite_lotes(): void
    {
        $this->api('GET', '/api/productor/producciones/12', [], $this->tokenDe(self::PRODUCTOR_B, 'PRODUCTOR'))
            ->assertOk()->assertJsonPath('estado', 'PLANIFICADA')->assertJsonPath('puede_registrar_lotes', false);
        $this->api('GET', '/api/productor/producciones/'.self::PRODUCCION, [], $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR'))
            ->assertOk()->assertJsonPath('estado', 'EN_CURSO')->assertJsonPath('puede_registrar_lotes', true);
    }
}
