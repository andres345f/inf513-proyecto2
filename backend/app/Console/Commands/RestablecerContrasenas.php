<?php

namespace App\Console\Commands;

use App\Exceptions\NegocioException;
use App\Services\CredencialesService;
use App\Support\GeneradorContrasenas;
use App\Support\PoliticaContrasena;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Throwable;

/**
 * H-02 · Restablece las contraseñas web para el despliegue.
 *
 * Por defecto genera una contraseña aleatoria fuerte para cada cuenta activa, cierra sus sesiones y entrega las
 * contraseñas UNA sola vez: en un archivo nuevo fuera de public/ (storage/app/private/credenciales/) o, con --mostrar,
 * en la consola. Nunca las escribe en el registro de Laravel ni en la bitácora.
 *
 * Modo --laboratorio: pone a todas la misma contraseña, tomada de la variable de entorno FEDER_CONTRASENA_LABORATORIO
 * (para preparar una demostración). Se rechaza con APP_ENV=production.
 */
class RestablecerContrasenas extends Command
{
    private const VARIABLE_LABORATORIO = 'FEDER_CONTRASENA_LABORATORIO';

    protected $signature = 'feder:restablecer-contrasenas
        {--correo=* : Solo estas cuentas (repetible). Sin esta opción: todas las cuentas activas}
        {--solo-demostracion : Solo las cuentas que todavía tienen la contraseña de demostración}
        {--salida= : Archivo nuevo donde se entregan las contraseñas (fuera de public/; no debe existir)}
        {--mostrar : Muestra las contraseñas en la consola en lugar de guardarlas en un archivo}
        {--laboratorio : Pone a todas la contraseña común de FEDER_CONTRASENA_LABORATORIO (prohibido en producción)}
        {--confirmar : No pregunta antes de cambiar (para guiones de despliegue)}';

    protected $description = 'Asigna una contraseña nueva a las cuentas web, cierra sus sesiones y entrega las contraseñas una sola vez';

    public function handle(CredencialesService $credenciales): int
    {
        $laboratorio = (bool) $this->option('laboratorio');
        $mostrar = (bool) $this->option('mostrar');
        $salida = $this->option('salida');

        if ($laboratorio && ($mostrar || $salida !== null)) {
            return $this->fallar('--laboratorio no entrega contraseñas: no se combina con --mostrar ni con --salida.');
        }
        if ($mostrar && $salida !== null) {
            return $this->fallar('Elige una sola forma de entrega: --mostrar o --salida.');
        }

        $comun = null;
        if ($laboratorio) {
            if (app()->isProduction()) {
                return $this->fallar('El modo --laboratorio no se puede usar en producción (APP_ENV=production). '
                    .'Ejecuta el comando sin --laboratorio para generar una contraseña distinta por cuenta.');
            }
            $comun = Env::get(self::VARIABLE_LABORATORIO);
            if (! is_string($comun) || $comun === '') {
                return $this->fallar('Para --laboratorio define antes la variable de entorno '.self::VARIABLE_LABORATORIO.' con la contraseña común.');
            }
            if (($motivo = PoliticaContrasena::incumplimiento($comun)) !== null) {
                return $this->fallar('La contraseña de '.self::VARIABLE_LABORATORIO." no cumple la política: {$motivo}.");
            }
        }

        try {
            $cuentas = $credenciales->cuentas((array) $this->option('correo'), (bool) $this->option('solo-demostracion'));
        } catch (NegocioException $e) {
            return $this->fallar($e->getMessage());
        }
        if ($cuentas->isEmpty()) {
            $this->info('No hay cuentas que restablecer. No se cambió nada.');

            return self::SUCCESS;
        }

        $archivo = null;
        if (! $laboratorio && ! $mostrar) {
            $archivo = $this->prepararArchivo($salida);
            if ($archivo === null) {
                return self::FAILURE;
            }
        }

        if (! $this->option('confirmar')) {
            if (! $this->input->isInteractive()) {
                return $this->fallar('Sin consola interactiva no se pide confirmación: agrega --confirmar para ejecutarlo desde un guion.');
            }
            if (! $this->confirm("¿Restablecer la contraseña de {$cuentas->count()} cuenta(s) y cerrar sus sesiones?")) {
                return $this->fallar('No se cambió nada.');
            }
        }

        $contrasenas = $cuentas->mapWithKeys(fn ($c) => [$c->id => $comun ?? GeneradorContrasenas::generar()])->all();

        // Primero se entrega (archivo nuevo) y después se cambia la base: nunca queda una cuenta con una contraseña que nadie recibió.
        if ($archivo !== null && ! $this->escribirArchivo($archivo, $cuentas, $contrasenas)) {
            return self::FAILURE;
        }

        try {
            $resultado = $credenciales->restablecer($contrasenas, $laboratorio ? CredencialesService::MODO_LABORATORIO : CredencialesService::MODO_ALEATORIA);
        } catch (Throwable $e) {
            if ($archivo !== null) {
                @unlink($archivo);
            }

            return $this->fallar('No se cambió ninguna contraseña: '.$this->motivoSinDatos($e));
        }

        $this->info("Se restablecieron {$resultado['cuentas']} cuenta(s) y se cerraron {$resultado['sesiones_cerradas']} sesión(es).");

        if ($mostrar) {
            $this->warn('Contraseñas nuevas. Se muestran UNA sola vez y no quedan guardadas: entrégalas por un canal privado.');
            foreach ($cuentas as $cuenta) {
                $this->line($cuenta->correo."\t".$contrasenas[$cuenta->id]);
            }
        } elseif ($archivo !== null) {
            $this->warn("Las contraseñas están en {$archivo}. Entrégalas por un canal privado y borra el archivo.");
        } else {
            $this->line('Todas las cuentas tienen ahora la contraseña común de '.self::VARIABLE_LABORATORIO.'.');
        }

        return self::SUCCESS;
    }

    /** Ruta absoluta del archivo de entrega, o null (con el motivo ya informado) si no se puede usar. */
    private function prepararArchivo(?string $salida): ?string
    {
        if ($salida === null || trim($salida) === '') {
            $carpeta = storage_path('app/private/credenciales');
            if (! is_dir($carpeta) && ! @mkdir($carpeta, 0700, true) && ! is_dir($carpeta)) {
                $this->error("No se pudo crear la carpeta {$carpeta}.");

                return null;
            }
            $salida = $carpeta.DIRECTORY_SEPARATOR.'credenciales-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.txt';
        }

        $carpetaReal = realpath(dirname($salida));
        if ($carpetaReal === false || ! is_dir($carpetaReal)) {
            $this->error('La carpeta del archivo de salida no existe: '.dirname($salida));

            return null;
        }
        $publica = realpath(public_path());
        if ($publica !== false && $this->estaDentro($carpetaReal, $publica)) {
            $this->error('El archivo de contraseñas no puede quedar dentro de public/ (se podría descargar desde la web).');

            return null;
        }
        $ruta = $carpetaReal.DIRECTORY_SEPARATOR.basename($salida);
        if (file_exists($ruta)) {
            $this->error("El archivo {$ruta} ya existe: no se sobrescribe. Indica otro nombre con --salida.");

            return null;
        }

        return $ruta;
    }

    private function escribirArchivo(string $ruta, $cuentas, array $contrasenas): bool
    {
        // «x»: se crea nuevo o falla si alguien lo creó entretanto; nunca se sobrescribe.
        $manejador = @fopen($ruta, 'x');
        if ($manejador === false) {
            $this->error("No se pudo crear el archivo {$ruta}. No se cambió ninguna contraseña.");

            return false;
        }
        @chmod($ruta, 0600);

        $lineas = ['# FEDER Web · contraseñas restablecidas el '.now()->format('d/m/Y H:i').' ('.config('app.timezone').').',
            '# Entrega cada una por un canal privado y borra este archivo. Formato: correo<TAB>contraseña'];
        foreach ($cuentas as $cuenta) {
            $lineas[] = $cuenta->correo."\t".$contrasenas[$cuenta->id];
        }
        $escrito = fwrite($manejador, implode(PHP_EOL, $lineas).PHP_EOL);
        fclose($manejador);

        if ($escrito === false) {
            @unlink($ruta);
            $this->error("No se pudo escribir el archivo {$ruta}. No se cambió ninguna contraseña.");

            return false;
        }

        return true;
    }

    private function estaDentro(string $carpeta, string $base): bool
    {
        $normalizar = fn (string $ruta) => rtrim(mb_strtolower(str_replace('\\', '/', $ruta)), '/').'/';

        return str_starts_with($normalizar($carpeta), $normalizar($base));
    }

    /** Motivo de una falla sin datos de la consulta (los parámetros incluirían hashes). */
    private function motivoSinDatos(Throwable $e): string
    {
        if ($e instanceof NegocioException) {
            return $e->getMessage();
        }
        if ($e instanceof QueryException) {
            return 'error de la base de datos (SQLSTATE '.($e->errorInfo[0] ?? '?').').';
        }

        return 'error inesperado ('.class_basename($e).').';
    }

    private function fallar(string $mensaje): int
    {
        $this->error($mensaje);

        return self::FAILURE;
    }
}
