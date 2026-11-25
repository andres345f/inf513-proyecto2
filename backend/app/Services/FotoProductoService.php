<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Repositories\ProductoRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FotoProductoService
{
    private const PREFIJO = '/imagenes/gestionadas/';

    public function __construct(private readonly ProductoRepository $productos) {}

    public function guardar(UploadedFile $archivo): string
    {
        if (! extension_loaded('gd')) {
            throw new NegocioException('La carga de fotos requiere habilitar la extensión GD de PHP.', 503);
        }
        $contenido = $archivo->get();
        $info = @getimagesizefromstring($contenido);
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
            || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 8000000) {
            throw ValidationException::withMessages(['foto' => 'Elige un JPEG o PNG de hasta 4096 píxeles por lado y 8 megapíxeles.']);
        }
        $imagen = @imagecreatefromstring($contenido);
        if ($imagen === false) {
            throw ValidationException::withMessages(['foto' => 'La imagen está incompleta o dañada.']);
        }

        $png = $info[2] === IMAGETYPE_PNG;
        $nombre = Str::uuid().($png ? '.png' : '.jpg');
        $stream = tmpfile();
        if ($stream === false) {
            throw new NegocioException('No se pudo preparar la foto para guardarla.', 503);
        }
        try {
            imagesavealpha($imagen, true);
            $escrita = $png ? imagepng($imagen, $stream, 6) : imagejpeg($imagen, $stream, 88);
            if (! $escrita || ftell($stream) < 1 || ! rewind($stream)) {
                throw new NegocioException('No se pudo convertir la foto.', 422);
            }
            if (! Storage::disk('fotos_productos')->put($nombre, $stream)) {
                throw new NegocioException('No se pudo guardar la foto. Inténtalo de nuevo.', 503);
            }
        } catch (Throwable $error) {
            $this->eliminarSiLibre(self::PREFIJO.$nombre);
            if ($error instanceof NegocioException) {
                throw $error;
            }
            report($error);
            throw new NegocioException('No se pudo guardar la foto. Inténtalo de nuevo.', 503);
        } finally {
            fclose($stream);
        }

        return self::PREFIJO.$nombre;
    }

    public function eliminarSiLibre(?string $ruta): void
    {
        if (! $ruta || ! preg_match('~\A/imagenes/gestionadas/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(?:jpg|png))\z~D', $ruta, $partes)) {
            return;
        }

        try {
            if (! $this->productos->usaImagen($ruta)) {
                Storage::disk('fotos_productos')->delete($partes[1]);
            }
        } catch (Throwable $error) {
            report($error);
        }
    }
}
