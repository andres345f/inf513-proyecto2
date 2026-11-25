<?php

namespace Tests\Integracion\Acceso;

use Illuminate\Support\Facades\DB;

/**
 * H-38 (parte de acceso y productores): entradas que antes pasaban el FormRequest y solo frenaba PostgreSQL
 * (o que terminaban en error 500) ahora se rechazan antes, con el mensaje en el campo que corresponde.
 */
class ValidacionEntradasTest extends AccesoTestCase
{
    public function test_login_con_el_correo_enviado_como_lista_responde_422_y_no_500(): void
    {
        $respuesta = $this->api('POST', '/api/auth/login', ['correo' => ['a@b.co'], 'password' => $this->contrasenaFicticia()]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['correo']);
    }

    public function test_login_con_la_contrasena_enviada_como_lista_responde_422(): void
    {
        $respuesta = $this->api('POST', '/api/auth/login', ['correo' => 'nadie@feder.example', 'password' => ['x']]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_registro_rechaza_saltos_de_linea_y_tabuladores_en_nombres_y_apellidos(): void
    {
        $clave = $this->contrasenaFicticia();
        $datos = [
            'nombres' => "Ana\nMaría", 'apellidos' => "Rojas\tVaca", 'correo' => 'prueba.'.bin2hex(random_bytes(4)).'@feder.example',
            'ci' => random_int(900000000, 999999999), 'telefono' => 70012345,
            'password' => $clave, 'password_confirmation' => $clave, 'acepta_terminos' => true,
        ];
        $antes = DB::table('usuario')->count();

        $respuesta = $this->api('POST', '/api/auth/registro', $datos);

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['nombres', 'apellidos']);
        $this->assertSame('Los nombres solo pueden contener letras y espacios.', $respuesta->json('errors.nombres.0'));
        $this->assertSame($antes, DB::table('usuario')->count());
    }

    public function test_registro_con_campos_de_texto_enviados_como_lista_responde_422_y_no_500(): void
    {
        $clave = $this->contrasenaFicticia();

        $respuesta = $this->api('POST', '/api/auth/registro', [
            'nombres' => ['Ana'], 'apellidos' => ['Rojas'], 'correo' => ['a@b.co'], 'ci_complemento' => ['1A'],
            'ci' => random_int(900000000, 999999999), 'telefono' => 70012345,
            'password' => $clave, 'password_confirmation' => $clave, 'acepta_terminos' => true,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['nombres', 'apellidos', 'correo', 'ci_complemento']);
    }

    public function test_registro_sigue_aceptando_nombres_compuestos_con_tildes_y_apostrofos(): void
    {
        $clave = $this->contrasenaFicticia();

        $respuesta = $this->api('POST', '/api/auth/registro', [
            'nombres' => 'María José', 'apellidos' => "D'Ávila Núñez-Roca", 'correo' => 'prueba.'.bin2hex(random_bytes(4)).'@feder.example',
            'ci' => random_int(900000000, 999999999), 'telefono' => 70012345,
            'password' => $clave, 'password_confirmation' => $clave, 'acepta_terminos' => true,
        ]);

        $respuesta->assertCreated()->assertJsonPath('usuario.nombres', 'María José');
    }

    public function test_el_perfil_rechaza_saltos_de_linea_en_los_nombres(): void
    {
        $cuenta = $this->crearCuenta(['CLIENTE']);

        $respuesta = $this->api('PUT', '/api/auth/perfil', [
            'nombres' => "Prueba\r\nDos", 'apellidos' => 'Integracion', 'correo' => $cuenta->correo, 'telefono' => $cuenta->telefono,
        ], $this->tokenDe($cuenta->correo));

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['nombres']);
    }

    public function test_el_alta_de_cuentas_por_el_administrador_rechaza_saltos_de_linea(): void
    {
        $clave = $this->contrasenaFicticia();

        $respuesta = $this->api('POST', '/api/admin/usuarios', [
            'nombres' => "Luis\nAlberto", 'apellidos' => 'Prueba', 'correo' => 'prueba.'.bin2hex(random_bytes(4)).'@feder.example',
            'ci' => random_int(900000000, 999999999), 'telefono' => 70012345,
            'password' => $clave, 'password_confirmation' => $clave, 'rol' => 'CLIENTE',
        ], $this->tokenDe(self::ADMIN));

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['nombres']);
    }

    public function test_lote_con_elaboracion_anterior_al_inicio_de_la_produccion_se_rechaza_en_su_campo(): void
    {
        // Producción 11 de la productora: EN_CURSO, inicio 2026-10-20.
        $antes = DB::table('lote_produccion')->count();

        $respuesta = $this->api('POST', '/api/productor/producciones/11/lotes', [
            'fecha_elaboracion' => '2026-10-01', 'fecha_vencimiento' => '2027-10-01',
            'cantidad_producida' => 10, 'cantidad_ofrecida' => 5, 'precio_ofrecido' => 20,
        ], $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR'));

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['fecha_elaboracion']);
        $this->assertStringContainsString('20/10/2026', $respuesta->json('errors.fecha_elaboracion.0'));
        $this->assertSame($antes, DB::table('lote_produccion')->count());
    }

    public function test_un_lote_elaborado_el_dia_de_inicio_de_la_produccion_se_acepta(): void
    {
        $respuesta = $this->api('POST', '/api/productor/producciones/11/lotes', [
            'fecha_elaboracion' => '2026-10-20', 'fecha_vencimiento' => '2027-10-20',
            'cantidad_producida' => 10, 'cantidad_ofrecida' => 5, 'precio_ofrecido' => 20,
        ], $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR'));

        $respuesta->assertCreated();
    }

    public function test_no_se_puede_mover_el_inicio_de_la_produccion_despues_de_la_elaboracion_de_sus_lotes(): void
    {
        // El lote 11 de la producción 11 se elaboró el 2026-11-05.
        $respuesta = $this->api('PUT', '/api/productor/producciones/11', [
            'producto_id' => (int) DB::table('produccion')->where('id', 11)->value('producto_id'),
            'fecha_inicio' => '2026-11-10', 'fecha_disponibilidad_estimada' => '2026-12-20',
            'cantidad_estimada' => 40, 'ubicacion_produccion' => 'Parcela comunal de prueba',
        ], $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR'));

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['fecha_inicio']);
        $this->assertSame('2026-10-20', DB::table('produccion')->where('id', 11)->value('fecha_inicio'));
    }
}
