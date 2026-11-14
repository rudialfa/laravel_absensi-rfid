<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\TeacherAttendance;
use App\Models\User;
use App\Services\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTeacherAttendanceNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $attendanceId,
        public string $type // 'masuk' | 'pulang'
    ) {}

    public function handle(PushSender $sender): void
    {
        $attendance = TeacherAttendance::with('teacher')->find($this->attendanceId);
        if (!$attendance || !$attendance->teacher) {
            return;
        }

        $name = $attendance->teacher->name;

        if ($this->type === 'masuk') {
            $body = "{$name} (guru) sudah masuk pukul " . $attendance->checked_in_at->format('H:i');
        } else {
            if (!$attendance->checked_out_at) {
                return; // belum pulang, tidak ada yang dikirim
            }
            $body = "{$name} (guru) sudah pulang pukul " . $attendance->checked_out_at->format('H:i');
        }

        // Penerima: hanya admin
        $tokens = DeviceToken::whereIn('user_id', User::where('role', 'admin')->pluck('id'))
            ->pluck('fcm_token')
            ->all();

        if (empty($tokens)) {
            return;
        }

        $sender->send($tokens, 'Absensi Guru', $body);
    }
}
