<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Cuenta de acceso (tabla usuario). Es el usuario autenticable de Laravel.
 */
class Usuario extends Authenticatable
{
    protected $table = 'usuario';

    public $timestamps = false;

    protected $fillable = [
        'correo', 'password_hash', 'nombres', 'apellidos', 'ci', 'ci_complemento', 'telefono',
        'activo', 'ultimo_acceso', 'fecha_aceptacion_terminos',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'fecha_registro' => 'datetime',
            'ultimo_acceso' => 'datetime',
            'fecha_aceptacion_terminos' => 'datetime',
        ];
    }

    /** Laravel usa este valor para verificar la contraseña. */
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    /** La tabla no tiene remember_token: la sesión web usa tokens propios. */
    public function getRememberTokenName(): ?string
    {
        return null;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'usuario_rol', 'usuario_id', 'rol_id')
            ->withPivot(['estado', 'fecha_activacion', 'fecha_expiracion']);
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombres.' '.$this->apellidos);
    }
}
