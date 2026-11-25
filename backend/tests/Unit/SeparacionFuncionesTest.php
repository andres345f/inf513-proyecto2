<?php

namespace Tests\Unit;

use App\Exceptions\NegocioException;
use App\Models\Usuario;
use App\Support\SeparacionFunciones;
use PHPUnit\Framework\TestCase;

class SeparacionFuncionesTest extends TestCase
{
    public function test_rechaza_el_acto_sobre_un_pedido_propio(): void
    {
        $actor = new Usuario();
        $actor->id = 3;

        try {
            SeparacionFunciones::exigirAjeno($actor, (object) ['usuario_id' => 3, 'codigo' => 'PED-1'], 'confirmar el pago');
            $this->fail('Debía rechazarse');
        } catch (NegocioException $e) {
            $this->assertSame(403, $e->estadoHttp());
            $this->assertStringContainsString('PED-1', $e->getMessage());
        }
    }

    public function test_permite_el_acto_sobre_un_pedido_ajeno(): void
    {
        $actor = new Usuario();
        $actor->id = 3;

        SeparacionFunciones::exigirAjeno($actor, (object) ['usuario_id' => 9, 'codigo' => 'PED-2'], 'confirmar el pago');
        $this->addToAssertionCount(1);
    }
}
