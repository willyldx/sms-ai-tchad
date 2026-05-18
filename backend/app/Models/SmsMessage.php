<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsMessage extends Model
{
    /**
     * Cette table n'utilise pas les colonnes standard updated_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'phone_number',
        'role',
        'content',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
