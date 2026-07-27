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
 * Avisa o usuario de que o processamento terminou e o ZIP esta disponivel.
 *
 * O processamento e assincrono: sem este aviso, quem envia um video longo
 * precisaria ficar consultando a tela para saber se ja acabou.
 */
class VideoProcessedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Video $video) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'FIAP X - Seu vídeo está pronto: '.$this->video->original_filename,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.video-ready',
            with: [
                'nomeArquivo' => $this->video->original_filename,
                'quantidadeFrames' => $this->video->frame_count,
                'tamanhoZip' => $this->formatarTamanho($this->video->zip_size_bytes),
                'concluidoEm' => $this->video->processed_at?->format('d/m/Y H:i:s'),
            ],
        );
    }

    private function formatarTamanho(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
