<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class LogPushSender implements PushSender
{
    public function send(array $tokens, string $title, string $body): void
    {
        Log::info('PUSH (palsu)', [
            'jumlah_token' => count($tokens),
            'title' => $title,
            'body' => $body,
        ]);
    }
}
