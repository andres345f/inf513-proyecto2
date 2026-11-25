<?php

namespace App\Http\Middleware;

use App\Exceptions\NegocioException;
use App\Services\PlanPagoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regla del plan de cuotas: con una cuota vencida el cliente no puede agregar al carrito ni crear pedidos
 * hasta ponerse al día (sí puede pagar).
 */
class BloquearPorCuotaVencida
{
    public function __construct(private readonly PlanPagoService $planes)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        auth('api')->user();
        $sesion = $request->attributes->get('sesion');

        if ($sesion && $this->planes->tieneCuotaVencida($sesion->usuario->id)) {
            throw new NegocioException('Tienes una cuota vencida. Págala desde "Planes de pago" para volver a comprar.', 403);
        }

        return $next($request);
    }
}
