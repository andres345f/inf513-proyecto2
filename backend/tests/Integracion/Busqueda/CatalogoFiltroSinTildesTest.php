<?php

namespace Tests\Integracion\Busqueda;

use Illuminate\Support\Facades\DB;
use Tests\Integracion\IntegracionTestCase;

/** H-32 (catálogo): el filtro «q» del catálogo no distingue tildes ni mayúsculas, igual que la búsqueda global. */
class CatalogoFiltroSinTildesTest extends IntegracionTestCase
{
    public function test_el_filtro_q_encuentra_productos_escritos_sin_tilde(): void
    {
        foreach (['totai' => 'totaí', 'cafe' => 'café', 'JABON' => 'jabón'] as $buscado => $real) {
            $esperados = DB::table('producto as p')->join('categoria as c', 'c.id', '=', 'p.categoria_id')
                ->where('p.activo', true)->where('c.activa', true)
                ->whereRaw('(p.nombre ILIKE ? OR p.descripcion ILIKE ? OR c.nombre ILIKE ?)', array_fill(0, 3, "%{$real}%"))
                ->pluck('p.id')->sort()->values()->all();
            $this->assertNotEmpty($esperados, "Los datos de prueba deberían tener productos con «{$real}».");

            $obtenidos = collect($this->getJson('/api/catalogo?q='.urlencode($buscado).'&pagina=1')->assertOk()->json('productos'))
                ->pluck('id')->sort()->values()->all();

            $this->assertSame($esperados, $obtenidos, "«{$buscado}» debería encontrar lo mismo que «{$real}».");
        }
    }

    public function test_los_comodines_se_buscan_como_texto(): void
    {
        // «%» no actúa como comodín: solo aparecen los productos que contienen ese carácter (p. ej. «70 % cacao»).
        $esperados = DB::table('producto as p')->join('categoria as c', 'c.id', '=', 'p.categoria_id')
            ->where('p.activo', true)->where('c.activa', true)
            ->whereRaw("(strpos(p.nombre, '%') > 0 OR strpos(coalesce(p.descripcion, ''), '%') > 0 OR strpos(c.nombre, '%') > 0)")
            ->pluck('p.id')->sort()->values()->all();
        $todos = DB::table('producto as p')->join('categoria as c', 'c.id', '=', 'p.categoria_id')
            ->where('p.activo', true)->where('c.activa', true)->count();
        $this->assertLessThan($todos, count($esperados), 'Los datos de prueba deberían tener productos sin «%».');

        $obtenidos = collect($this->getJson('/api/catalogo?q='.urlencode('%').'&pagina=1')->assertOk()->json('productos'))
            ->pluck('id')->sort()->values()->all();

        $this->assertSame($esperados, $obtenidos);
    }
}
