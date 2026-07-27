<?php

declare(strict_types=1);

namespace App\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Conexao com o RabbitMQ e declaracao da topologia compartilhada com o worker Go.
 *
 * A topologia e declarada de forma idempotente por quem conectar primeiro,
 * entao API e worker podem subir em qualquer ordem.
 */
class RabbitMqConnection
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function channel(): AMQPChannel
    {
        if ($this->channel instanceof AMQPChannel && $this->channel->is_open()) {
            return $this->channel;
        }

        // O heartbeat precisa ser folgado em relação ao tempo que o consumidor
        // passa bloqueado em wait(): enquanto está parado ali, a biblioteca não
        // envia batimento. Com wait de 10s e heartbeat de 60s sobra margem
        // suficiente para o servidor nunca dar a conexão como morta.
        $this->connection = new AMQPStreamConnection(
            $this->config['host'],
            $this->config['port'],
            $this->config['user'],
            $this->config['password'],
            $this->config['vhost'],
            heartbeat: (int) ($this->config['heartbeat'] ?? 60),
        );

        $this->channel = $this->connection->channel();
        $this->declareTopology($this->channel);

        return $this->channel;
    }

    private function declareTopology(AMQPChannel $channel): void
    {
        $exchange = $this->config['exchange'];
        $dlx = $this->config['dlx'];
        $queues = $this->config['queues'];
        $keys = $this->config['routing_keys'];

        // durable=true em tudo: o conteudo sobrevive a um restart do broker,
        // que e o que sustenta o requisito de nao perder requisicao em picos.
        $channel->exchange_declare($exchange, AMQPExchangeType::TOPIC, false, true, false);
        $channel->exchange_declare($dlx, AMQPExchangeType::TOPIC, false, true, false);

        $channel->queue_declare($queues['dlq'], false, true, false, false);
        $channel->queue_bind($queues['dlq'], $dlx, '#');

        $channel->queue_declare($queues['processing'], false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => $dlx,
        ]));
        $channel->queue_bind($queues['processing'], $exchange, $keys['uploaded']);

        $channel->queue_declare($queues['status'], false, true, false, false);
        $channel->queue_bind($queues['status'], $exchange, $keys['completed']);
        $channel->queue_bind($queues['status'], $exchange, $keys['failed']);
    }

    public function close(): void
    {
        if ($this->channel instanceof AMQPChannel && $this->channel->is_open()) {
            $this->channel->close();
        }

        if ($this->connection instanceof AMQPStreamConnection && $this->connection->isConnected()) {
            $this->connection->close();
        }

        $this->channel = null;
        $this->connection = null;
    }
}
