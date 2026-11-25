<?php

namespace Tests\Integracion\Busqueda;

use Illuminate\Support\Facades\DB;
use Tests\Integracion\IntegracionTestCase;

/**
 * H-32: la búsqueda global no distingue tildes ni mayúsculas («cafe» encuentra «Café»),
 * sin extensiones ni cambios de esquema (translate() de PostgreSQL).
 */
class BusquedaSinTildesTest extends IntegracionTestCase
{
    /** @return list<string> */
    private function nombresDeProductos(string $texto): array
    {
        $respuesta = $this->getJson('/api/buscar?q='.rawurlencode($texto))->assertOk();

        return array_column($respuesta->json('productos'), 'nombre');
    }

    /** Productos activos cuyo nombre contiene el texto con o sin tilde, calculado aparte en SQL (máximo 5, por nombre). */
    private function esperadosPorSql(string $conTilde): array
    {
        return DB::table('producto')->where('activo', true)
            ->whereRaw('nombre ILIKE ?', ['%'.$conTilde.'%'])
            ->orderBy('nombre')->limit(5)->pluck('nombre')->all();
    }

    public function test_sin_tilde_encuentra_lo_mismo_que_con_tilde(): void
    {
        foreach (['cafe' => 'café', 'jabon' => 'jabón', 'totai' => 'totaí'] as $sinTilde => $conTilde) {
            $esperados = $this->esperadosPorSql($conTilde);
            $this->assertNotEmpty($esperados, "Los datos de prueba deberían tener productos con «{$conTilde}».");
            $this->assertSame($esperados, $this->nombresDeProductos($sinTilde), "«{$sinTilde}» debe encontrar lo mismo que «{$conTilde}».");
            $this->assertSame($esperados, $this->nombresDeProductos($conTilde), "«{$conTilde}» debe seguir funcionando.");
        }
    }

    public function test_no_distingue_mayusculas_con_tilde(): void
    {
        $this->assertSame($this->nombresDeProductos('café'), $this->nombresDeProductos('CAFÉ'));
        $this->assertSame($this->nombresDeProductos('café'), $this->nombresDeProductos('Cafe'));
    }

    public function test_comunidades_y_categorias_sin_tilde(): void
    {
        $comunidad = DB::table('comunidad_agricola')->where('activa', true)->whereRaw("nombre ~ '[áéíóú]'")->orderBy('id')->value('nombre');
        $this->assertNotNull($comunidad, 'Se necesita una comunidad activa con tilde en los datos de prueba.');
        $sinTilde = strtr(mb_strtolower($comunidad), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        $respuesta = $this->getJson('/api/buscar?q='.rawurlencode($sinTilde))->assertOk();
        $this->assertContains($comunidad, array_column($respuesta->json('comunidades'), 'nombre'));
    }

    public function test_los_comodines_se_buscan_como_texto(): void
    {
        $this->assertSame([], $this->nombresDeProductos('%%'));
        $this->assertSame([], $this->nombresDeProductos('__'));
    }
}
