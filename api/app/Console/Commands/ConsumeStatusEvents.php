<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\RabbitMqConnection;
use App\Services\VideoStatusUpdater;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Consome a fila de resultados publicada pelo worker Go e reflete o
 * desfecho de cada video no banco.
 *
 * Roda como processo separado da API (deployment proprio no Kubernetes)
 * para que o consumo nao concorra com o trafego HTTP.
 */
class ConsumeStatusEvents extends Command
{
    protected $signature = 'fiapx:consume-status
                            {--max-messages=0 : Encerra apos N mensagens (0 = sem limite)}';

    protected $description = 'Consome os eventos de conclusao e falha do processamento de videos';

    private int $processadas = 0;

    public function handle(RabbitMqConnection $connection, VideoStatusUpdater $updater): int
    {
        $config = config('fiapx.rabbitmq');
        $limite = (int) $this->option('max-messages');

        $channel = $connection->channel();

        // Uma mensagem por vez: o consumo e barato e a ordem por video importa.
        $channel->basic_qos(0, 1, false);

        $channel->basic_consume(
            $config['queues']['status'],
            consumer_tag: 'fiapx-api-status',
            no_local: false,
            no_ack: false,
            exclusive: false,
            nowait: false,
            callback: function (AMQPMessage $message) use ($updater, $limite): void {
                $this->handleMessage($message, $updater);

                $this->processadas++;
                if ($limite > 0 && $this->processadas >= $limite) {
                    $message->getChannel()->basic_cancel('fiapx-api-status');
                }
            }
        );

        $this->info('Consumidor de status iniciado. Aguardando eventos...');

        while ($channel->is_consuming()) {
            try {
                $channel->wait(timeout: 30);
            } catch (AMQPTimeoutException) {
                // Timeout apenas devolve o controle: permite ao processo
                // reagir a sinais de encerramento entre mensagens.
                continue;
            }
        }

        $connection->close();

        return self::SUCCESS;
    }

    private function handleMessage(AMQPMessage $message, VideoStatusUpdater $updater): void
    {
        $routingKey = $message->getRoutingKey();
        $keys = config('fiapx.rabbitmq.routing_keys');

        try {
            $payload = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Mensagem ilegivel nunca vai melhorar: descarta para a DLQ.
            Log::error('evento de status ilegivel', ['error' => $e->getMessage()]);
            $message->nack(requeue: false);

            return;
        }

        try {
            $tratado = match ($routingKey) {
                $keys['completed'] => $updater->markCompleted($payload),
                $keys['failed'] => $updater->markFailed($payload),
                default => $this->ignorar($routingKey),
            };

            if (! $tratado) {
                // Video inexistente (ex.: removido antes do fim): confirmar e seguir,
                // pois reenfileirar geraria um laco infinito.
                Log::warning('evento sem video correspondente', [
                    'routing_key' => $routingKey,
                    'video_id' => $payload['video_id'] ?? null,
                ]);
            }

            $message->ack();
        } catch (\Throwable $e) {
            // Falha de infraestrutura (banco fora): devolve para a fila.
            Log::error('erro ao processar evento de status', [
                'routing_key' => $routingKey,
                'error' => $e->getMessage(),
            ]);
            $message->nack(requeue: true);
        }
    }

    private function ignorar(string $routingKey): bool
    {
        Log::warning('routing key desconhecida na fila de status', ['routing_key' => $routingKey]);

        return true;
    }
}
