<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabla técnica confirmacion_operacion.
 *
 * En FEDER Web se usa como almacén JSON tipado por la columna "comando":
 * SESION_WEB, PERMISO_ROL, MENU_WEB, TARIFA_ENVIO, PESO_PRODUCTO, METODO_PAGO, PLAN_PAGO.
 */
class ConfirmacionOperacion extends Model
{
    public const SESION_WEB = 'SESION_WEB';
    public const PERMISO_ROL = 'PERMISO_ROL';
    public const MENU_WEB = 'MENU_WEB';
    public const TARIFA_ENVIO = 'TARIFA_ENVIO';
    public const PESO_PRODUCTO = 'PESO_PRODUCTO';
    public const SOLICITUD_PRODUCTO = 'SOLICITUD_PRODUCTO';
    public const CAMBIO_COMUNIDAD_PRODUCTOR = 'CAMBIO_COMUNIDAD';
    public const LOGISTICA_COMPRA = 'LOGISTICA_COMPRA';
    public const ASIGNACION_ENTREGA = 'ASIGNACION_ENTREGA';

    protected $table = 'confirmacion_operacion';

    public $timestamps = false;

    protected $fillable = [
        'codigo_hash', 'correo', 'usuario_id', 'comando', 'parametros', 'expira_en', 'usada_en',
    ];

    protected function casts(): array
    {
        return [
            'parametros' => 'array',
            'creada_en' => 'datetime',
            'expira_en' => 'datetime',
            'usada_en' => 'datetime',
        ];
    }
}
