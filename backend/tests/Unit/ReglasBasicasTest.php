<?php

namespace Tests\Unit;

use App\Support\Cantidad;
use App\Support\TarjetaSimulada;
use PHPUnit\Framework\TestCase;

class ReglasBasicasTest extends TestCase
{
    public function test_formatea_cantidades_y_montos_sin_perder_precision_comercial(): void
    {
        $this->assertSame('12.346', Cantidad::texto(12.3456));
        $this->assertSame('12.35', Cantidad::monto(12.345));
        $this->assertTrue(Cantidad::esEntera(4.0));
        $this->assertFalse(Cantidad::esEntera(4.5));
    }

    public function test_reconoce_las_tarjetas_de_demostracion(): void
    {
        $this->assertTrue(TarjetaSimulada::luhnValido('4242 4242 4242 4242'));
        $this->assertSame('VISA', TarjetaSimulada::marca('4242 4242 4242 4242'));
        $this->assertSame(TarjetaSimulada::APROBADA, TarjetaSimulada::comportamiento('4242 4242 4242 4242'));
        $this->assertSame(TarjetaSimulada::FONDOS_INSUFICIENTES, TarjetaSimulada::comportamiento('4000 0000 0000 0002'));
        $this->assertFalse(TarjetaSimulada::luhnValido('1234'));
    }
}
