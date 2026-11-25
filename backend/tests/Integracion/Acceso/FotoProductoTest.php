<?php

namespace Tests\Integracion\Acceso;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FotoProductoTest extends AccesoTestCase
{
    private const RUTA = '/api/gestion/productos';

    private string $operador;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fotos_productos');
        $this->operador = $this->tokenDe(self::PERSONAL, 'PERSONAL_FEDER');
    }

    private function datos(string $codigo = 'FOTO-PRUEBA'): array
    {
        return ['categoria_id' => 1, 'codigo' => $codigo, 'nombre' => 'Producto de prueba',
            'presentacion' => 'Unidad de muestra', 'unidad_medida' => 'UNIDAD', 'precio_venta' => 30, 'stock_minimo' => 0];
    }

    private function enviar(array $datos, ?int $id = null, ?string $token = null)
    {
        return $this->post(self::RUTA.($id ? '/'.$id : ''), $datos + ($id ? ['_method' => 'PUT'] : []),
            ['Accept' => 'application/json', 'Authorization' => 'Bearer '.($token ?? $this->operador)]);
    }

    private function foto(string $tipo = 'jpeg'): UploadedFile
    {
        return UploadedFile::fake()->image('muestra.'.$tipo, 36, 24);
    }

    private function ruta(int $id): ?string
    {
        return $this->getJson('/api/catalogo/'.$id)->assertOk()->json('imagen_url');
    }

    public function test_alta_sin_foto_conservacion_reemplazo_y_retirada(): void
    {
        $sinFoto = $this->enviar($this->datos('SIN-FOTO'))->assertCreated()->json('id');
        $this->assertNull($this->ruta($sinFoto));
        $id = $this->enviar($this->datos() + ['foto' => $this->foto()])->assertCreated()->json('id');
        $anterior = $this->ruta($id);
        $this->assertMatchesRegularExpression('~^/imagenes/gestionadas/[0-9a-f-]{36}\.jpg$~', $anterior);
        $this->api('PUT', self::RUTA.'/'.$id, ['precio_venta' => 38] + $this->datos(), $this->operador)->assertOk();
        $this->getJson('/api/catalogo/'.$id)->assertJsonPath('imagen_url', $anterior)->assertJsonPath('precio', 38);
        $this->enviar($this->datos() + ['foto' => $this->foto('png')], $id)->assertOk();
        $nueva = $this->ruta($id);
        $this->assertStringEndsWith('.png', $nueva);
        $disco = Storage::disk('fotos_productos');
        $disco->assertMissing(basename($anterior));
        $disco->assertExists(basename($nueva));
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($disco->get(basename($nueva)))[2]);
        $this->enviar($this->datos() + ['retirar_foto' => 1], $id)->assertOk();
        $this->assertNull($this->ruta($id));
        $disco->assertMissing(basename($nueva));
    }

    public function test_compartida_se_conserva_mientras_haya_otra_referencia(): void
    {
        $id = $this->enviar($this->datos() + ['foto' => $this->foto()])->assertCreated()->json('id');
        $anterior = $this->ruta($id);
        $otro = $this->enviar($this->datos('OTRO-FOTO'))->assertCreated()->json('id');
        DB::table('producto')->where('id', $otro)->update(['imagen_url' => $anterior]);
        $this->enviar($this->datos() + ['foto' => $this->foto()], $id)->assertOk();
        $this->assertSame($anterior, $this->ruta($otro));
        Storage::disk('fotos_productos')->assertExists(basename($anterior));
    }

    public function test_foto_invalida_excesiva_demasiado_ancha_o_sin_autorizacion(): void
    {
        foreach ([UploadedFile::fake()->createWithContent('dañada.jpg', 'Archivo incompleto de prueba'),
            UploadedFile::fake()->create('grande.jpg', 5121, 'image/jpeg'),
            UploadedFile::fake()->image('ancha.png', 4097, 1)] as $archivo) {
            $this->enviar($this->datos() + ['foto' => $archivo])->assertUnprocessable()->assertJsonValidationErrors('foto');
        }
        $this->enviar($this->datos() + ['imagen_url' => '/otra/ruta.jpg'])->assertUnprocessable();
        $this->enviar($this->datos() + ['foto' => $this->foto()], null, $this->tokenDe(self::PRODUCTORA, 'PRODUCTOR'))->assertForbidden();
        $this->assertSame([], Storage::disk('fotos_productos')->allFiles());
    }

    public function test_si_la_operacion_falla_despues_de_guardar_el_archivo_se_mantiene_la_foto_vigente(): void
    {
        $id = $this->enviar($this->datos() + ['foto' => $this->foto()])->assertCreated()->json('id');
        $anterior = $this->ruta($id);
        $auditoria = $this->mock(\App\Services\AuditoriaService::class);
        $auditoria->shouldReceive('operacion')->once()->andThrow(new \RuntimeException('Fallo de escritura de prueba.'));
        $this->enviar($this->datos() + ['foto' => $this->foto('png')], $id)->assertServerError();
        $this->assertSame($anterior, $this->ruta($id));
        $this->assertSame([basename($anterior)], Storage::disk('fotos_productos')->allFiles());
    }

    public function test_fallo_del_disco_conserva_la_referencia_y_el_archivo_anterior(): void
    {
        $id = $this->enviar($this->datos() + ['foto' => $this->foto()])->assertCreated()->json('id');
        $anterior = $this->ruta($id);
        $sano = Storage::disk('fotos_productos');
        $fallido = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $fallido->shouldReceive('put')->once()->andReturn(false);
        $fallido->shouldReceive('delete')->once()->andReturn(true);
        Storage::set('fotos_productos', $fallido);
        $this->enviar($this->datos() + ['foto' => $this->foto('png')], $id)->assertStatus(503);
        $this->assertSame($anterior, $this->ruta($id));
        $sano->assertExists(basename($anterior));
    }

    public function test_reemplazar_una_imagen_precargada_no_modifica_el_archivo_ni_los_demas_productos(): void
    {
        $ruta = '/imagenes/productos/aceite-totai-20ml.jpg';
        $preexistente = hash_file('sha256', public_path($ruta));
        $id = $this->enviar($this->datos())->assertCreated()->json('id');
        $otro = $this->enviar($this->datos('OTRA-FOTO'))->assertCreated()->json('id');
        DB::table('producto')->whereIn('id', [$id, $otro])->update(['imagen_url' => $ruta]);
        $this->enviar($this->datos() + ['foto' => $this->foto()], $id)->assertOk();
        $this->assertNotSame($ruta, $this->ruta($id));
        $this->assertSame($ruta, $this->ruta($otro));
        $this->assertSame($preexistente, hash_file('sha256', public_path($ruta)));
    }

    public function test_categoria_y_producto_se_pueden_retirar_y_reactivar_sin_perder_referencias(): void
    {
        $url = '/api/gestion/categorias';
        $categoria = $this->api('POST', $url, ['nombre' => 'Fotos nuevas'], $this->operador)->assertCreated()->json('id');
        $datos = ['categoria_id' => $categoria] + $this->datos();
        $id = $this->enviar($datos)->assertCreated()->json('id');
        $this->api('PUT', $url.'/'.$categoria, ['nombre' => 'Fotos del catálogo', 'activa' => false], $this->operador)->assertOk();
        $this->getJson('/api/catalogo/'.$id)->assertNotFound();
        $this->api('PUT', $url.'/'.$categoria, ['nombre' => 'Fotos del catálogo', 'activa' => true], $this->operador)->assertOk();
        $this->getJson('/api/catalogo/'.$id)->assertOk()->assertJsonPath('categoria', 'Fotos del catálogo');
        $this->enviar($datos + ['activo' => 0], $id)->assertOk();
        $this->getJson('/api/catalogo/'.$id)->assertNotFound();
        $this->enviar($datos + ['activo' => 1], $id)->assertOk();
        $this->getJson('/api/catalogo/'.$id)->assertOk();
        $this->api('PUT', $url.'/2147483647', ['nombre' => 'Inexistente'], $this->operador)->assertNotFound();
    }
}
