<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboundSms extends Model
{
    protected $table = 'inbound_sms';

    protected $fillable = [
        'from_phone', 'to_shortcode', 'body', 'provider',
        'provider_message_id', 'parsed_intent', 'parsed_args',
        'received_at', 'processed_at', 'reply_sent',
    ];

    protected function casts(): array
    {
        return [
            'parsed_args'   => 'array',
            'received_at'   => 'datetime',
            'processed_at'  => 'datetime',
        ];
    }
}
