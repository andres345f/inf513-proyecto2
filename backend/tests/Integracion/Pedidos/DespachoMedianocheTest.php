<?php

namespace Tests\Integracion\Pedidos;

use App\Repositories\InventarioRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL fija current_date al inicio de la transacción. Un despacho
 * que empieza un día y descuenta el lote después de la medianoche podía consumir un lote que acababa de vencer.
 * El vencimiento se revalida con el reloj real (clock_timestamp) en la fecha civil de la sesión (America/La_Paz, H-14),
 * también dentro del UPDATE condicional del descuento.
 */
class DespachoMedianocheTest extends PedidosTestCase
{
    /**
     * Fechas controladas: elige para la sesión una zona con desplazamiento en minutos cuya medianoche cae entre el
     * inicio de la transacción (now(), que fija current_date) y el reloj real (clock_timestamp()). Así la transacción
     * «empezó» un día y lo que sigue ocurre al siguiente, como un despacho iniciado a las 23:59:59 que descuenta a las
     * 00:00:02. Como PostgreSQL solo admite desplazamientos de minutos, espera si hace falta al siguiente cambio de
     * minuto (como máximo 60 s). SET LOCAL se deshace con la transacción de la prueba.
     *
     * @return array{0: string, 1: string} fecha civil al iniciar la transacción y fecha civil real, en la zona de la sesión
     */
    private function cruzarMedianoche(): array
    {
        $inicio = (float) DB::selectOne('SELECT extract(epoch FROM now()) AS t')->t;
        $medianoche = (int) (floor($inicio / 60) + 1) * 60;
        $reloj = (float) DB::selectOne('SELECT extract(epoch FROM clock_timestamp()) AS t')->t;
        if ($reloj < $medianoche + 0.3) {
            usleep((int) (($medianoche + 0.3 - $reloj) * 1_000_000));
        }

        $minutos = intdiv((86400 - $medianoche % 86400) % 86400, 60);
        if ($minutos > 14 * 60) {
            $minutos -= 24 * 60;
        }
        DB::statement(sprintf("SET LOCAL TIME ZONE INTERVAL '%s%02d:%02d' HOUR TO MINUTE",
            $minutos < 0 ? '-' : '+', intdiv(abs($minutos), 60), abs($minutos) % 60));
        $fechas = DB::selectOne('SELECT current_date::text AS al_iniciar, CAST(clock_timestamp() AS date)::text AS real');

        return [$fechas->al_iniciar, $fechas->real];
    }

    public function test_un_despacho_que_cruza_la_medianoche_no_consume_un_lote_que_acaba_de_vencer(): void
    {
        $producto = $this->crearProducto();
        $lote = $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$producto => 2]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION');
        $token = $this->personal(); // la sesión se abre antes de cambiar la zona de la conexión

        [$alIniciar, $real] = $this->cruzarMedianoche();
        $this->assertSame(Carbon::parse($alIniciar)->addDay()->toDateString(), $real, 'La transacción empezó el día anterior al reloj real.');
        // El lote vence el día en que empezó la transacción: según el reloj real ya venció.
        $this->fijarVencimiento($lote, $alIniciar);
        $antes = $this->fotoInventario($pedido);

        $respuesta = $this->avanzar($token, $pedido, ['hacia' => 'DESPACHADO']);

        $respuesta->assertStatus(409);
        $this->assertStringContainsString('venci', $respuesta->json('message'));
        $this->assertSame($antes, $this->fotoInventario($pedido), 'No se consume nada del lote vencido.');
        $this->assertCount(0, $this->salidas($pedido));
        $this->assertSame(['10.000', '2.000'], [$this->lote($lote)->cantidad_disponible, $this->lote($lote)->cantidad_reservada]);
    }

    public function test_el_descuento_condicional_revalida_el_vencimiento_con_el_reloj_real(): void
    {
        // Fechas controladas en el calendario de Bolivia: un lote que venció ayer no se descuenta aunque la
        // comprobación previa lo hubiera dado por bueno; uno que vence hoy todavía sí.
        $repositorio = app(InventarioRepository::class);
        $producto = $this->crearProducto();
        $vencido = $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$producto => 3]);
        $reserva = $this->reservas($pedido)[0];
        $this->assertSame($vencido, (int) $reserva->lote_feder_id);

        $this->fijarVencimiento($vencido, $this->ayer());
        $this->assertFalse($repositorio->descontarReservaDelLote($reserva));
        $this->assertSame(['10.000', '3.000', 'DISPONIBLE'],
            [$this->lote($vencido)->cantidad_disponible, $this->lote($vencido)->cantidad_reservada, $this->lote($vencido)->estado]);

        $this->fijarVencimiento($vencido, now()->toDateString());
        $this->assertTrue($repositorio->descontarReservaDelLote($reserva));
        $this->assertSame(['7.000', '0.000'], [$this->lote($vencido)->cantidad_disponible, $this->lote($vencido)->cantidad_reservada]);
    }
}
