<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAttendanceNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $attendanceId,
        public string $type // 'masuk' | 'pulang'
    ) {}

    public function handle(PushSender $sender): void
    {
        $attendance = Attendance::with('student.guardians')->find($this->attendanceId);
        if (!$attendance) {
            return;
        }

        $student = $attendance->student;

        // Penerima: wali murid ini + semua admin
        $userIds = $student->guardians->pluck('id')
            ->merge(User::where('role', 'admin')->pluck('id'))
            ->unique();

        $tokens = DeviceToken::whereIn('user_id', $userIds)->pluck('fcm_token')->all();

        if ($this->type === 'masuk') {
            $jam  = $attendance->checked_in_at->format('H:i');
            $body = "{$student->name} sudah masuk pukul {$jam}";
            if ($attendance->status === 'terlambat') {
                $body .= ' (terlambat)';
            }
        } else {
            $jam  = $attendance->checked_out_at->format('H:i');
            $body = "{$student->name} sudah pulang pukul {$jam}";
        }

        if (empty($tokens)) {
            return;
        }

        $sender->send($tokens, 'Absensi', $body);
    }
}
