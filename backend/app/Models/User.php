<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = [
        'phone_number',
        'daily_requests_count',
        'daily_requests_date',
        'last_sms_fingerprint',
        'last_sms_reply',
        'last_sms_received_at',
    ];

    protected function casts(): array
    {
        return [
            'daily_requests_count' => 'integer',
            'daily_requests_date' => 'date:Y-m-d',
            'last_sms_received_at' => 'datetime',
        ];
    }
}
