<?php

namespace Tests\Integracion\Acceso;

use Illuminate\Routing\Route as Ruta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * Cobertura esencial de la auditoría RBAC, repetida como prueba automática:
 * menú por rol, todas las rutas de lectura con cada tipo de sesión y todas las rutas de escritura
 * llamadas directamente (sin pasar por la interfaz) por quien no tiene el permiso.
 *
 * El resultado esperado se calcula con la matriz PERMISO_ROL guardada y con los middleware de cada ruta,
 * de modo que una ruta nueva sin protección o un permiso mal puesto hacen fallar la prueba.
 */
class CoberturaRutasPorRolTest extends AccesoTestCase
{
    /** Rutas que no exigen permiso de la matriz (solo sesión, o públicas a propósito). */
    private const SIN_PERMISO = [
        'GET api/ping', 'POST api/auth/login', 'POST api/auth/registro', 'GET api/menu', 'POST api/visitas',
        'GET api/auth/yo', 'POST api/auth/rol-activo', 'POST api/auth/logout', 'PUT api/auth/contrasena',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 503)]);
    }

    public function test_menu_de_cada_rol(): void
    {
        $esperado = ['VISITANTE' => 2, 'CLIENTE' => 12, 'PRODUCTOR' => 11, 'PERSONAL_FEDER' => 17, 'ADMINISTRADOR' => 24];

        foreach ($this->sesiones() as $nombre => [$token, $rol]) {
            $menu = $this->api('GET', '/api/menu', [], $token)->assertOk()->assertJsonPath('rol', $rol);
            $entradas = collect($menu->json('grupos'))->flatMap(fn ($g) => $g['entradas']);

            $this->assertCount($esperado[$rol], $entradas, "Menú de {$nombre}");
            // Cada entrada visible corresponde a un permiso que el rol activo realmente tiene.
            $this->assertSame([], array_values(array_diff($entradas->pluck('permiso')->unique()->all(), $this->permisosGuardados($rol))), "Menú de {$nombre}");
        }
    }

    public function test_solo_ocho_rutas_y_el_cambio_de_contrasena_quedan_fuera_de_la_matriz(): void
    {
        $sinPermiso = $this->rutasApi()->filter(fn (Ruta $r) => $this->permisosDe($r) === [])
            ->map(fn (Ruta $r) => $r->methods()[0].' '.$r->uri())->values()->all();

        $this->assertEqualsCanonicalizing(self::SIN_PERMISO, $sinPermiso);
        // Las que no son públicas exigen sesión.
        foreach (['GET api/auth/yo', 'POST api/auth/rol-activo', 'POST api/auth/logout', 'PUT api/auth/contrasena'] as $firma) {
            $ruta = $this->rutasApi()->first(fn (Ruta $r) => $r->methods()[0].' '.$r->uri() === $firma);
            $this->assertTrue($this->exigeSesion($ruta), $firma);
        }
    }

    public function test_toda_ruta_con_permiso_de_la_matriz_que_no_es_del_catalogo_publico_exige_sesion(): void
    {
        foreach ($this->rutasApi() as $ruta) {
            $permisos = $this->permisosDe($ruta);
            if ($permisos === [] || $permisos === [['catalogo.ver']]) {
                continue;
            }
            $this->assertTrue($this->exigeSesion($ruta), $ruta->methods()[0].' '.$ruta->uri().' tiene permiso pero no exige sesión.');
        }
    }

    public function test_lecturas_de_todas_las_rutas_con_cada_tipo_de_sesion(): void
    {
        $rutas = $this->rutasApi()->filter(fn (Ruta $r) => in_array('GET', $r->methods(), true));
        $this->assertGreaterThanOrEqual(50, $rutas->count());
        $pruebas = 0;

        foreach ($this->sesiones() as $nombre => [$token, $rol]) {
            $concedidos = $this->permisosGuardados($rol);
            foreach ($rutas as $ruta) {
                $url = $this->urlDe($ruta);
                $estado = $this->api('GET', $url, [], $token)->getStatusCode();
                $pruebas++;
                $contexto = "{$nombre} · GET {$url} → {$estado}";

                if ($token === null && $this->exigeSesion($ruta)) {
                    $this->assertSame(401, $estado, $contexto);
                } elseif (! $this->autorizado($ruta, $concedidos)) {
                    $this->assertSame($token === null ? 401 : 403, $estado, $contexto);
                } else {
                    // Con permiso: responde, no encuentra el registro (ajeno o inexistente) o pide un dato; nunca 401/403/500.
                    $this->assertContains($estado, [200, 404, 422], $contexto);
                }
            }
        }

        $this->assertGreaterThanOrEqual(400, $pruebas);
    }

    public function test_escrituras_directas_a_la_api_sin_el_permiso_se_rechazan_antes_de_validar(): void
    {
        $rutas = $this->rutasApi()->reject(fn (Ruta $r) => in_array('GET', $r->methods(), true) || $this->permisosDe($r) === []);
        $this->assertGreaterThanOrEqual(45, $rutas->count());
        $huella = $this->huellaDeDatos();
        $pruebas = 0;

        foreach ($this->sesiones() as $nombre => [$token, $rol]) {
            $concedidos = $this->permisosGuardados($rol);
            foreach ($rutas as $ruta) {
                if ($token !== null && $this->autorizado($ruta, $concedidos)) {
                    continue;
                }
                $metodo = $ruta->methods()[0];
                $url = $this->urlDe($ruta);
                $respuesta = $this->api($metodo, $url, [], $token);
                $pruebas++;

                // Sin permiso no se llega a la validación: no hay pistas sobre los campos ni cambios en los datos.
                $this->assertSame($token === null ? 401 : 403, $respuesta->getStatusCode(), "{$nombre} · {$metodo} {$url}");
                $this->assertNull($respuesta->json('errors'), "{$nombre} · {$metodo} {$url} reveló campos");
            }
        }

        $this->assertGreaterThanOrEqual(150, $pruebas);
        $this->assertSame($huella, $this->huellaDeDatos(), 'Una escritura sin permiso cambió datos.');
    }

    public function test_las_rutas_de_sesion_no_funcionan_sin_token(): void
    {
        $this->api('GET', '/api/auth/yo')->assertStatus(401);
        $this->api('POST', '/api/auth/rol-activo', ['rol_id' => 1])->assertStatus(401);
        $this->api('POST', '/api/auth/logout')->assertStatus(401);
        $this->api('PUT', '/api/auth/perfil', [])->assertStatus(401);
    }

    /** @return array<string, array{0: ?string, 1: string}> nombre => [token, rol activo] */
    private function sesiones(): array
    {
        return [
            'visitante' => [null, 'VISITANTE'],
            'cliente' => [$this->tokenDe(self::CLIENTE, 'CLIENTE'), 'CLIENTE'],
            'productor' => [$this->tokenDe(self::PRODUCTORA_C, 'PRODUCTOR'), 'PRODUCTOR'],
            'personal' => [$this->tokenDe(self::PERSONAL, 'PERSONAL_FEDER'), 'PERSONAL_FEDER'],
            'administrador' => [$this->tokenDe(self::ADMIN, 'ADMINISTRADOR'), 'ADMINISTRADOR'],
            'multirrol como personal' => [$this->tokenDe(self::MULTIRROL, 'PERSONAL_FEDER'), 'PERSONAL_FEDER'],
            'multirrol como cliente' => [$this->tokenDe(self::MULTIRROL, 'CLIENTE'), 'CLIENTE'],
            'productora como cliente' => [$this->tokenDe(self::PRODUCTORA, 'CLIENTE'), 'CLIENTE'],
        ];
    }

    private function rutasApi()
    {
        return collect(Route::getRoutes()->getRoutes())->filter(fn (Ruta $r) => str_starts_with($r->uri(), 'api/'))->values();
    }

    /** Grupos de permisos de la ruta: cada middleware «permiso:a,b» es un grupo (basta uno); todos los grupos se exigen. */
    private function permisosDe(Ruta $ruta): array
    {
        return collect($ruta->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permiso:'))
            ->map(fn ($m) => explode(',', substr($m, 8)))->values()->all();
    }

    private function exigeSesion(Ruta $ruta): bool
    {
        return in_array('auth:api', $ruta->gatherMiddleware(), true);
    }

    private function autorizado(Ruta $ruta, array $concedidos): bool
    {
        foreach ($this->permisosDe($ruta) as $grupo) {
            if (array_intersect($grupo, $concedidos) === []) {
                return false;
            }
        }

        return true;
    }

    private function urlDe(Ruta $ruta): string
    {
        $url = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => match ($m[1]) {
            'tipo' => 'ventas',
            'rol' => 'CLIENTE',
            default => '1',
        }, $ruta->uri());

        return $url;
    }

    private function huellaDeDatos(): array
    {
        $tablas = ['usuario', 'usuario_rol', 'productor', 'produccion', 'lote_produccion', 'compra_feder', 'detalle_compra', 'lote_feder',
            'producto', 'categoria', 'comunidad_agricola', 'pedido', 'pago', 'factura', 'devolucion', 'item_carrito', 'movimiento_inventario'];
        $huella = [];
        foreach ($tablas as $tabla) {
            $huella[$tabla] = DB::selectOne("SELECT count(*) || ':' || COALESCE(md5(string_agg(t::text, '|' ORDER BY t.id)), '') AS h FROM {$tabla} t")->h;
        }
        $huella['configuracion'] = DB::selectOne("SELECT md5(string_agg(parametros::text, '|' ORDER BY id)) AS h FROM confirmacion_operacion WHERE comando <> 'SESION_WEB'")->h;

        return $huella;
    }
}
