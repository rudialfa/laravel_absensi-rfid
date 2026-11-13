<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebasePushSender implements PushSender
{
    public function __construct(private Messaging $messaging) {}

    /** @param string[] $tokens */
    public function send(array $tokens, string $title, string $body): void
    {
        $tokens = array_values(array_unique($tokens));
        if (empty($tokens)) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body));

        // FCM menerima maksimal 500 token per kirim
        foreach (array_chunk($tokens, 500) as $chunk) {
            $report = $this->messaging->sendMulticast($message, $chunk);

            // Hapus token yang sudah tidak valid supaya tidak dikirimi terus
            $dead = array_merge($report->invalidTokens(), $report->unknownTokens());
            if (!empty($dead)) {
                DeviceToken::whereIn('fcm_token', $dead)->delete();
                Log::warning('FCM: token tidak valid dihapus', ['jumlah' => count($dead)]);
            }

            Log::info('FCM terkirim', [
                'sukses' => $report->successes()->count(),
                'gagal'  => $report->failures()->count(),
            ]);
        }
    }
}
