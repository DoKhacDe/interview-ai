<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = ['type', 'name', 'content', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];
}
