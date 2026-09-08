<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * ProcessCommandRequest
 *
 * Validasi & sanitasi input sebelum sampai ke controller.
 * Jika validasi gagal, otomatis kembalikan JSON error (bukan redirect).
 */
class ProcessCommandRequest extends FormRequest
{
    /**
     * Semua user boleh mengirim perintah (auth bisa ditambah nanti).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare input data for validation.
     * Otomatis memetakan field 'message' dari ESP32 ke 'text_command' jika dikirim.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('text_command') && $this->has('message')) {
            $this->merge([
                'text_command' => $this->input('message'),
            ]);
        }
    }

    /**
     * Aturan validasi input.
     */
    public function rules(): array
    {
        return [
            // Teks perintah wajib ada (bisa dikirim via 'message' atau 'text_command'), max 500 karakter
            'text_command' => ['required_without:message', 'nullable', 'string', 'min:2', 'max:500'],
            'message' => ['required_without:text_command', 'nullable', 'string', 'min:2', 'max:500'],

            // Sumber input: 'text' (keyboard) atau 'voice' (Speech-to-Text)
            'input_source' => ['sometimes', 'string', 'in:text,voice'],
        ];
    }

    /**
     * Pesan error dalam Bahasa Indonesia.
     */
    public function messages(): array
    {
        return [
            'text_command.required_without' => 'Perintah (text_command/message) tidak boleh kosong.',
            'message.required_without' => 'Perintah (message/text_command) tidak boleh kosong.',
            'text_command.min' => 'Perintah terlalu pendek (minimal 2 karakter).',
            'message.min' => 'Perintah terlalu pendek (minimal 2 karakter).',
            'text_command.max' => 'Perintah terlalu panjang (maksimal 500 karakter).',
            'message.max' => 'Perintah terlalu panjang (maksimal 500 karakter).',
            'input_source.in' => 'Sumber input harus "text" atau "voice".',
        ];
    }

    /**
     * Override: kembalikan JSON jika validasi gagal (bukan redirect HTML).
     * Penting untuk API endpoint yang dikonsumsi Alpine.js/Axios/ESP32.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Input tidak valid.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }

    /**
     * Teks perintah yang sudah dibersihkan (trim whitespace).
     */
    public function getCommand(): string
    {
        return trim((string) ($this->input('message') ?? $this->input('text_command') ?? ''));
    }

    /**
     * Sumber input, default 'text'.
     */
    public function getCommandSource(): string
    {
        return $this->input('input_source', 'text');
    }
}
