<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Repositories\PedidoRepository;
use App\Support\SesionActiva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Consulta de facturas y registro administrativo de anulaciones sustentadas.
 */
class FacturaService
{
    public function __construct(private readonly PedidoRepository $pedidos, private readonly AuditoriaService $auditoria)
    {
    }

    public function anular(SesionActiva $sesion, int $id, array $datos, ?string $ip): array
    {
        if ($sesion->rolCodigo !== 'ADMINISTRADOR') {
            throw new NegocioException('Solo Administración puede registrar la anulación de una factura.', 403);
        }

        return DB::transaction(function () use ($sesion, $id, $datos, $ip) {
            $factura = $this->pedidos->facturaParaAnular($id) ?? throw new NegocioException('Esa factura no existe.', 404);
            if ($factura->estado !== 'VIGENTE') {
                throw new NegocioException('La factura ya está anulada.', 409);
            }
            $fecha = CarbonImmutable::parse($datos['fecha']);
            if ($fecha->lt(CarbonImmutable::parse($factura->fecha_emision)) || $fecha->isFuture()) {
                throw new NegocioException('La fecha de la anulación debe estar entre la emisión y el momento actual.', 422);
            }
            $this->pedidos->anularFactura($id, $fecha->toIso8601String());
            $this->auditoria->operacion($sesion->usuario->id, 'ANULAR', 'FACTURA', $id, [
                'operacion' => 'REGISTRAR_ANULACION_DOCUMENTADA', 'pedido_id' => (int) $factura->pedido_id,
                'numero' => $factura->numero, 'motivo' => $datos['motivo'], 'referencia' => $datos['referencia'],
                'antes' => ['estado' => 'VIGENTE', 'fecha_anulacion' => $factura->fecha_anulacion],
                'despues' => ['estado' => 'ANULADA', 'fecha_anulacion' => $fecha->toIso8601String()],
            ], $ip);

            return ['mensaje' => 'Se registró la anulación documentada de la factura.', 'estado' => 'ANULADA'];
        });
    }

    public function listar(int $clienteId): array
    {
        return $this->pedidos->facturasDelCliente($clienteId)->map(fn ($f) => [
            'id' => (int) $f->id,
            'numero' => $f->numero,
            'fecha' => $f->fecha_emision,
            'total' => (float) $f->total,
            'estado' => $f->estado,
            'pedido_id' => (int) $f->pedido_id,
            'pedido' => $f->pedido,
        ])->all();
    }

    public function detalle(int $clienteId, int $facturaId): array
    {
        $f = $this->pedidos->facturaDelCliente($facturaId, $clienteId) ?? throw new NegocioException('Esa factura no existe.', 404);

        return [
            'id' => (int) $f->id,
            'numero' => $f->numero,
            'fecha' => $f->fecha_emision,
            'fecha_anulacion' => $f->fecha_anulacion,
            'estado' => $f->estado,
            'nit_ci' => $f->nit_ci,
            'razon_social' => $f->razon_social,
            'codigo_autorizacion' => $f->codigo_autorizacion,
            'pedido' => $f->pedido,
            'pedido_id' => (int) $f->pedido_id,
            'subtotal' => (float) $f->subtotal,
            'costo_envio' => (float) $f->costo_envio,
            'total' => (float) $f->total,
            'lineas' => $this->pedidos->lineas((int) $f->pedido_id)->map(fn ($l) => [
                'descripcion' => $l->descripcion_venta,
                'cantidad' => (float) $l->cantidad,
                'precio' => (float) $l->precio_unitario,
                'subtotal' => (float) $l->subtotal,
            ])->all(),
        ];
    }
}
