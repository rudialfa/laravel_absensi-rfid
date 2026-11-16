<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function index()
    {
        $devices = Device::orderBy('name')->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'data'    => $devices->map(fn(Device $d) => $this->data($d))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
        ]);

        if ($v->fails()) {
            return $this->validation($v);
        }

        $plain = Str::random(40);

        $device = Device::create([
            'name'         => $v->validated()['name'],
            'api_key_hash' => hash('sha256', $plain),
        ]);

        return response()->json([
            'success'    => true,
            'data'       => $this->data($device),
            'device_key' => $plain, // hanya tampil sekali
        ], 201);
    }

    public function update(Request $request, Device $device)
    {
        $v = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
        ]);

        if ($v->fails()) {
            return $this->validation($v);
        }

        $device->update(['name' => $v->validated()['name']]);

        return response()->json([
            'success' => true,
            'data'    => $this->data($device->fresh()),
        ]);
    }

    /** Ganti key perangkat. Key lama langsung tidak berlaku. */
    public function rotateKey(Device $device)
    {
        $plain = Str::random(40);

        $device->update(['api_key_hash' => hash('sha256', $plain)]);

        return response()->json([
            'success'    => true,
            'data'       => $this->data($device->fresh()),
            'device_key' => $plain, // hanya tampil sekali
        ]);
    }

    public function destroy(Device $device)
    {
        $blocked = false;

        DB::transaction(function () use ($device, &$blocked) {
            // Kunci semua baris supaya dua penghapusan bersamaan tidak sama-sama lolos
            $ids = Device::lockForUpdate()->pluck('id');

            if ($ids->count() <= 1) {
                $blocked = true;
                return;
            }

            $device->delete(); // device_id di absensi otomatis jadi kosong
        });

        if ($blocked) {
            return response()->json([
                'success' => false,
                'code'    => 'LAST_DEVICE',
                'message' => 'Perangkat terakhir tidak boleh dihapus',
            ], 422);
        }

        return response()->json(['success' => true]);
    }

    private function data(Device $d): array
    {
        return [
            'id'         => $d->id,
            'name'       => $d->name,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    private function validation($validator)
    {
        return response()->json([
            'success' => false,
            'code'    => 'VALIDATION',
            'message' => 'Nama perangkat wajib diisi (maksimal 100 karakter)',
            'errors'  => $validator->errors(),
        ], 422);
    }
}
