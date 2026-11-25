<?php

namespace Tests\Integracion\Acceso;

use Illuminate\Support\Facades\DB;

/**
 * H-39: límite de inicio de sesión también por IP y contador de visitas que solo cuenta páginas reales
 * que la persona puede abrir.
 */
class LimitesYVisitasTest extends AccesoTestCase
{
    public function test_el_inicio_de_sesion_tiene_un_tope_por_ip_aunque_cambie_el_correo(): void
    {
        $clave = $this->contrasenaFicticia();
        $codigos = [];
        for ($i = 1; $i <= 31; $i++) {
            $codigos[] = $this->api('POST', '/api/auth/login', ['correo' => "nadie{$i}@feder.example", 'password' => $clave])->status();
        }

        // Los 30 primeros se atienden (credenciales incorrectas); el siguiente desde la misma IP se frena.
        $this->assertSame(array_fill(0, 30, 422), array_slice($codigos, 0, 30));
        $this->assertSame(429, $codigos[30]);
    }

    public function test_se_conserva_el_tope_de_cinco_intentos_por_correo_e_ip(): void
    {
        $clave = $this->contrasenaFicticia();
        $codigos = [];
        for ($i = 1; $i <= 6; $i++) {
            $codigos[] = $this->api('POST', '/api/auth/login', ['correo' => $i % 2 ? 'nadie@feder.example' : 'NADIE@Feder.Example', 'password' => $clave])->status();
        }

        $this->assertSame([422, 422, 422, 422, 422, 429], $codigos);
    }

    public function test_una_pagina_inventada_no_se_registra_ni_se_cuenta(): void
    {
        $antes = DB::table('bitacora')->where('entidad', 'ACCESO_WEB')->count();

        $respuesta = $this->api('POST', '/api/visitas', ['pagina' => '/pagina-inventada/<b>x</b>']);

        $respuesta->assertOk()->assertJsonPath('registrada', false)->assertJsonPath('total', 0);
        $this->assertSame($antes, DB::table('bitacora')->where('entidad', 'ACCESO_WEB')->count());
    }

    public function test_una_pagina_publica_real_se_registra_y_su_contador_aumenta(): void
    {
        $primera = $this->api('POST', '/api/visitas', ['pagina' => '/catalogo'])->assertOk()->assertJsonPath('registrada', true);
        $segunda = $this->api('POST', '/api/visitas', ['pagina' => '/catalogo'])->assertOk();

        $this->assertSame($primera->json('total') + 1, $segunda->json('total'));
        $this->assertSame('/catalogo', $this->ultimaBitacora('ACCESO_WEB')->detalle['pagina']);
    }

    public function test_las_paginas_de_detalle_y_la_portada_siguen_contando(): void
    {
        foreach (['/', '/catalogo/5', '/ingresar', '/registro'] as $pagina) {
            $this->api('POST', '/api/visitas', ['pagina' => $pagina])
                ->assertOk()->assertJsonPath('registrada', true)->assertJsonPath('pagina', $pagina);
        }

        $token = $this->tokenDe(self::CLIENTE);
        foreach (['/mis-pedidos', '/mis-pedidos/11', '/checkout', '/mi-solicitud', '/mis-facturas/3', '/perfil'] as $pagina) {
            $this->api('POST', '/api/visitas', ['pagina' => $pagina], $token)->assertOk()->assertJsonPath('registrada', true);
        }
    }

    public function test_una_pagina_privada_no_cuenta_para_quien_no_puede_abrirla(): void
    {
        $antes = DB::table('bitacora')->where('entidad', 'ACCESO_WEB')->count();

        // Visitante que escribe la dirección de una pantalla privada: la aplicación lo redirige y la visita no cuenta.
        $this->api('POST', '/api/visitas', ['pagina' => '/admin/usuarios'])->assertOk()->assertJsonPath('registrada', false);
        // Cliente con sesión en una pantalla de administración: ve «sin permiso» y tampoco cuenta.
        $this->api('POST', '/api/visitas', ['pagina' => '/admin/usuarios'], $this->tokenDe(self::CLIENTE))
            ->assertOk()->assertJsonPath('registrada', false);
        $this->assertSame($antes, DB::table('bitacora')->where('entidad', 'ACCESO_WEB')->count());

        // El administrador sí la abre: cuenta.
        $this->api('POST', '/api/visitas', ['pagina' => '/admin/usuarios'], $this->tokenDe(self::ADMIN))
            ->assertOk()->assertJsonPath('registrada', true);
        $this->assertSame($antes + 1, DB::table('bitacora')->where('entidad', 'ACCESO_WEB')->count());
    }

    public function test_todas_las_entradas_del_menu_de_cada_rol_se_pueden_contar(): void
    {
        foreach ([self::ADMIN => 'ADMINISTRADOR', self::PERSONAL => 'PERSONAL_FEDER', self::PRODUCTORA => 'PRODUCTOR', self::CLIENTE => 'CLIENTE'] as $correo => $rol) {
            $token = $this->tokenDe($correo, $rol);
            $rutas = collect($this->api('GET', '/api/menu', [], $token)->json('grupos'))->flatMap(fn ($g) => array_column($g['entradas'], 'ruta'));
            $this->assertNotEmpty($rutas);
            foreach ($rutas as $ruta) {
                $this->api('POST', '/api/visitas', ['pagina' => $ruta], $token)->assertOk()->assertJsonPath('registrada', true);
            }
        }
    }

    public function test_una_pagina_con_formato_invalido_se_rechaza(): void
    {
        $this->api('POST', '/api/visitas', ['pagina' => 'catalogo'])->assertStatus(422)->assertJsonValidationErrors(['pagina']);
        $this->api('POST', '/api/visitas', ['pagina' => ['/catalogo']])->assertStatus(422)->assertJsonValidationErrors(['pagina']);
        $this->api('POST', '/api/visitas', [])->assertStatus(422)->assertJsonValidationErrors(['pagina']);
    }
}
