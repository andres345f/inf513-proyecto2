<?php

namespace Tests\Unit;

use App\Support\EstructuraFilaWeb;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** H-25: estructura de las filas JSON de confirmacion_operacion por tipo de fila. */
class EstructuraFilaWebTest extends TestCase
{
    #[DataProvider('filasValidas')]
    public function test_acepta_filas_bien_formadas(string $comando, array $parametros): void
    {
        $this->assertSame([], EstructuraFilaWeb::errores($comando, $parametros));
        EstructuraFilaWeb::exigir($comando, $parametros);
    }

    #[DataProvider('filasInvalidas')]
    public function test_rechaza_filas_mal_formadas(string $comando, mixed $parametros): void
    {
        $this->assertFalse(EstructuraFilaWeb::esValida($comando, $parametros));
        $this->expectException(InvalidArgumentException::class);
        EstructuraFilaWeb::exigir($comando, $parametros);
    }

    public function test_ninguna_fila_web_puede_ser_un_arreglo_json(): void
    {
        foreach (['PERMISO_ROL', 'MENU_WEB', 'TARIFA_ENVIO', 'PESO_PRODUCTO', 'SESION_WEB', 'SOLICITUD_PRODUCTO', 'CAMBIO_COMUNIDAD', 'LOGISTICA_COMPRA', 'OTRO'] as $comando) {
            $this->assertFalse(EstructuraFilaWeb::esValida($comando, ['a', 'b']), $comando);
            $this->assertFalse(EstructuraFilaWeb::esValida($comando, []), $comando);
            $this->assertFalse(EstructuraFilaWeb::esValida($comando, 'texto'), $comando);
            $this->assertFalse(EstructuraFilaWeb::esValida($comando, null), $comando);
        }
    }

    public static function filasValidas(): array
    {
        $vehiculo = ['minimo' => 5, 'precio_km' => 1.0, 'precio_min' => 0.3, 'km_incluidos' => 1.5, 'min_incluidos' => 5];

        return [
            'permisos' => ['PERMISO_ROL', ['rol' => 'CLIENTE', 'permisos' => ['inicio.ver', 'metodos_pago.gestionar']]],
            'permisos vacíos' => ['PERMISO_ROL', ['rol' => 'VISITANTE', 'permisos' => []]],
            'menú' => ['MENU_WEB', ['etiqueta' => 'Catálogo', 'ruta' => '/catalogo', 'permiso' => 'catalogo.ver', 'icono' => 'catalogo', 'grupo' => 'General', 'orden' => 20]],
            'tarifa' => ['TARIFA_ENVIO', ['origen' => ['lat' => -17.78, 'lng' => -63.18, 'nombre' => 'Almacén'], 'cobertura_km' => 15,
                'vehiculos' => ['MOTO' => $vehiculo, 'AUTO' => $vehiculo], 'peso_maximo_moto_kg' => 20, 'peso_por_unidad_kg' => ['UNIDAD' => 0.5]]],
            'peso' => ['PESO_PRODUCTO', ['producto_id' => 7, 'peso_kg' => 0.25]],
            'sesión' => ['SESION_WEB', ['rol_id' => 4, 'rol_codigo' => 'CLIENTE']],
            'propuesta' => ['SOLICITUD_PRODUCTO', ['estado' => 'PENDIENTE', 'nombre' => 'Miel', 'descripcion' => 'Miel de monte', 'presentacion' => 'Frasco',
                'unidad_medida' => 'UNIDAD', 'categoria_sugerida' => 'Mieles', 'enviada_en' => '2026-11-24T10:00:00-04:00']],
            'cambio de comunidad' => ['CAMBIO_COMUNIDAD', ['estado' => 'APROBADA', 'productor_id' => 3, 'comunidad_anterior_id' => 1, 'comunidad_nueva_id' => 2]],
            'logística' => ['LOGISTICA_COMPRA', ['compra_id' => 9, 'modalidad' => 'RECOJO_FEDER', 'fecha_acordada' => null, 'estado' => 'PENDIENTE']],
            'tipo sin reglas propias' => ['OTRO_TIPO', ['cualquier' => 'objeto']],
        ];
    }

    public static function filasInvalidas(): array
    {
        return [
            'permisos como texto' => ['PERMISO_ROL', ['rol' => 'CLIENTE', 'permisos' => '*']],
            'permisos con números' => ['PERMISO_ROL', ['rol' => 'CLIENTE', 'permisos' => ['inicio.ver', 5]]],
            'permisos como objeto' => ['PERMISO_ROL', ['rol' => 'CLIENTE', 'permisos' => ['a' => 'inicio.ver']]],
            'permiso con espacios' => ['PERMISO_ROL', ['rol' => 'CLIENTE', 'permisos' => ['inicio ver']]],
            'sin rol' => ['PERMISO_ROL', ['permisos' => ['inicio.ver']]],
            'menú sin barra' => ['MENU_WEB', ['etiqueta' => 'X', 'ruta' => 'catalogo', 'permiso' => 'catalogo.ver', 'icono' => 'x', 'grupo' => 'General', 'orden' => 1]],
            'menú sin permiso' => ['MENU_WEB', ['etiqueta' => 'X', 'ruta' => '/x', 'icono' => 'x', 'grupo' => 'General', 'orden' => 1]],
            'menú con orden de texto' => ['MENU_WEB', ['etiqueta' => 'X', 'ruta' => '/x', 'permiso' => 'x.ver', 'icono' => 'x', 'grupo' => 'General', 'orden' => 'primero']],
            'tarifa sin vehículos' => ['TARIFA_ENVIO', ['origen' => ['lat' => -17.78, 'lng' => -63.18], 'cobertura_km' => 15]],
            'tarifa con cobertura negativa' => ['TARIFA_ENVIO', ['origen' => ['lat' => 1, 'lng' => 1], 'cobertura_km' => -1, 'vehiculos' => []]],
            'peso con producto no numérico' => ['PESO_PRODUCTO', ['producto_id' => 'abc', 'peso_kg' => 1.5]],
            'peso de texto' => ['PESO_PRODUCTO', ['producto_id' => 2, 'peso_kg' => 'pesado']],
            'peso cero' => ['PESO_PRODUCTO', ['producto_id' => 2, 'peso_kg' => 0]],
            'sesión sin rol' => ['SESION_WEB', ['rol_codigo' => 'CLIENTE']],
            'sesión con rol inventado' => ['SESION_WEB', ['rol_id' => 9, 'rol_codigo' => 'SUPERUSUARIO']],
            'propuesta sin nombre' => ['SOLICITUD_PRODUCTO', ['estado' => 'PENDIENTE']],
            'cambio sin comunidades' => ['CAMBIO_COMUNIDAD', ['estado' => 'PENDIENTE', 'productor_id' => 3]],
            'logística sin compra' => ['LOGISTICA_COMPRA', ['modalidad' => 'RECOJO_FEDER', 'estado' => 'PENDIENTE']],
            'arreglo' => ['PESO_PRODUCTO', [1, 2]],
            'texto suelto' => ['PERMISO_ROL', 'suelto'],
        ];
    }
}
