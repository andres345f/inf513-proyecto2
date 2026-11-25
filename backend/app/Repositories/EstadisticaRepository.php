<?php

namespace App\Repositories;

use App\Models\Bitacora;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de solo lectura para el panel (CU7/CU8) y la auditoría de accesos.
 * Fechas: $desde y $hasta son días (Y-m-d) inclusivos, en la zona de la conexión (America/La_Paz).
 *
 * Definiciones de los indicadores de negocio (H-21):
 * - Ingresos cobrados (criterio de caja): pagos PAGADO por su fecha de pago. Los reintegros EJECUTADO
 *   restan en su fecha de ejecución, así un pago luego reembolsado no queda como ingreso.
 * - Pedido vendido: pedido creado en el período que llegó a confirmarse (CONFIRMADO, EN_PREPARACION,
 *   DESPACHADO o ENTREGADO). No cuentan los pendientes de pago, los encargos sin abastecer ni los cancelados.
 * - Productos más vendidos: líneas de los pedidos vendidos (cantidades brutas, sin descontar devoluciones).
 */
class EstadisticaRepository
{
    /** Estados de un pedido que ya es una venta (confirmado comercialmente y no cancelado). */
    public const ESTADOS_VENDIDOS = ['CONFIRMADO', 'EN_PREPARACION', 'DESPACHADO', 'ENTREGADO'];

    public function __construct(private readonly CatalogoRepository $catalogo)
    {
    }

    /** Suma de pagos confirmados (bruto, antes de reintegros). */
    public function cobrosBrutos(string $desde, string $hasta): float
    {
        return (float) DB::table('pago')->where('estado', 'PAGADO')
            ->whereBetween(DB::raw('fecha_pago::date'), [$desde, $hasta])->sum('monto');
    }

    /** Suma de reintegros ya ejecutados en el período (por pedidos cancelados o devoluciones). */
    public function reintegrosEjecutados(string $desde, string $hasta): float
    {
        return (float) DB::table('reembolso')->where('estado', 'EJECUTADO')
            ->whereBetween(DB::raw('fecha_ejecucion::date'), [$desde, $hasta])->sum('monto');
    }

    /** Pedidos vendidos del período: cantidad y suma de sus totales. */
    public function pedidosVendidos(string $desde, string $hasta): object
    {
        return DB::table('pedido')->whereIn('estado', self::ESTADOS_VENDIDOS)
            ->whereBetween(DB::raw('fecha_creacion::date'), [$desde, $hasta])
            ->first([DB::raw('COUNT(*) AS cantidad'), DB::raw('COALESCE(SUM(total), 0) AS total')]);
    }

    public function clientesNuevos(string $desde, string $hasta): int
    {
        return DB::table('usuario as u')->join('usuario_rol as ur', 'ur.usuario_id', '=', 'u.id')->join('rol as r', 'r.id', '=', 'ur.rol_id')
            ->where('r.codigo', 'CLIENTE')->whereBetween(DB::raw('u.fecha_registro::date'), [$desde, $hasta])->distinct()->count('u.id');
    }

    public function pedidosEnCurso(): int
    {
        return DB::table('pedido')->whereIn('estado', ['PENDIENTE', 'ESPERANDO_ABASTECIMIENTO', 'CONFIRMADO', 'EN_PREPARACION', 'DESPACHADO'])->count();
    }

    public function productosConStockBajo(): int
    {
        return DB::table('producto as p')
            ->leftJoinSub($this->catalogo->stockPorProducto(), 's', 's.producto_id', '=', 'p.id')
            ->where('p.activo', true)
            ->whereRaw('COALESCE(s.vendible, 0) < p.stock_minimo')
            ->count();
    }

    /** Por mes: cobrado (bruto), reintegrado y neto. Cada movimiento cuenta en el mes de su propia fecha. */
    public function ventasPorMes(string $desde, string $hasta): Collection
    {
        return DB::query()->fromSub($this->movimientosDeCaja($desde, $hasta), 'm')
            ->groupBy('mes')->orderBy('mes')
            ->get(['mes', DB::raw('SUM(cobrado) AS cobrado'), DB::raw('SUM(reintegrado) AS reintegrado'),
                DB::raw('SUM(cobrado) - SUM(reintegrado) AS total'), DB::raw('SUM(pagos) AS pagos')]);
    }

    public function pedidosPorEstado(string $desde, string $hasta): Collection
    {
        return DB::table('pedido')->whereBetween(DB::raw('fecha_creacion::date'), [$desde, $hasta])
            ->groupBy('estado')->get(['estado', DB::raw('COUNT(*) AS cantidad')]);
    }

    public function productosMasVendidos(string $desde, string $hasta, int $limite = 5): Collection
    {
        return DB::table('detalle_pedido as d')->join('pedido as p', 'p.id', '=', 'd.pedido_id')->join('producto as pr', 'pr.id', '=', 'd.producto_id')
            ->whereIn('p.estado', self::ESTADOS_VENDIDOS)->whereBetween(DB::raw('p.fecha_creacion::date'), [$desde, $hasta])
            ->groupBy('pr.id', 'pr.nombre')->orderByDesc(DB::raw('SUM(d.subtotal)'))->orderBy('pr.nombre')->limit($limite)
            ->get(['pr.nombre', DB::raw('SUM(d.cantidad) AS cantidad'), DB::raw('SUM(d.subtotal) AS monto')]);
    }

    /** Por medio de pago: cobrado (bruto), reintegrado sobre pagos de ese medio, neto y cantidad de pagos. */
    public function pagosPorMetodo(string $desde, string $hasta): Collection
    {
        return DB::query()->fromSub($this->movimientosDeCaja($desde, $hasta), 'm')
            ->groupBy('metodo')->orderBy('metodo')
            ->get(['metodo', DB::raw('SUM(cobrado) AS cobrado'), DB::raw('SUM(reintegrado) AS reintegrado'),
                DB::raw('SUM(cobrado) - SUM(reintegrado) AS monto'), DB::raw('SUM(pagos) AS cantidad')]);
    }

    /** Pagos confirmados (+) y reintegros ejecutados (−) del período, con su mes y el medio del pago original. */
    private function movimientosDeCaja(string $desde, string $hasta)
    {
        $reintegros = DB::table('reembolso as r')->join('pago as g', 'g.id', '=', 'r.pago_id')
            ->where('r.estado', 'EJECUTADO')->whereBetween(DB::raw('r.fecha_ejecucion::date'), [$desde, $hasta])
            ->select([DB::raw("to_char(r.fecha_ejecucion, 'YYYY-MM') AS mes"), 'g.metodo',
                DB::raw('0::numeric AS cobrado'), DB::raw('r.monto AS reintegrado'), DB::raw('0 AS pagos')]);

        return DB::table('pago')->where('estado', 'PAGADO')->whereBetween(DB::raw('fecha_pago::date'), [$desde, $hasta])
            ->select([DB::raw("to_char(fecha_pago, 'YYYY-MM') AS mes"), 'metodo',
                DB::raw('monto AS cobrado'), DB::raw('0::numeric AS reintegrado'), DB::raw('1 AS pagos')])
            ->unionAll($reintegros);
    }

    // ---------------- Accesos al sitio (bitácora) ----------------

    public function visitasPorDia(string $desde, string $hasta): Collection
    {
        return $this->accesos($desde, $hasta)->groupBy(DB::raw('fecha::date'))->orderBy(DB::raw('fecha::date'))
            ->get([DB::raw('fecha::date AS dia'), DB::raw('COUNT(*) AS visitas')]);
    }

    public function paginasMasVisitadas(string $desde, string $hasta, int $limite = 8): Collection
    {
        return $this->accesos($desde, $hasta)->groupBy(DB::raw("detalle->>'pagina'"))->orderByDesc(DB::raw('COUNT(*)'))->limit($limite)
            ->get([DB::raw("detalle->>'pagina' AS pagina"), DB::raw('COUNT(*) AS visitas')]);
    }

    public function totalVisitas(string $desde, string $hasta): int
    {
        return $this->accesos($desde, $hasta)->count();
    }

    public function inicioSesionPorDia(string $desde, string $hasta): Collection
    {
        return $this->inicios($desde, $hasta)->groupBy(DB::raw('fecha::date'))->orderBy(DB::raw('fecha::date'))
            ->get([DB::raw('fecha::date AS dia'),
                DB::raw("COUNT(*) FILTER (WHERE detalle->>'resultado' = 'EXITO') AS exitosos"),
                DB::raw("COUNT(*) FILTER (WHERE detalle->>'resultado' = 'FALLIDO') AS fallidos")]);
    }

    public function ipsConMasFallos(string $desde, string $hasta, int $limite = 5): Collection
    {
        return $this->inicios($desde, $hasta)->whereRaw("detalle->>'resultado' = 'FALLIDO'")->whereNotNull('direccion_ip')
            ->groupBy('direccion_ip')->orderByDesc(DB::raw('COUNT(*)'))->limit($limite)
            ->get([DB::raw('host(direccion_ip) AS ip'), DB::raw('COUNT(*) AS fallidos')]);
    }

    /** Bitácora completa con filtros (administración). */
    public function bitacora(array $filtros): LengthAwarePaginator
    {
        $consulta = DB::table('bitacora as b')->leftJoin('usuario as u', 'u.id', '=', 'b.usuario_id')
            ->select(['b.id', 'b.fecha', 'b.accion', 'b.entidad', 'b.entidad_id', 'b.detalle', DB::raw('host(b.direccion_ip) AS ip'),
                'u.correo', DB::raw("u.nombres || ' ' || u.apellidos AS usuario")])
            ->orderByDesc('b.fecha')->orderByDesc('b.id');

        if (! empty($filtros['usuario'])) {
            $patron = '%'.addcslashes($filtros['usuario'], '%_\\').'%';
            $consulta->where(fn ($q) => $q->whereRaw('u.correo ILIKE ?', [$patron])->orWhereRaw("(u.nombres || ' ' || u.apellidos) ILIKE ?", [$patron])
                ->orWhereRaw("b.detalle->>'correo' ILIKE ?", [$patron]));
        }
        foreach (['accion' => 'b.accion', 'entidad' => 'b.entidad'] as $filtro => $columna) {
            if (! empty($filtros[$filtro])) {
                $consulta->where($columna, $filtros[$filtro]);
            }
        }
        if (! empty($filtros['resultado'])) {
            $consulta->whereRaw("b.detalle->>'resultado' = ?", [$filtros['resultado']]);
        }
        if (! empty($filtros['ip'])) {
            $consulta->whereRaw('host(b.direccion_ip) LIKE ?', [addcslashes($filtros['ip'], '%_\\').'%']);
        }
        if (! empty($filtros['desde'])) {
            $consulta->whereDate('b.fecha', '>=', $filtros['desde']);
        }
        if (! empty($filtros['hasta'])) {
            $consulta->whereDate('b.fecha', '<=', $filtros['hasta']);
        }

        return $consulta->paginate(20, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));
    }

    private function accesos(string $desde, string $hasta)
    {
        return DB::table('bitacora')->where('entidad', Bitacora::ENTIDAD_ACCESO_WEB)->whereBetween(DB::raw('fecha::date'), [$desde, $hasta]);
    }

    private function inicios(string $desde, string $hasta)
    {
        return DB::table('bitacora')->where('accion', Bitacora::INICIO_SESION)->whereBetween(DB::raw('fecha::date'), [$desde, $hasta]);
    }
}
