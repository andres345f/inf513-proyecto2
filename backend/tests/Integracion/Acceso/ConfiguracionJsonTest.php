<?php

namespace Tests\Integracion\Acceso;

use App\Repositories\ConfiguracionWebRepository;
use App\Support\EstructuraFilaWeb;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * H-25: las filas JSON de confirmacion_operacion se validan por comando al escribir, y al leer una fila mal formada
 * se descarta (con aviso en el registro) en lugar de romper la función para todos.
 */
class ConfiguracionJsonTest extends AccesoTestCase
{
    public function test_un_permiso_rol_con_texto_en_lugar_de_lista_no_rompe_los_permisos(): void
    {
        $this->reemplazarParametros('PERMISO_ROL', "parametros->>'rol' = 'CLIENTE'", '{"rol":"CLIENTE","permisos":"*"}');
        $repositorio = app(ConfiguracionWebRepository::class);

        // El rol dañado se queda sin permisos (se niega, no se concede todo) y los demás roles no se enteran.
        $this->assertSame([], $repositorio->permisosDeRol('CLIENTE'));
        $this->assertContains('usuarios.gestionar', $repositorio->permisosDeRol('ADMINISTRADOR'));
        $this->assertArrayNotHasKey('CLIENTE', $repositorio->matrizPermisos());
        $this->assertArrayHasKey('PERSONAL_FEDER', $repositorio->matrizPermisos());

        $this->api('GET', '/api/mis-pedidos', [], $this->tokenDe(self::CLIENTE))->assertStatus(403);
        $this->api('GET', '/api/menu', [], $this->tokenDe(self::CLIENTE))->assertOk()->assertJsonPath('grupos', []);
        $this->api('GET', '/api/admin/permisos', [], $this->tokenDe(self::ADMIN))->assertOk();
        $this->api('GET', '/api/gestion/pedidos', [], $this->tokenDe(self::PERSONAL))->assertOk();
    }

    public function test_filas_de_permisos_que_no_son_un_objeto_se_ignoran(): void
    {
        foreach (['"texto suelto"', '["VISITANTE","inicio.ver"]', '42', '{"rol":"CLIENTE"}', '{"rol":"CLIENTE","permisos":[1,{"x":2}]}', '{"permisos":["inicio.ver"]}'] as $json) {
            $this->insertarFila('PERMISO_ROL', $json);
        }
        $repositorio = app(ConfiguracionWebRepository::class);

        $this->assertSame(['VISITANTE', 'CLIENTE', 'PRODUCTOR', 'PERSONAL_FEDER', 'ADMINISTRADOR'], array_keys($repositorio->matrizPermisos()));
        $this->assertContains('carrito.usar', $repositorio->permisosDeRol('CLIENTE'));
        $this->api('GET', '/api/menu')->assertOk()->assertJsonPath('rol', 'VISITANTE');
    }

    public function test_una_fila_duplicada_de_permisos_nunca_gana_a_la_original(): void
    {
        $this->insertarFila('PERMISO_ROL', '{"rol":"CLIENTE","permisos":["usuarios.gestionar","permisos.gestionar"]}');
        $repositorio = app(ConfiguracionWebRepository::class);
        $originales = $this->permisosGuardados('CLIENTE');

        $this->assertSame($originales, $repositorio->permisosDeRol('CLIENTE'));
        $this->assertSame($originales, $repositorio->matrizPermisos()['CLIENTE']);

        // Tras una edición normal sigue mandando la misma fila (la más antigua), no la duplicada.
        $repositorio->guardarPermisosDeRol('CLIENTE', array_values(array_diff($originales, ['planes_pago.propios'])));
        $this->assertNotContains('usuarios.gestionar', $repositorio->permisosDeRol('CLIENTE'));
        $this->assertNotContains('planes_pago.propios', $repositorio->permisosDeRol('CLIENTE'));
        $this->api('GET', '/api/admin/usuarios', [], $this->tokenDe(self::CLIENTE))->assertStatus(403);
    }

    public function test_un_peso_con_producto_no_numerico_no_rompe_productos_ni_envios(): void
    {
        $this->insertarFila('PESO_PRODUCTO', '{"producto_id":"abc","peso_kg":1.5}');
        $this->insertarFila('PESO_PRODUCTO', '{"producto_id":2,"peso_kg":"pesado"}');
        $this->insertarFila('PESO_PRODUCTO', '"suelto"');
        $repositorio = app(ConfiguracionWebRepository::class);

        $pesos = $repositorio->pesosDeProductos([1, 2, 3]);
        $this->assertEquals([1 => 0.05, 2 => 0.20, 3 => 0.09], $pesos);

        $this->api('GET', '/api/gestion/productos', [], $this->tokenDe(self::PERSONAL))->assertOk();
        // Guardar el peso de un producto sigue funcionando con la fila dañada presente.
        $repositorio->guardarPesoProducto(3, 0.11, config('feder.correo_sistema'));
        $this->assertEquals(0.11, $repositorio->pesosDeProductos([3])[3]);
    }

    public function test_una_entrada_de_menu_mal_formada_no_rompe_el_menu(): void
    {
        $this->insertarFila('MENU_WEB', '{"etiqueta":"Rota","ruta":"sin-barra","permiso":"inicio.ver","icono":"x","grupo":"General","orden":1}');
        $this->insertarFila('MENU_WEB', '{"etiqueta":"Sin permiso","ruta":"/rota","icono":"x","grupo":"General","orden":2}');
        $this->insertarFila('MENU_WEB', '"suelto"');

        $entradas = app(ConfiguracionWebRepository::class)->entradasMenu();
        $this->assertCount(39, $entradas);

        $menu = $this->api('GET', '/api/menu')->assertOk();
        $this->assertSame(['/', '/catalogo'], array_column($menu->json('grupos.0.entradas'), 'ruta'));
        $this->api('GET', '/api/admin/permisos', [], $this->tokenDe(self::ADMIN))->assertOk();
    }

    public function test_una_tarifa_de_envio_incompleta_responde_que_no_esta_configurada(): void
    {
        $this->reemplazarParametros('TARIFA_ENVIO', 'true', '{"origen":{"lat":-17.78,"lng":-63.18},"cobertura_km":15}');

        $this->assertSame([], app(ConfiguracionWebRepository::class)->tarifaEnvio());

        $cliente = $this->crearCuenta(['CLIENTE']);
        DB::table('item_carrito')->insert(['cliente_id' => $cliente->id, 'producto_id' => 1, 'cantidad' => 1]);
        $respuesta = $this->api('POST', '/api/carrito/cotizar-envio', ['lat' => -17.79, 'lng' => -63.17], $this->tokenDe($cliente->correo));
        $respuesta->assertStatus(503);
        $this->assertStringContainsString('tarifa de envío', $respuesta->json('message'));
    }

    public function test_no_se_escriben_filas_con_estructura_invalida(): void
    {
        $repositorio = app(ConfiguracionWebRepository::class);
        $antes = $this->permisosGuardados('CLIENTE');

        foreach ([['inicio.ver', 42], ['inicio.ver', ['anidado']], ['con espacios'], ['permisos' => ['inicio.ver']]] as $permisos) {
            try {
                $repositorio->guardarPermisosDeRol('CLIENTE', $permisos);
                $this->fail('Se guardó una lista de permisos inválida: '.json_encode($permisos));
            } catch (InvalidArgumentException) {
                $this->assertSame($antes, $this->permisosGuardados('CLIENTE'));
            }
        }

        foreach ([0.0, -1.0, NAN, INF] as $peso) {
            try {
                $repositorio->guardarPesoProducto(1, $peso, config('feder.correo_sistema'));
                $this->fail("Se guardó un peso inválido: {$peso}");
            } catch (InvalidArgumentException) {
                $this->assertEquals(0.05, $repositorio->pesosDeProductos([1])[1]);
            }
        }
    }

    public function test_las_filas_que_escribe_la_configuracion_son_siempre_objetos_json(): void
    {
        // Convivencia con el Proyecto 1 (H-24): su procesador de correo espera un arreglo en «parametros»; mientras la web
        // guarde objetos, una búsqueda accidental del P1 falla al leer y no marca la fila como usada.
        $repositorio = app(ConfiguracionWebRepository::class);
        $repositorio->guardarPermisosDeRol('CLIENTE', []);
        $repositorio->guardarPesoProducto(1, 0.07, config('feder.correo_sistema'));

        $tipos = DB::table('confirmacion_operacion')->whereNull('usuario_id')
            ->whereIn('comando', ['PERMISO_ROL', 'MENU_WEB', 'TARIFA_ENVIO', 'PESO_PRODUCTO'])
            ->selectRaw('DISTINCT jsonb_typeof(parametros) AS tipo')->pluck('tipo')->all();
        $this->assertSame(['object'], $tipos);
        $this->assertSame('array', DB::table('confirmacion_operacion')->where('comando', 'PERMISO_ROL')
            ->whereRaw("parametros->>'rol' = 'CLIENTE'")->selectRaw("jsonb_typeof(parametros->'permisos') AS tipo")->value('tipo'));
    }

    public function test_el_validador_reconoce_las_filas_reales_de_los_datos_iniciales(): void
    {
        $filas = DB::table('confirmacion_operacion')->whereIn('comando', ['PERMISO_ROL', 'MENU_WEB', 'TARIFA_ENVIO', 'PESO_PRODUCTO'])->get(['id', 'comando', 'parametros']);
        $this->assertCount(76, $filas);

        foreach ($filas as $fila) {
            $errores = EstructuraFilaWeb::errores($fila->comando, json_decode($fila->parametros, true));
            $this->assertSame([], $errores, "Fila {$fila->comando} #{$fila->id}");
        }
    }

    public function test_solicitudes_y_cambios_mal_formados_no_rompen_los_listados_del_personal(): void
    {
        $productorUsuario = $this->usuarioId(self::PRODUCTORA);
        $this->insertarFila('SOLICITUD_PRODUCTO', '"suelto"', $productorUsuario);
        $this->insertarFila('SOLICITUD_PRODUCTO', '{"estado":"PENDIENTE","nombre":"Miel de pruebas","descripcion":"Miel multifloral de monte","presentacion":"Frasco 500 g","unidad_medida":"UNIDAD","categoria_sugerida":"Mieles"}', $productorUsuario);
        $this->insertarFila('CAMBIO_COMUNIDAD', '{"estado":"PENDIENTE"}', $productorUsuario);
        $this->insertarFila('CAMBIO_COMUNIDAD', '[1,2,3]', $productorUsuario);
        $personal = $this->tokenDe(self::PERSONAL);

        $propuestas = $this->api('GET', '/api/gestion/productos-propuestos', [], $personal)->assertOk()->json('propuestas');
        $this->assertSame(['Miel de pruebas'], array_column($propuestas, 'nombre'));
        $this->api('GET', '/api/gestion/cambios-comunidad-productor', [], $personal)->assertOk()->assertJsonPath('solicitudes', []);
        $this->api('GET', '/api/productor/productos-propuestos', [], $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR'))->assertOk();
    }

    private function insertarFila(string $comando, string $json, ?int $usuarioId = null): int
    {
        return (int) DB::table('confirmacion_operacion')->insertGetId([
            'codigo_hash' => hash('sha256', 'PRUEBA:'.bin2hex(random_bytes(8))),
            'correo' => $usuarioId ? 'prueba@feder.example' : 'sistema@feder.example',
            'usuario_id' => $usuarioId,
            'comando' => $comando,
            'parametros' => $json,
            'expira_en' => '9999-12-31 23:59:59-04',
        ]);
    }

    private function reemplazarParametros(string $comando, string $condicion, string $json): void
    {
        $filas = DB::update("UPDATE confirmacion_operacion SET parametros = ?::jsonb WHERE comando = ? AND usuario_id IS NULL AND {$condicion}", [$json, $comando]);
        $this->assertSame(1, $filas);
    }
}
