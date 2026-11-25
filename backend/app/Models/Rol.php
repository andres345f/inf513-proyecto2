<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo cerrado de los cuatro roles (tabla rol). Solo lectura desde la web.
 */
class Rol extends Model
{
    protected $table = 'rol';

    public $timestamps = false;

    protected $guarded = ['*'];
}
