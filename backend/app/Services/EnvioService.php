<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Repositories\ConfiguracionWebRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Costo de envío estilo Yango: tarifa mínima + km + minutos por calles (OpenRouteService),
 * moto o auto según el peso del pedido. Si el servicio de rutas falla, se estima en línea recta × factor.
 */
class EnvioService
{
    private const VELOCIDAD_RESPALDO_KMH = 25;

    public function __construct(private readonly ConfiguracionWebRepository $configuracion)
    {
    }

    /**
     * @param  Collection  $items  ítems con producto_id, cantidad y unidad_medida
     */
    public function cotizar(float $lat, float $lng, Collection $items): array
    {
        $tarifa = $this->configuracion->tarifaEnvio();
        if ($tarifa === []) {
            throw new NegocioException('La tarifa de envío no está configurada. Comunícate con FEDER.', 503);
        }

        $this->validarZona($lat, $lng);

        $peso = $this->pesoEstimado($items, $tarifa);
        $vehiculo = $peso > ($tarifa['peso_maximo_moto_kg'] ?? 20) ? 'AUTO' : 'MOTO';
        $origen = $tarifa['origen'];
        $ruta = $this->rutaPorCalles($origen['lat'], $origen['lng'], $lat, $lng)
            ?? $this->rutaEstimada($origen['lat'], $origen['lng'], $lat, $lng, (float) ($tarifa['factor_respaldo_linea_recta'] ?? 1.3));

        $fueraDeCobertura = $ruta['km'] > (float) $tarifa['cobertura_km'];
        $p = $tarifa['vehiculos'][$vehiculo];
        $costo = $fueraDeCobertura ? null : (int) round(
            $p['minimo']
            + max(0, $ruta['km'] - $p['km_incluidos']) * $p['precio_km']
            + max(0, $ruta['minutos'] - $p['min_incluidos']) * $p['precio_min']
        );

        return [
            'distancia_km' => round($ruta['km'], 1),
            'duracion_min' => (int) round($ruta['minutos']),
            'peso_kg' => round($peso, 1),
            'vehiculo' => $vehiculo,
            'costo' => $costo,
            'estimado' => $ruta['estimado'],
            'fuera_de_cobertura' => $fueraDeCobertura,
            'cobertura_km' => (float) $tarifa['cobertura_km'],
            'origen' => $origen,
        ];
    }

    /** El almacén está en Santa Cruz de la Sierra: se descartan coordenadas claramente fuera del área. */
    private function validarZona(float $lat, float $lng): void
    {
        if ($lat < -18.5 || $lat > -17.0 || $lng < -64.0 || $lng > -62.5) {
            throw new NegocioException('Solo entregamos dentro del área metropolitana de Santa Cruz. Elige otra ubicación o recoge tu pedido en el almacén.');
        }
    }

    private function pesoEstimado(Collection $items, array $tarifa): float
    {
        $pesos = $this->configuracion->pesosDeProductos($items->pluck('producto_id')->map(fn ($id) => (int) $id)->all());
        $porUnidad = $tarifa['peso_por_unidad_kg'] ?? [];

        return $items->sum(fn ($i) => (float) $i->cantidad
            * ($pesos[(int) $i->producto_id] ?? (float) ($porUnidad[$i->unidad_medida] ?? 0.5)));
    }

    /**
     * Dirección aproximada del punto marcado en el mapa (geocodificación inversa de OpenRouteService).
     * Devuelve null si no hay clave, si el servicio no responde o si no conoce el lugar: el cliente la escribe a mano.
     */
    public function direccionDe(float $lat, float $lng): ?string
    {
        $clave = config('feder.ors.api_key');
        if (empty($clave)) {
            return null;
        }

        $cache = sprintf('direccion:%.4f,%.4f', $lat, $lng);
        if ($guardada = Cache::get($cache)) {
            return $guardada;
        }

        try {
            $respuesta = Http::timeout(6)
                ->withHeaders(['Authorization' => $clave])
                ->get(rtrim(config('feder.ors.url'), '/').'/geocode/reverse', [
                    'point.lat' => $lat,
                    'point.lon' => $lng,
                    'size' => 1,
                    'boundary.country' => 'BO',
                ]);
            $lugar = $respuesta->successful() ? $respuesta->json('features.0.properties') : null;
        } catch (Throwable $e) {
            Log::warning('OpenRouteService no devolvió la dirección: '.$e->getMessage());

            return null;
        }

        if (! is_array($lugar)) {
            return null;
        }

        // Calle y número si existen; si no, el nombre del lugar. Luego barrio y ciudad, sin repetir.
        $calle = trim(($lugar['street'] ?? '').' '.($lugar['housenumber'] ?? '')) ?: ($lugar['name'] ?? '');
        $partes = collect([$calle, $lugar['neighbourhood'] ?? null, $lugar['locality'] ?? null])
            ->filter()->map(fn ($p) => trim($p))->unique()->values();
        $direccion = $partes->isEmpty() ? null : mb_substr($partes->implode(', '), 0, 255);

        if ($direccion !== null) {
            Cache::put($cache, $direccion, now()->addWeek());
        }

        return $direccion;
    }

    /** Distancia y tiempo reales por calles. Se guarda en caché un día por ubicación para cuidar la cuota. */
    private function rutaPorCalles(float $latO, float $lngO, float $latD, float $lngD): ?array
    {
        $clave = config('feder.ors.api_key');
        if (empty($clave)) {
            return null;
        }

        $cache = sprintf('ruta:%.4f,%.4f:%.4f,%.4f', $latO, $lngO, $latD, $lngD);
        if ($guardada = Cache::get($cache)) {
            return $guardada;
        }

        // Una falla no se guarda en caché: el siguiente intento vuelve a consultar el servicio.
        $ruta = (function () use ($clave, $latO, $lngO, $latD, $lngD) {
            try {
                $respuesta = Http::timeout(8)
                    ->withHeaders(['Authorization' => $clave])
                    ->get(rtrim(config('feder.ors.url'), '/').'/v2/directions/driving-car', [
                        'start' => "{$lngO},{$latO}",
                        'end' => "{$lngD},{$latD}",
                    ]);

                $resumen = $respuesta->json('features.0.properties.summary');
                if (! $respuesta->successful() || ! isset($resumen['distance'])) {
                    Log::warning('OpenRouteService no devolvió una ruta', ['estado' => $respuesta->status()]);

                    return null;
                }

                return ['km' => $resumen['distance'] / 1000, 'minutos' => $resumen['duration'] / 60, 'estimado' => false];
            } catch (Throwable $e) {
                Log::warning('OpenRouteService no respondió: '.$e->getMessage());

                return null;
            }
        })();

        if ($ruta !== null) {
            Cache::put($cache, $ruta, now()->addDay());
        }

        return $ruta;
    }

    /** Respaldo: línea recta (Haversine) multiplicada por un factor que aproxima las calles. */
    private function rutaEstimada(float $latO, float $lngO, float $latD, float $lngD, float $factor): array
    {
        $radio = 6371;
        $dLat = deg2rad($latD - $latO);
        $dLng = deg2rad($lngD - $lngO);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($latO)) * cos(deg2rad($latD)) * sin($dLng / 2) ** 2;
        $km = 2 * $radio * asin(min(1, sqrt($a))) * $factor;

        return ['km' => $km, 'minutos' => $km / self::VELOCIDAD_RESPALDO_KMH * 60, 'estimado' => true];
    }
}
