<?php

namespace Tests\Integracion\Acceso;

use App\Http\Controllers\Api\ProductorController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;

/**
 * H-40 (productores y comunidades, CU2): las acciones de ProductorController que reciben datos validan con un FormRequest
 * propio y conservan sus mensajes en español y por campo.
 */
class FormRequestsProductoresTest extends AccesoTestCase
{
    public function test_las_acciones_con_datos_declaran_un_form_request(): void
    {
        foreach (['index', 'rechazar', 'guardarComunidad', 'solicitar'] as $metodo) {
            $tipo = (new ReflectionMethod(ProductorController::class, $metodo))->getParameters()[0]->getType()?->getName();
            $this->assertTrue(is_subclass_of((string) $tipo, FormRequest::class), "ProductorController::{$metodo} valida dentro del controlador (recibe {$tipo}).");
        }

        $codigo = file_get_contents((new ReflectionClass(ProductorController::class))->getFileName());
        $this->assertStringNotContainsString('->validate(', $codigo, 'ProductorController todavía llama a $request->validate().');
    }

    public function test_el_listado_de_productores_valida_sus_filtros(): void
    {
        $personal = $this->tokenDe(self::PERSONAL);

        $this->api('GET', '/api/gestion/productores?estado=INVENTADO', [], $personal)
            ->assertStatus(422)->assertJsonPath('errors.estado.0', 'El estado elegido no es válido.');
        $this->api('GET', '/api/gestion/productores?comunidad=0', [], $personal)->assertStatus(422)->assertJsonValidationErrors(['comunidad']);
        $this->api('GET', '/api/gestion/productores?pagina=0', [], $personal)->assertStatus(422)->assertJsonValidationErrors(['pagina']);
        $this->api('GET', '/api/gestion/productores?estado=APROBADA&q=a&pagina=1', [], $personal)->assertOk();
    }

    public function test_rechazar_una_solicitud_de_productor_exige_un_motivo_en_su_campo(): void
    {
        $personal = $this->tokenDe(self::PERSONAL);
        $pendiente = $this->crearSolicitudPendiente();

        $this->api('POST', "/api/gestion/productores/{$pendiente}/rechazar", [], $personal)
            ->assertStatus(422)->assertJsonPath('errors.motivo.0', 'Escribe el motivo del rechazo.');
        $this->api('POST', "/api/gestion/productores/{$pendiente}/rechazar", ['motivo' => 'no'], $personal)
            ->assertStatus(422)->assertJsonPath('errors.motivo.0', 'El motivo debe tener al menos 5 caracteres.');
        $this->assertSame('PENDIENTE', DB::table('productor')->where('id', $pendiente)->value('estado_solicitud'));

        // Con un motivo válido el rechazo sigue funcionando como antes.
        $this->api('POST', "/api/gestion/productores/{$pendiente}/rechazar", ['motivo' => 'Documentación incompleta'], $personal)->assertOk();
        $this->assertSame('RECHAZADA', DB::table('productor')->where('id', $pendiente)->value('estado_solicitud'));
    }

    public function test_guardar_una_comunidad_valida_cada_campo_en_espanol(): void
    {
        $personal = $this->tokenDe(self::PERSONAL);

        $vacia = $this->api('POST', '/api/gestion/comunidades', [], $personal)->assertStatus(422);
        $vacia->assertJsonPath('errors.nombre.0', 'Escribe el nombre de la comunidad.');
        $vacia->assertJsonPath('errors.municipio.0', 'Escribe el municipio.');

        $corta = $this->api('POST', '/api/gestion/comunidades', ['nombre' => 'ab', 'municipio' => 'cd', 'activa' => 'quizas'], $personal)->assertStatus(422);
        $corta->assertJsonPath('errors.nombre.0', 'El nombre debe tener al menos 3 caracteres.');
        $corta->assertJsonPath('errors.municipio.0', 'El municipio debe tener al menos 3 caracteres.');
        $this->assertStringNotContainsString('_', implode(' ', array_merge(...array_values($corta->json('errors')))));

        $larga = $this->api('POST', '/api/gestion/comunidades', [
            'nombre' => 'Comunidad de prueba', 'municipio' => 'Concepción', 'departamento' => str_repeat('x', 41),
        ], $personal)->assertStatus(422);
        $this->assertStringContainsString('departamento', $larga->json('errors.departamento.0'));
    }

    public function test_una_comunidad_valida_se_crea_y_se_edita_como_antes(): void
    {
        $personal = $this->tokenDe(self::PERSONAL);
        $nombre = 'Comunidad Integración '.bin2hex(random_bytes(3));

        $alta = $this->api('POST', '/api/gestion/comunidades', [
            'nombre' => $nombre, 'municipio' => 'San Ignacio de Velasco', 'departamento' => 'Santa Cruz', 'activa' => true,
        ], $personal)->assertStatus(201);
        $id = (int) DB::table('comunidad_agricola')->where('nombre', $nombre)->value('id');
        $this->assertGreaterThan(0, $id, (string) $alta->getContent());

        $this->api('PUT', "/api/gestion/comunidades/{$id}", [
            'nombre' => $nombre, 'municipio' => 'Concepción', 'descripcion' => 'Editada por la prueba', 'activa' => true,
        ], $personal)->assertOk();
        $this->assertSame('Concepción', DB::table('comunidad_agricola')->where('id', $id)->value('municipio'));
    }

    /** Solicitud de productor PENDIENTE de una cuenta de prueba; devuelve el id de la ficha. */
    private function crearSolicitudPendiente(): int
    {
        $cuenta = $this->crearCuenta(['CLIENTE']);

        return (int) DB::table('productor')->insertGetId([
            'usuario_id' => $cuenta->id,
            'comunidad_agricola_id' => (int) DB::table('comunidad_agricola')->where('activa', true)->min('id'),
            'estado_solicitud' => 'PENDIENTE',
            'fecha_solicitud' => DB::raw('now()'),
            'ubicacion_actividad' => 'Parcela de prueba de integración',
            'activo' => false,
        ]);
    }
}
