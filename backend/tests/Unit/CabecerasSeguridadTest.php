<?php

namespace Tests\Unit;

use App\Http\Middleware\AgregarCabecerasSeguridad;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/** H-30: la política del navegador debe permitir lo que la propia aplicación usa, y nada más. */
class CabecerasSeguridadTest extends TestCase
{
    private function cabeceras(): Response
    {
        return (new AgregarCabecerasSeguridad())->handle(Request::create('/catalogo'), fn () => new Response('ok'));
    }

    /** @return array<string, string> directiva => valor */
    private function directivasCsp(Response $respuesta): array
    {
        $directivas = [];
        foreach (explode(';', (string) $respuesta->headers->get('Content-Security-Policy')) as $parte) {
            $partes = preg_split('/\s+/', trim($parte), 2);
            if ($partes[0] !== '') {
                $directivas[$partes[0]] = $partes[1] ?? '';
            }
        }

        return $directivas;
    }

    public function test_la_geolocalizacion_se_permite_solo_al_propio_sitio(): void
    {
        $politica = (string) $this->cabeceras()->headers->get('Permissions-Policy');

        $this->assertStringContainsString('geolocation=(self)', $politica, '«Usar mi ubicación» del checkout necesita geolocalización en el propio origen.');
        $this->assertStringContainsString('camera=()', $politica);
        $this->assertStringContainsString('microphone=()', $politica);
    }

    public function test_la_csp_permite_las_fuentes_de_google_que_carga_index_html(): void
    {
        $csp = $this->directivasCsp($this->cabeceras());

        $this->assertStringContainsString('https://fonts.googleapis.com', $csp['style-src']);
        $this->assertStringContainsString('https://fonts.gstatic.com', $csp['font-src']);
    }

    public function test_openstreetmap_recibe_referente_y_las_dos_fuentes_de_cabeceras_coinciden(): void
    {
        $respuesta = $this->cabeceras();
        $csp = $this->directivasCsp($respuesta);

        $this->assertSame('strict-origin-when-cross-origin', $respuesta->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('https://tile.openstreetmap.org', $csp['img-src']);

        $htaccess = file_get_contents(__DIR__.'/../../public/.htaccess');
        $this->assertIsString($htaccess);
        $this->assertStringContainsString('Header always set Referrer-Policy "strict-origin-when-cross-origin"', $htaccess);
        $this->assertStringContainsString('https://tile.openstreetmap.org', $htaccess);
    }

    public function test_la_csp_no_se_relaja_en_lo_demas(): void
    {
        $csp = $this->directivasCsp($this->cabeceras());

        $this->assertSame("'self'", $csp['script-src'], 'No se permiten scripts externos ni en línea.');
        $this->assertSame("'self'", $csp['default-src']);
        $this->assertSame("'self'", $csp['connect-src']);
        $this->assertSame("'none'", $csp['object-src']);
        $this->assertSame("'none'", $csp['frame-ancestors']);
        $this->assertStringNotContainsString('fonts.g', $csp['script-src'].$csp['connect-src'].$csp['img-src']);
    }
}
