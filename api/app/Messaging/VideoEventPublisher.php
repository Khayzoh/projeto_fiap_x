<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Models\Video;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Publica o pedido de processamento consumido pelo worker Go.
 */
class VideoEventPublisher
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly RabbitMqConnection $connection,
        private readonly array $config,
    ) {}

    /**
     * Anuncia que um video foi recebido e esta pronto para processamento.
     *
     * O payload segue exatamente a struct domain.Job do worker.
     */
    public function publishUploaded(Video $video, string $correlationId): void
    {
        $payload = [
            'video_id' => $video->id,
            'user_id' => $video->user_id,
            'object_key' => $video->object_key,
            'filename' => $video->original_filename,
            'fps' => (int) config('fiapx.processing.fps'),
            'correlation_id' => $correlationId,
        ];

        $message = new AMQPMessage(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            [
                'content_type' => 'application/json',
                // Mensagem persistente: sobrevive ao restart do broker.
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'correlation_id' => $correlationId,
                'timestamp' => time(),
                'application_headers' => new AMQPTable([
                    'x-attempt' => 0,
                    'x-correlation-id' => $correlationId,
                    'x-source' => 'fiapx-api',
                ]),
            ]
        );

        $channel = $this->connection->channel();
        $channel->basic_publish(
            $message,
            $this->config['exchange'],
            $this->config['routing_keys']['uploaded']
        );
    }
}
