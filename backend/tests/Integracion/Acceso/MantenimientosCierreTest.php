<?php

namespace Tests\Integracion\Acceso;

use Illuminate\Support\Facades\DB;

class MantenimientosCierreTest extends AccesoTestCase
{
    public function test_alta_concesion_y_perfil_con_cambio_de_correo_preservan_identidad(): void
    {
        $admin = $this->tokenDe(self::ADMIN);
        $clave = $this->contrasenaFicticia();
        $datos = ['nombres' => 'Rosa', 'apellidos' => 'Ortiz', 'telefono' => '70008811', 'correo' => 'personal.cierre@feder.example'];
        $cuenta = $this->api('POST', '/api/admin/usuarios', $datos + [
            'ci' => 98100123, 'password' => $clave, 'password_confirmation' => $clave, 'rol' => 'PERSONAL_FEDER',
        ], $admin)->assertCreated()->json('id');
        $this->api('POST', "/api/admin/usuarios/{$cuenta}/roles", ['rol' => 'CLIENTE'], $admin)->assertOk();
        $yo = $this->tokenDe($datos['correo'], 'CLIENTE');
        $datos['correo'] = 'personal.cierre.nuevo@feder.example';
        $datos['nombres'] = 'Rosario';
        $this->api('PUT', '/api/auth/perfil', $datos, $yo)->assertUnprocessable()->assertJsonValidationErrors(['password_actual']);
        $this->api('PUT', '/api/auth/perfil', $datos + ['ci' => 55000000, 'password_actual' => $clave], $yo)->assertOk();
        $this->api('GET', '/api/auth/yo', [], $yo)->assertOk()->assertJsonPath('usuario.correo', $datos['correo']);
        $this->assertSame(98100123, (int) DB::table('usuario')->where('id', $cuenta)->value('ci'));
        $this->assertSame('EDITAR_PERFIL', $this->ultimaBitacora('USUARIO', $cuenta)->detalle['operacion']);
        $this->api('PATCH', "/api/admin/usuarios/{$cuenta}/activo", ['activo' => false], $admin)->assertOk();
        $this->api('GET', '/api/carrito', [], $yo)->assertUnauthorized();
        $this->api('PATCH', "/api/admin/usuarios/{$cuenta}/activo", ['activo' => true], $admin)->assertOk();
        $this->api('GET', '/api/carrito', [], $this->tokenDe($datos['correo'], 'CLIENTE'))->assertOk();
    }

    public function test_comunidad_vigencia_y_aprobacion_de_cambio_de_ficha(): void
    {
        $personal = $this->tokenDe(self::PERSONAL);
        $productor = $this->tokenDe(self::PRODUCTORA);
        $ficha = $this->api('GET', '/api/productor/ficha', [], $productor)->assertOk()->json();
        $datos = ['nombre' => 'Comunidad Cierre', 'municipio' => 'Roboré', 'departamento' => 'Santa Cruz', 'activa' => true];
        $id = $this->api('POST', '/api/gestion/comunidades', $datos, $personal)->assertCreated()->json('id');
        $datos['nombre'] = 'Comunidad Norte';
        $datos['activa'] = false;
        $this->api('PUT', "/api/gestion/comunidades/{$id}", $datos, $personal)->assertOk();
        $this->assertFalse($this->ultimaBitacora('COMUNIDAD_AGRICOLA', $id)->detalle['activa']);
        $this->assertNotContains($id, array_column($this->api('GET', '/api/comunidades-disponibles', [], $productor)->assertOk()->json('comunidades'), 'id'));
        $datos['activa'] = true;
        $this->api('PUT', "/api/gestion/comunidades/{$id}", $datos, $personal)->assertOk();
        $this->api('PUT', '/api/productor/ficha', ['comunidad_id' => $id, 'ubicacion_actividad' => 'Parcela norte del río'], $productor)->assertOk();
        $this->api('GET', '/api/productor/ficha', [], $productor)->assertOk()->assertJsonPath('comunidad_id', $ficha['comunidad_id'])->assertJsonPath('ubicacion', 'Parcela norte del río');
        $solicitudes = $this->api('GET', '/api/gestion/cambios-comunidad-productor', [], $personal)->assertOk()->json('solicitudes');
        $solicitud = collect($solicitudes)->firstWhere('comunidad_nueva', $datos['nombre']);
        $this->assertSame('PENDIENTE', $solicitud['estado']);
        $this->api('POST', '/api/gestion/cambios-comunidad-productor/'.$solicitud['id'], ['aprobar' => true], $personal)->assertOk();
        $this->api('GET', '/api/productor/ficha', [], $productor)->assertOk()->assertJsonPath('comunidad_id', $id);
        $datos['activa'] = false;
        $this->api('PUT', "/api/gestion/comunidades/{$id}", $datos, $personal)->assertOk();
        $this->api('GET', '/api/productor/ficha', [], $productor)->assertOk()->assertJsonPath('comunidad_id', $id);
        $this->api('PUT', '/api/gestion/comunidades/999999', $datos, $personal)->assertNotFound();
    }

    public function test_aprobar_una_afiliacion_activa_solo_una_comunidad_propuesta(): void
    {
        $personal = $this->tokenDe(self::PERSONAL);
        $persona = $this->crearCuenta(['CLIENTE']);
        $token = $this->tokenDe($persona->correo);
        $propuesta = ['nombre' => 'Propuesta Cierre', 'municipio' => 'Roboré', 'departamento' => 'Santa Cruz'];
        $datos = ['comunidad_nueva' => $propuesta, 'ubicacion_actividad' => 'Parcela comunitaria de ensayo'];
        $id = $this->api('POST', '/api/productor/solicitud', $datos, $token)->assertCreated()->json('id');
        $this->api('GET', '/api/productor/solicitud', [], $token)->assertOk()->assertJsonPath('solicitud.comunidad_nueva', true);
        $this->api('POST', "/api/gestion/productores/{$id}/aprobar", [], $personal)->assertOk();
        $comunidad = DB::table('comunidad_agricola')->where('nombre', $propuesta['nombre'])->first();
        $this->assertTrue((bool) $comunidad->activa);
        $segunda = $this->crearCuenta(['CLIENTE']);
        $token2 = $this->tokenDe($segunda->correo);
        $pendiente = $this->api('POST', '/api/productor/solicitud', ['comunidad_id' => $comunidad->id,
            'ubicacion_actividad' => 'Parcela de la segunda familia'], $token2)->assertCreated()->json('id');
        $this->api('PUT', '/api/gestion/comunidades/'.$comunidad->id, $propuesta + ['activa' => false], $personal)->assertOk();
        $this->api('POST', "/api/gestion/productores/{$pendiente}/aprobar", [], $personal)->assertUnprocessable();
        $otra = $this->crearCuenta(['CLIENTE']);
        $this->api('POST', '/api/productor/solicitud', $datos, $this->tokenDe($otra->correo))->assertUnprocessable();
        $this->assertFalse((bool) DB::table('comunidad_agricola')->where('id', $comunidad->id)->value('activa'));
    }

    public function test_productor_gestiona_su_produccion_y_retira_una_oferta_sin_compromisos(): void
    {
        $yo = $this->tokenDe(self::PRODUCTORA);
        $datos = ['producto_id' => 1, 'fecha_inicio' => today()->subDay()->toDateString(), 'fecha_disponibilidad_estimada' => today()->addDay()->toDateString(),
            'cantidad_estimada' => 8, 'ubicacion_produccion' => 'Parcela de ensayo'];
        $id = $this->api('POST', '/api/productor/producciones', $datos, $yo)->assertCreated()->json('id');
        $url = "/api/productor/producciones/{$id}";
        $datos['cantidad_estimada'] = 9;
        $this->api('PUT', $url, $datos, $yo)->assertOk();
        $this->api('POST', $url.'/estado', ['estado' => 'EN_CURSO'], $yo)->assertOk();
        $oferta = ['fecha_elaboracion' => today()->toDateString(), 'fecha_vencimiento' => today()->addMonth()->toDateString(),
            'cantidad_producida' => 8, 'cantidad_ofrecida' => 6, 'precio_ofrecido' => 51];
        $lote = $this->api('POST', $url.'/lotes', $oferta, $yo)->assertCreated()->json('id');
        $oferta['precio_ofrecido'] = 50;
        $this->api('PUT', $url.'/lotes/'.$lote, $oferta, $yo)->assertOk();
        $this->api('POST', "/api/productor/lotes/{$lote}/retirar", [], $yo)->assertOk();
        $this->api('POST', $url.'/estado', ['estado' => 'TERMINADA'], $yo)->assertOk();
        $this->api('GET', $url, [], $yo)->assertOk()->assertJsonPath('estado', 'TERMINADA')->assertJsonPath('lotes.0.estado', 'RETIRADO');
        $this->api('PUT', $url, $datos, $yo)->assertUnprocessable();
    }

    public function test_propuesta_visible_al_titular_resuelta_por_catalogo_y_sin_doble_alta(): void
    {
        $yo = $this->tokenDe(self::PRODUCTORA);
        $personal = $this->tokenDe(self::PERSONAL);
        $datos = ['nombre' => 'Harina de ensayo', 'descripcion' => 'Producto elaborado en comunidad.', 'presentacion' => 'Bolsa de muestra',
            'unidad_medida' => 'UNIDAD', 'categoria_sugerida' => 'Harinas'];
        $id = $this->api('POST', '/api/productor/productos-propuestos', $datos, $yo)->assertCreated()->json('id');
        $this->api('GET', '/api/productor/productos-propuestos', [], $yo)->assertOk()->assertJsonFragment(['id' => $id, 'estado' => 'PENDIENTE']);
        $this->api('GET', '/api/productor/productos-propuestos', [], $this->tokenDe(self::PRODUCTOR_B))->assertOk()->assertJsonMissing(['nombre' => $datos['nombre']]);
        $alta = $datos + ['codigo' => 'CF-HARINA', 'categoria_id' => 1, 'precio_venta' => 12, 'stock_minimo' => 0, 'activo' => true];
        $ruta = "/api/gestion/productos-propuestos/{$id}/aprobar";
        $this->api('POST', $ruta, $alta, $personal)->assertOk();
        $this->api('POST', $ruta, $alta, $personal)->assertUnprocessable();
        $this->assertSame(1, DB::table('producto')->where('codigo', $alta['codigo'])->count());
        $this->api('GET', '/api/productor/productos-propuestos', [], $yo)->assertOk()->assertJsonFragment(['id' => $id, 'estado' => 'APROBADA']);
    }
}
