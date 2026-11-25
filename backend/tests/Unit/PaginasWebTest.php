<?php

namespace Tests\Unit;

use App\Support\PaginasWeb;
use App\Support\TextoPersona;
use PHPUnit\Framework\TestCase;

class PaginasWebTest extends TestCase
{
    public function test_reconoce_pantallas_publicas_privadas_y_de_detalle(): void
    {
        $this->assertSame(['pagina' => '/', 'permiso' => null], PaginasWeb::resolver('/'));
        $this->assertSame(['pagina' => '/catalogo', 'permiso' => null], PaginasWeb::resolver('/catalogo/'));
        $this->assertSame(['pagina' => '/catalogo/15', 'permiso' => null], PaginasWeb::resolver('/Catalogo/15'));
        $this->assertSame(['pagina' => '/mis-pedidos/7', 'permiso' => 'pedidos.propios'], PaginasWeb::resolver('/mis-pedidos/7'));
        $this->assertSame(['pagina' => '/admin/usuarios', 'permiso' => 'usuarios.gestionar'], PaginasWeb::resolver('/admin/usuarios'));
    }

    public function test_rechaza_paginas_inventadas_o_con_identificadores_no_numericos(): void
    {
        foreach (['/no-existe', '/catalogo/abc', '/catalogo/5/extra', '/mis-pedidos/-1', '/admin', '/pagina/<b>x</b>', '', '/catalogo/1234567890123456789'] as $pagina) {
            $this->assertNull(PaginasWeb::resolver($pagina), $pagina);
        }
    }

    public function test_acepta_las_entradas_del_menu_guardado_aunque_no_tengan_pantalla_fija(): void
    {
        $menu = [['ruta' => '/novedades', 'permiso' => 'inicio.ver'], ['ruta' => '/Ayuda/', 'permiso' => 'perfil.ver']];

        $this->assertSame(['pagina' => '/novedades', 'permiso' => 'inicio.ver'], PaginasWeb::resolver('/novedades', $menu));
        $this->assertSame(['pagina' => '/ayuda', 'permiso' => 'perfil.ver'], PaginasWeb::resolver('/ayuda', $menu));
        $this->assertNull(PaginasWeb::resolver('/otra', $menu));
    }

    public function test_los_nombres_de_persona_no_admiten_caracteres_de_control(): void
    {
        $expresion = substr(TextoPersona::REGLA, strlen('regex:'));

        foreach (['María José', "D'Ávila", 'Núñez-Roca', 'Ma. Luisa', 'Ñuflo'] as $valido) {
            $this->assertSame(1, preg_match($expresion, $valido), $valido);
        }
        foreach (["Ana\nMaría", "Ana\tMaría", "Ana\r\nMaría", "Ana\x00", ' Ana', '9Ana', "Ana\x0B"] as $invalido) {
            $this->assertSame(0, preg_match($expresion, $invalido), json_encode($invalido));
        }
        $this->assertSame('Ana', TextoPersona::limpiar("  Ana \n"));
        $this->assertSame(['Ana'], TextoPersona::limpiar(['Ana']));
        $this->assertSame('a@b.co', TextoPersona::limpiarCorreo(' A@B.co '));
    }
}
