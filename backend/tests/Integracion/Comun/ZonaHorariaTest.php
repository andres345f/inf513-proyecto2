<?php

namespace Tests\Integracion\Comun;

use Illuminate\Support\Facades\DB;
use Tests\Integracion\IntegracionTestCase;

/**
 * H-14: los instantes que escribe PHP (now() de Carbon, sin desplazamiento) y los que escribe PostgreSQL (DEFAULT now())
 * deben representar el mismo momento, sea cual sea la zona por defecto del servidor PostgreSQL.
 */
class ZonaHorariaTest extends IntegracionTestCase
{
    public function test_la_conexion_usa_la_zona_de_la_aplicacion(): void
    {
        $this->assertSame(config('app.timezone'), DB::selectOne('SHOW timezone')->TimeZone);
    }

    public function test_un_instante_escrito_por_php_coincide_con_now_de_postgresql(): void
    {
        $fila = DB::selectOne('SELECT abs(extract(epoch FROM (?::timestamptz - now()))) AS diferencia', [now()->toDateTimeString()]);

        $this->assertLessThan(5, (float) $fila->diferencia, 'PHP y PostgreSQL no coinciden en el instante actual.');
    }

    public function test_la_sesion_guarda_la_duracion_configurada(): void
    {
        $this->tokenDe('administracion@feder.example');

        $fila = DB::selectOne("SELECT extract(epoch FROM (expira_en - creada_en)) / 3600 AS horas
                                 FROM confirmacion_operacion WHERE comando = 'SESION_WEB' ORDER BY id DESC LIMIT 1");

        $this->assertEqualsWithDelta((float) config('feder.sesion_horas'), (float) $fila->horas, 0.01);
    }

    public function test_una_sesion_corta_no_viola_la_vigencia_de_la_base(): void
    {
        config(['feder.sesion_horas' => 1]);

        $token = $this->tokenDe('administracion@feder.example');

        $this->withToken($token)->getJson('/api/auth/yo')->assertOk();
    }

    public function test_la_fecha_lejana_de_configuracion_se_lee_sin_desbordar_el_anio(): void
    {
        $fila = DB::selectOne("SELECT expira_en::text AS texto FROM confirmacion_operacion WHERE comando = 'PERMISO_ROL' LIMIT 1");

        $this->assertStringStartsWith('9999-12-31', $fila->texto);
    }
}
