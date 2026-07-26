<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notificacao enviada ao usuario quando o processamento de um video falha
 * em definitivo, atendendo ao requisito de comunicacao de erro.
 */
class VideoProcessingFailedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Video $video) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'FIAP X - Falha no processamento do video '.$this->video->original_filename,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.video-failed',
            with: [
                'nomeArquivo' => $this->video->original_filename,
                'videoId' => $this->video->id,
                'motivo' => $this->video->error_message,
                'tentativas' => $this->video->attempts,
                'ocorridoEm' => $this->video->processed_at?->format('d/m/Y H:i:s'),
            ],
        );
    }
}
