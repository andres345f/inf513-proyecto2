<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Estructura que debe tener el JSON «parametros» de cada tipo de fila web guardada en confirmacion_operacion.
 *
 * La tabla no valida el contenido (es JSONB libre) y el cero cambios de esquema impide añadir restricciones, así que
 * la forma se comprueba aquí: al escribir (exigir) y al leer (errores/avisar), para que una fila mal formada se descarte
 * en lugar de romper la función para todos.
 *
 * Toda fila web es un OBJETO JSON, nunca un arreglo: el procesador de correo del Proyecto 1 lee «parametros» como
 * arreglo y, al no poder hacerlo con un objeto, revierte sin marcar la fila como usada.
 */
final class EstructuraFilaWeb
{
    private const PERMISO = '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/';

    private const ROLES_CON_SESION = ['ADMINISTRADOR', 'PERSONAL_FEDER', 'PRODUCTOR', 'CLIENTE'];

    private const ESTADOS_SOLICITUD = ['PENDIENTE', 'APROBADA', 'RECHAZADA'];

    private function __construct()
    {
    }

    /** @return list<string> motivos por los que la fila no es válida (vacío si lo es) */
    public static function errores(string $comando, mixed $p): array
    {
        if (! is_array($p) || $p === [] || array_is_list($p)) {
            return ['«parametros» debe ser un objeto JSON con sus campos'];
        }

        return match ($comando) {
            'PERMISO_ROL' => self::permisoRol($p),
            'MENU_WEB' => self::menuWeb($p),
            'TARIFA_ENVIO' => self::tarifaEnvio($p),
            'PESO_PRODUCTO' => self::pesoProducto($p),
            'SESION_WEB' => self::sesionWeb($p),
            'SOLICITUD_PRODUCTO' => self::solicitudProducto($p),
            'CAMBIO_COMUNIDAD' => self::cambioComunidad($p),
            'LOGISTICA_COMPRA' => self::logisticaCompra($p),
            default => [],
        };
    }

    public static function esValida(string $comando, mixed $parametros): bool
    {
        return self::errores($comando, $parametros) === [];
    }

    /** Para escrituras: no deja guardar una fila con estructura inválida. */
    public static function exigir(string $comando, mixed $parametros): void
    {
        $errores = self::errores($comando, $parametros);
        if ($errores !== []) {
            throw new InvalidArgumentException("Fila {$comando} con estructura inválida: ".implode('; ', $errores).'.');
        }
    }

    /**
     * Para lecturas: deja constancia en el registro de la fila descartada, como máximo una vez por hora y por fila
     * (estas filas se leen en casi todas las peticiones). No incluye el contenido de la fila.
     */
    public static function avisar(string $comando, int|string|null $filaId, array $errores): void
    {
        try {
            if (Cache::add("feder:fila-web-invalida:{$comando}:{$filaId}", true, 3600)) {
                Log::warning("Se ignoró la fila {$comando} #{$filaId} de confirmacion_operacion: ".implode('; ', $errores).'.');
            }
        } catch (Throwable) {
            // El aviso es informativo: si la caché o el registro fallan, la lectura defensiva sigue adelante.
        }
    }

    private static function permisoRol(array $p): array
    {
        $errores = [];
        if (! self::texto($p['rol'] ?? null, 20) || ! preg_match('/^[A-Z_]+$/', $p['rol'])) {
            $errores[] = '«rol» debe ser el código de un rol';
        }
        $permisos = $p['permisos'] ?? null;
        if (! is_array($permisos) || ! array_is_list($permisos)) {
            $errores[] = '«permisos» debe ser una lista';
        } else {
            foreach ($permisos as $permiso) {
                if (! is_string($permiso) || strlen($permiso) > 60 || ! preg_match(self::PERMISO, $permiso)) {
                    $errores[] = '«permisos» solo admite claves de permiso como «catalogo.ver»';
                    break;
                }
            }
        }

        return $errores;
    }

    private static function menuWeb(array $p): array
    {
        $errores = [];
        foreach (['etiqueta' => 80, 'grupo' => 80, 'icono' => 40] as $campo => $maximo) {
            if (! self::texto($p[$campo] ?? null, $maximo)) {
                $errores[] = "«{$campo}» debe ser un texto";
            }
        }
        if (! self::texto($p['ruta'] ?? null, 200) || ! preg_match('/^\/[^\s?#]*$/', $p['ruta'])) {
            $errores[] = '«ruta» debe empezar con «/»';
        }
        if (! self::texto($p['permiso'] ?? null, 60) || ! preg_match(self::PERMISO, $p['permiso'])) {
            $errores[] = '«permiso» debe ser una clave de permiso';
        }
        if (! self::numero($p['orden'] ?? null)) {
            $errores[] = '«orden» debe ser un número';
        }

        return $errores;
    }

    private static function tarifaEnvio(array $p): array
    {
        $errores = [];
        if (! is_array($p['origen'] ?? null) || ! self::numero($p['origen']['lat'] ?? null) || ! self::numero($p['origen']['lng'] ?? null)) {
            $errores[] = '«origen» debe traer «lat» y «lng» numéricos';
        }
        if (! self::numero($p['cobertura_km'] ?? null, positivo: true)) {
            $errores[] = '«cobertura_km» debe ser un número mayor que cero';
        }
        foreach (['MOTO', 'AUTO'] as $vehiculo) {
            $v = $p['vehiculos'][$vehiculo] ?? null;
            foreach (['minimo', 'km_incluidos', 'precio_km', 'min_incluidos', 'precio_min'] as $campo) {
                if (! is_array($v) || ! self::numero($v[$campo] ?? null) || (float) $v[$campo] < 0) {
                    $errores[] = "«vehiculos.{$vehiculo}.{$campo}» debe ser un número no negativo";
                }
            }
        }
        foreach (['peso_maximo_moto_kg', 'factor_respaldo_linea_recta'] as $opcional) {
            if (array_key_exists($opcional, $p) && ! self::numero($p[$opcional], positivo: true)) {
                $errores[] = "«{$opcional}» debe ser un número mayor que cero";
            }
        }
        if (array_key_exists('peso_por_unidad_kg', $p)) {
            $pesos = $p['peso_por_unidad_kg'];
            if (! is_array($pesos) || array_filter($pesos, fn ($peso) => ! self::numero($peso, positivo: true)) !== []) {
                $errores[] = '«peso_por_unidad_kg» debe traer un peso mayor que cero por unidad de medida';
            }
        }

        return $errores;
    }

    private static function pesoProducto(array $p): array
    {
        $errores = [];
        if (! self::identificador($p['producto_id'] ?? null)) {
            $errores[] = '«producto_id» debe ser un identificador numérico';
        }
        if (! self::numero($p['peso_kg'] ?? null, positivo: true)) {
            $errores[] = '«peso_kg» debe ser un número mayor que cero';
        }

        return $errores;
    }

    private static function sesionWeb(array $p): array
    {
        $errores = [];
        if (! self::identificador($p['rol_id'] ?? null)) {
            $errores[] = '«rol_id» debe ser un identificador numérico';
        }
        if (! in_array($p['rol_codigo'] ?? null, self::ROLES_CON_SESION, true)) {
            $errores[] = '«rol_codigo» debe ser un rol del sistema';
        }

        return $errores;
    }

    private static function solicitudProducto(array $p): array
    {
        $errores = [];
        if (! in_array($p['estado'] ?? null, self::ESTADOS_SOLICITUD, true)) {
            $errores[] = '«estado» debe ser PENDIENTE, APROBADA o RECHAZADA';
        }
        foreach (['nombre' => 120, 'descripcion' => 1000, 'presentacion' => 60, 'unidad_medida' => 20, 'categoria_sugerida' => 100] as $campo => $maximo) {
            if (! self::texto($p[$campo] ?? null, $maximo)) {
                $errores[] = "«{$campo}» debe ser un texto";
            }
        }

        return $errores;
    }

    private static function cambioComunidad(array $p): array
    {
        $errores = [];
        if (! in_array($p['estado'] ?? null, self::ESTADOS_SOLICITUD, true)) {
            $errores[] = '«estado» debe ser PENDIENTE, APROBADA o RECHAZADA';
        }
        foreach (['productor_id', 'comunidad_anterior_id', 'comunidad_nueva_id'] as $campo) {
            if (! self::identificador($p[$campo] ?? null)) {
                $errores[] = "«{$campo}» debe ser un identificador numérico";
            }
        }

        return $errores;
    }

    private static function logisticaCompra(array $p): array
    {
        $errores = [];
        if (! self::identificador($p['compra_id'] ?? null)) {
            $errores[] = '«compra_id» debe ser un identificador numérico';
        }
        if (! in_array($p['modalidad'] ?? null, ['ENTREGA_PRODUCTOR', 'RECOJO_FEDER'], true)) {
            $errores[] = '«modalidad» debe ser ENTREGA_PRODUCTOR o RECOJO_FEDER';
        }
        if (! self::texto($p['estado'] ?? null, 30)) {
            $errores[] = '«estado» debe ser un texto';
        }

        return $errores;
    }

    private static function texto(mixed $valor, int $maximo): bool
    {
        return is_string($valor) && trim($valor) !== '' && mb_strlen($valor) <= $maximo;
    }

    /** Número JSON real (no texto), finito. */
    private static function numero(mixed $valor, bool $positivo = false): bool
    {
        return (is_int($valor) || is_float($valor)) && is_finite((float) $valor) && (! $positivo || $valor > 0);
    }

    /** Identificador: entero positivo, escrito como número o como texto de solo dígitos (hasta 18). */
    private static function identificador(mixed $valor): bool
    {
        return (is_int($valor) && $valor > 0) || (is_string($valor) && preg_match('/^[1-9][0-9]{0,17}$/', $valor) === 1);
    }
}
