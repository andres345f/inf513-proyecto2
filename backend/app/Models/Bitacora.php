<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de auditoría (tabla bitacora). Solo admite inserciones: un disparador impide UPDATE y DELETE.
 */
class Bitacora extends Model
{
    public const INICIO_SESION = 'INICIO_SESION';
    public const CREAR = 'CREAR';

    /** Entidad con la que se registran las visitas a páginas del sitio web. */
    public const ENTIDAD_ACCESO_WEB = 'ACCESO_WEB';

    protected $table = 'bitacora';

    public $timestamps = false;

    protected $fillable = ['usuario_id', 'fecha', 'accion', 'entidad', 'entidad_id', 'detalle', 'direccion_ip'];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'detalle' => 'array',
        ];
    }
}
