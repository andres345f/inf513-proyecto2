<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comercio\CompraFederRequest;
use App\Http\Requests\Comercio\ProductoRequest;
use App\Http\Requests\Comercio\RecepcionCompraRequest;
use App\Http\Requests\Productor\ActualizarFichaProductorRequest;
use App\Http\Requests\Productor\CambiarEstadoProduccionRequest;
use App\Http\Requests\Productor\LoteProduccionRequest;
use App\Http\Requests\Productor\ProduccionRequest;
use App\Http\Requests\Productor\PropuestaProductoRequest;
use App\Http\Requests\Productor\RechazarPropuestaRequest;
use App\Http\Requests\Productor\ResolverCambioComunidadRequest;
use App\Services\OperacionProductorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Acciones del ciclo Productor → oferta → compra → recepción → pago. */
class OperacionProductorController extends Controller
{
    public function __construct(private readonly OperacionProductorService $operaciones) {}

    public function opcionesProduccion(): JsonResponse
    {
        return response()->json($this->operaciones->opcionesProduccion());
    }

    public function crearProduccion(ProduccionRequest $request): JsonResponse
    {
        return response()->json($this->operaciones->crearProduccion($this->usuario($request), $request->validated(), $request->ip()), 201);
    }

    public function detalleProduccion(Request $request, int $produccion): JsonResponse
    {
        return response()->json($this->operaciones->detalleProduccion($this->usuario($request), $produccion));
    }

    public function actualizarProduccion(ProduccionRequest $request, int $produccion): JsonResponse
    {
        return response()->json($this->operaciones->actualizarProduccion($this->usuario($request), $produccion, $request->validated(), $request->ip()));
    }

    public function cambiarEstadoProduccion(CambiarEstadoProduccionRequest $request, int $produccion): JsonResponse
    {
        return response()->json($this->operaciones->cambiarEstadoProduccion($this->usuario($request), $produccion, $request->validated('estado'), $request->ip()));
    }

    public function crearLote(LoteProduccionRequest $request, int $produccion): JsonResponse
    {
        return response()->json($this->operaciones->guardarLote($this->usuario($request), $produccion, null, $request->validated(), $request->ip()), 201);
    }

    public function actualizarLote(LoteProduccionRequest $request, int $produccion, int $lote): JsonResponse
    {
        return response()->json($this->operaciones->guardarLote($this->usuario($request), $produccion, $lote, $request->validated(), $request->ip()));
    }

    public function retirarLote(Request $request, int $lote): JsonResponse
    {
        return response()->json($this->operaciones->retirarLote($this->usuario($request), $lote, $request->ip()));
    }

    public function propuestasProductor(Request $request): JsonResponse
    {
        return response()->json(['propuestas' => $this->operaciones->propuestasProducto($this->usuario($request)->id)]);
    }

    public function proponerProducto(PropuestaProductoRequest $request): JsonResponse
    {
        return response()->json($this->operaciones->proponerProducto($this->usuario($request), $request->validated(), $request->ip()), 201);
    }

    public function actualizarFicha(ActualizarFichaProductorRequest $request): JsonResponse
    {
        return response()->json($this->operaciones->actualizarFicha($this->usuario($request), $request->validated(), $request->ip()));
    }

    public function venta(Request $request, int $compra): JsonResponse
    {
        return response()->json($this->operaciones->ventaDelProductor($this->usuario($request), $compra));
    }

    public function lotesOfertados(): JsonResponse
    {
        return response()->json(['lotes' => $this->operaciones->lotesDisponiblesParaCompra()]);
    }

    public function crearCompra(CompraFederRequest $request): JsonResponse
    {
        return response()->json($this->operaciones->crearCompra($this->usuario($request), $request->validated(), $request->ip()), 201);
    }

    public function compra(int $compra): JsonResponse
    {
        return response()->json($this->operaciones->detalleCompra($compra));
    }

    public function recibirCompra(RecepcionCompraRequest $request, int $compra): JsonResponse
    {
        return response()->json($this->operaciones->recibirCompra($this->usuario($request), $compra, $request->validated(), $request->ip()));
    }

    public function pagarCompra(Request $request, int $compra): JsonResponse
    {
        return response()->json($this->operaciones->pagarCompra($this->usuario($request), $compra, $request->ip()));
    }

    public function propuestasGestion(): JsonResponse
    {
        return response()->json(['propuestas' => $this->operaciones->propuestasProducto()]);
    }

    public function cambiosComunidad(): JsonResponse
    {
        return response()->json(['solicitudes' => $this->operaciones->cambiosComunidad()]);
    }

    public function resolverCambioComunidad(ResolverCambioComunidadRequest $request, int $solicitud): JsonResponse
    {
        return response()->json($this->operaciones->resolverCambioComunidad(
            $this->usuario($request), $solicitud, (bool) $request->validated('aprobar'), $request->validated('motivo'), $request->ip()
        ));
    }

    public function aprobarPropuesta(ProductoRequest $request, int $propuesta): JsonResponse
    {
        return response()->json($this->operaciones->resolverPropuestaProducto($this->usuario($request), $propuesta, true, $request->validated(), $request->ip()));
    }

    public function rechazarPropuesta(RechazarPropuestaRequest $request, int $propuesta): JsonResponse
    {
        return response()->json($this->operaciones->resolverPropuestaProducto($this->usuario($request), $propuesta, false, $request->validated(), $request->ip()));
    }

    private function usuario(Request $request)
    {
        return $this->sesionActual($request)->usuario;
    }
}
