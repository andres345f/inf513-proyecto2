<?php

namespace Tests\Unit;

use App\Models\ConfirmacionOperacion;
use App\Repositories\DatosWebUsuarioRepository;
use App\Services\PlanPagoService;
use PHPUnit\Framework\TestCase;

class PlanPagoServiceTest extends TestCase
{
    public function test_no_bloquea_si_la_primera_cuota_nunca_se_pago(): void
    {
        $servicio = $this->servicioConCuotas([
            ['estado' => 'PENDIENTE', 'vence' => '2020-01-01'],
            ['estado' => 'PENDIENTE', 'vence' => '2020-01-08'],
        ]);

        $this->assertFalse($servicio->tieneCuotaVencida(7));
    }

    public function test_bloquea_si_una_cuota_posterior_esta_vencida(): void
    {
        $servicio = $this->servicioConCuotas([
            ['estado' => 'PAGADA', 'vence' => '2020-01-01'],
            ['estado' => 'PENDIENTE', 'vence' => '2020-01-08'],
        ]);

        $this->assertTrue($servicio->tieneCuotaVencida(7));
    }

    private function servicioConCuotas(array $cuotas): PlanPagoService
    {
        $plan = new ConfirmacionOperacion([
            'parametros' => ['estado' => 'ACTIVO', 'cuotas' => $cuotas],
        ]);
        $repositorio = $this->createMock(DatosWebUsuarioRepository::class);
        $repositorio->expects($this->once())
            ->method('vigentes')
            ->with(7, DatosWebUsuarioRepository::PLAN_PAGO)
            ->willReturn(collect([$plan]));

        return new PlanPagoService($repositorio);
    }
}
