<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EstadoSistemaTest extends TestCase
{
    public function test_la_api_informa_que_el_sistema_esta_operativo(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([(object) ['?column?' => 1]]);
        DB::shouldReceive('getConnections')->zeroOrMoreTimes()->andReturn([]);

        $response = $this->getJson('/api/ping');

        $response->assertOk()
            ->assertJsonPath('estado', 'ok')
            ->assertJsonPath('sistema', 'FEDER')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_cors_no_autoriza_un_origen_desconocido(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([(object) ['?column?' => 1]]);
        DB::shouldReceive('getConnections')->zeroOrMoreTimes()->andReturn([]);

        $response = $this->getJson('/api/ping', ['Origin' => 'https://sitio-no-autorizado.example']);

        $response->assertOk();
        $origenPermitido = $response->headers->get('Access-Control-Allow-Origin');
        $this->assertNotSame('*', $origenPermitido);
        $this->assertNotSame('https://sitio-no-autorizado.example', $origenPermitido);
    }
}
