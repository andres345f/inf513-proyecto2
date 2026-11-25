<?php

namespace App\Repositories;

use App\Models\ConfirmacionOperacion;
use App\Support\EstructuraFilaWeb;
use App\Support\PoliticaPermisos;
use Illuminate\Support\Collection;

/**
 * Configuración global de la web guardada en confirmacion_operacion (usuario_id NULL):
 * permisos por rol, menú, tarifa de envío y pesos de productos.
 *
 * La tabla no valida el JSON, así que cada fila se comprueba con EstructuraFilaWeb:
 * - al escribir, una estructura inválida se rechaza (InvalidArgumentException);
 * - al leer, la fila mal formada se descarta con un aviso en el registro y el resto sigue funcionando.
 * Las filas se leen siempre en orden de id: si alguien duplica una, manda la más antigua, no la que el motor devuelva primero.
 */
class ConfiguracionWebRepository
{
    /**
     * Permisos del rol. Si su fila no existe o está mal formada, el rol queda sin permisos (se niega; nunca se concede de más).
     *
     * @return list<string>
     */
    public function permisosDeRol(string $rolCodigo): array
    {
        $fila = $this->filasValidas(ConfirmacionOperacion::PERMISO_ROL)
            ->first(fn (ConfirmacionOperacion $f) => $f->parametros['rol'] === $rolCodigo);

        return PoliticaPermisos::efectivos($rolCodigo, $fila?->parametros['permisos'] ?? []);
    }

    /** Todas las filas de permisos: [rol => list<permiso>]. Con filas duplicadas de un rol, cuenta la más antigua. */
    public function matrizPermisos(): array
    {
        $matriz = [];
        foreach ($this->filasValidas(ConfirmacionOperacion::PERMISO_ROL) as $fila) {
            $rol = $fila->parametros['rol'];
            if (in_array($rol, PoliticaPermisos::ROLES, true)) {
                $matriz[$rol] ??= PoliticaPermisos::efectivos($rol, $fila->parametros['permisos']);
            }
        }

        return $matriz;
    }

    /** Selecciona la misma fila vigente que rige en las lecturas y serializa las ediciones del rol. */
    public function bloquearPermisosDeRol(string $rol): ?ConfirmacionOperacion
    {
        $filas = $this->filasGlobales(ConfirmacionOperacion::PERMISO_ROL)
            ->whereRaw("parametros->>'rol' = ?", [$rol])->lockForUpdate()->get();

        return $this->soloValidas($filas, ConfirmacionOperacion::PERMISO_ROL)->first();
    }

    public function guardarPermisosDeRol(string $rolCodigo, array $permisos): ?int
    {
        $nuevos = ['rol' => $rolCodigo, 'permisos' => array_values($permisos)];
        EstructuraFilaWeb::exigir(ConfirmacionOperacion::PERMISO_ROL, $nuevos);
        if (array_diff($permisos, PoliticaPermisos::admisibles($rolCodigo)) !== []) {
            throw new \InvalidArgumentException('El rol no admite esos permisos.');
        }

        $fila = $this->bloquearPermisosDeRol($rolCodigo);
        if ($fila === null) {
            return null;
        }

        $fila->parametros = $nuevos;
        $fila->save();

        return (int) $fila->id;
    }

    /**
     * Entradas del menú ordenadas por "orden".
     *
     * @return Collection<int, array{etiqueta:string, ruta:string, permiso:string, icono:string, grupo:string, orden:int}>
     */
    public function entradasMenu(): Collection
    {
        return $this->filasValidas(ConfirmacionOperacion::MENU_WEB)
            ->map(fn (ConfirmacionOperacion $fila) => $fila->parametros)
            ->sortBy('orden')
            ->values();
    }

    /** Tarifa de envío vigente, o [] si no hay ninguna fila bien formada (EnvioService lo informa como «no configurada»). */
    public function tarifaEnvio(): array
    {
        return $this->filasValidas(ConfirmacionOperacion::TARIFA_ENVIO)->first()?->parametros ?? [];
    }

    /** Peso por unidad de venta (kg) de los productos indicados: [producto_id => peso_kg]. */
    public function pesosDeProductos(array $productoIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productoIds)));
        if ($ids === []) {
            return [];
        }

        // Se compara como texto: convertir «parametros->>'producto_id'» a número haría fallar toda la consulta
        // (SQLSTATE 22P02) si una sola fila trajera un valor no numérico.
        $pesos = [];
        $consulta = $this->filasGlobales(ConfirmacionOperacion::PESO_PRODUCTO)
            ->whereRaw("parametros->>'producto_id' = ANY (?::text[])", ['{'.implode(',', $ids).'}']);
        foreach ($this->soloValidas($consulta->get(), ConfirmacionOperacion::PESO_PRODUCTO) as $fila) {
            $pesos[(int) $fila->parametros['producto_id']] ??= (float) $fila->parametros['peso_kg'];
        }

        return $pesos;
    }

    /** Crea o actualiza el peso por unidad de venta de un producto (fila PESO_PRODUCTO). */
    public function guardarPesoProducto(int $productoId, float $pesoKg, string $correoSistema): void
    {
        $nuevos = ['producto_id' => $productoId, 'peso_kg' => $pesoKg];
        EstructuraFilaWeb::exigir(ConfirmacionOperacion::PESO_PRODUCTO, $nuevos);

        $fila = $this->filasGlobales(ConfirmacionOperacion::PESO_PRODUCTO)
            ->whereRaw("parametros->>'producto_id' = ?", [(string) $productoId])->first();

        if ($fila) {
            $fila->parametros = $nuevos;
            $fila->save();

            return;
        }

        ConfirmacionOperacion::query()->create([
            'codigo_hash' => hash('sha256', 'FEDER_WEB:PESO_PRODUCTO:'.$productoId),
            'correo' => $correoSistema,
            'usuario_id' => null,
            'comando' => ConfirmacionOperacion::PESO_PRODUCTO,
            'parametros' => $nuevos,
            'expira_en' => '9999-12-31 23:59:59-04',
        ]);
    }

    /** @return Collection<int, ConfirmacionOperacion> filas vigentes del comando con estructura válida, por id */
    private function filasValidas(string $comando): Collection
    {
        return $this->soloValidas($this->filasGlobales($comando)->get(), $comando);
    }

    /** Descarta (y avisa en el registro) las filas cuyo JSON no tiene la forma que corresponde a su comando. */
    private function soloValidas(Collection $filas, string $comando): Collection
    {
        return $filas->filter(function (ConfirmacionOperacion $fila) use ($comando) {
            $errores = EstructuraFilaWeb::errores($comando, $fila->parametros);
            if ($errores !== []) {
                EstructuraFilaWeb::avisar($comando, $fila->id, $errores);
            }

            return $errores === [];
        })->values();
    }

    private function filasGlobales(string $comando)
    {
        return ConfirmacionOperacion::query()
            ->whereNull('usuario_id')
            ->where('comando', $comando)
            ->whereNull('usada_en')
            ->orderBy('id');
    }
}
