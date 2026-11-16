<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SettingController extends Controller
{
    private const TIME_REGEX = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function index()
    {
        return response()->json([
            'success' => true,
            'data'    => $this->data(),
        ]);
    }

    public function update(Request $request)
    {
        $v = Validator::make($request->all(), [
            'late_time'          => ['sometimes', 'required', 'string', 'regex:' . self::TIME_REGEX],
            'checkout_start'     => ['sometimes', 'required', 'string', 'regex:' . self::TIME_REGEX],
            'cooldown_seconds'   => ['sometimes', 'required', 'integer', 'between:1,600'],
            'lcd_idle'           => ['sometimes', 'required', 'string', 'max:16'],
            'require_card_token' => ['sometimes', 'required', 'boolean'],
        ]);

        if ($v->fails()) {
            return $this->fail('VALIDATION', 'Setelan tidak valid', 422, $v->errors());
        }

        $data = $v->validated();

        if (empty($data)) {
            return $this->fail('VALIDATION', 'Tidak ada setelan yang dikirim', 422);
        }

        // Urutan jam: batas terlambat tidak boleh lebih besar dari jam mulai pulang.
        // Dicek memakai nilai gabungan (yang dikirim + yang sudah tersimpan).
        if (array_key_exists('late_time', $data) || array_key_exists('checkout_start', $data)) {
            $late = $data['late_time'] ?? Setting::get('late_time', '07:15');
            $out  = $data['checkout_start'] ?? Setting::get('checkout_start', '12:00');

            if (strcmp($late, $out) > 0) {
                return $this->fail('SETTING_ORDER', 'Batas terlambat tidak boleh lebih besar dari jam mulai pulang', 422);
            }
        }

        DB::transaction(function () use ($data) {
            foreach ($data as $key => $value) {
                if ($key === 'require_card_token') {
                    $value = in_array($value, [true, 1, '1'], true) ? '1' : '0';
                }

                Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
            }
        });

        return response()->json([
            'success' => true,
            'data'    => $this->data(),
        ]);
    }

    private function data(): array
    {
        return [
            'late_time'          => Setting::get('late_time', '07:15'),
            'checkout_start'     => Setting::get('checkout_start', '12:00'),
            'cooldown_seconds'   => (int) Setting::get('cooldown_seconds', '10'),
            'lcd_idle'           => Setting::get('lcd_idle', 'Tempel kartu'),
            'require_card_token' => Setting::get('require_card_token', '0') === '1',
        ];
    }

    private function fail(string $code, string $message, int $http, $errors = null)
    {
        $body = [
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $http);
    }
}
