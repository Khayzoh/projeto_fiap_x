<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $upload = config('fiapx.upload');

        return [
            'video' => [
                'required',
                'file',
                'max:'.$upload['max_size_kb'],
                // Extensao e mimetype validados juntos: a extensao sozinha
                // seria facil de forjar renomeando o arquivo.
                'mimes:'.implode(',', $upload['extensions']),
                'mimetypes:'.implode(',', $upload['mimetypes']),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $extensoes = implode(', ', config('fiapx.upload.extensions'));

        return [
            'video.required' => 'Envie um arquivo de video no campo "video".',
            'video.max' => 'O video excede o tamanho maximo permitido.',
            'video.mimes' => "Formato nao suportado. Use: {$extensoes}.",
            'video.mimetypes' => "Formato nao suportado. Use: {$extensoes}.",
        ];
    }
}
