// Package messaging adapta o RabbitMQ ao worker: consumo dos jobs e
// publicacao dos eventos de resultado.
package messaging

import (
	"fmt"

	amqp "github.com/rabbitmq/amqp091-go"
)

// Nomes da topologia. Ficam centralizados aqui para que produtor (API) e
// consumidor (worker) nao divirjam.
const (
	ExchangeEvents = "fiapx.events"
	ExchangeDLX    = "fiapx.dlx"

	QueueProcessing = "video.processing"
	QueueDLQ        = "video.processing.dlq"
	QueueStatus     = "video.status"

	RoutingKeyUploaded  = "video.uploaded"
	RoutingKeyCompleted = "video.completed"
	RoutingKeyFailed    = "video.failed"

	HeaderAttempt       = "x-attempt"
	HeaderCorrelationID = "x-correlation-id"
)

// declareTopology cria exchanges e filas de forma idempotente.
//
// Tudo e declarado como durable e as mensagens sao persistentes: se o broker
// reiniciar durante um pico, os jobs enfileirados continuam la. Esse e o
// mecanismo que atende ao requisito de "nao perder uma requisicao".
func declareTopology(ch *amqp.Channel) error {
	if err := ch.ExchangeDeclare(ExchangeEvents, amqp.ExchangeTopic, true, false, false, false, nil); err != nil {
		return fmt.Errorf("declarar exchange %s: %w", ExchangeEvents, err)
	}
	if err := ch.ExchangeDeclare(ExchangeDLX, amqp.ExchangeTopic, true, false, false, false, nil); err != nil {
		return fmt.Errorf("declarar exchange %s: %w", ExchangeDLX, err)
	}

	// Fila morta: recebe o que falhou em definitivo, para inspecao manual.
	if _, err := ch.QueueDeclare(QueueDLQ, true, false, false, false, nil); err != nil {
		return fmt.Errorf("declarar fila %s: %w", QueueDLQ, err)
	}
	if err := ch.QueueBind(QueueDLQ, "#", ExchangeDLX, false, nil); err != nil {
		return fmt.Errorf("bind da DLQ: %w", err)
	}

	// Fila de trabalho, apontando para a DLX quando a mensagem e rejeitada.
	if _, err := ch.QueueDeclare(QueueProcessing, true, false, false, false, amqp.Table{
		"x-dead-letter-exchange": ExchangeDLX,
	}); err != nil {
		return fmt.Errorf("declarar fila %s: %w", QueueProcessing, err)
	}
	if err := ch.QueueBind(QueueProcessing, RoutingKeyUploaded, ExchangeEvents, false, nil); err != nil {
		return fmt.Errorf("bind da fila de processamento: %w", err)
	}

	// Fila de status, consumida pela API para atualizar o banco e notificar o usuario.
	if _, err := ch.QueueDeclare(QueueStatus, true, false, false, false, nil); err != nil {
		return fmt.Errorf("declarar fila %s: %w", QueueStatus, err)
	}
	for _, key := range []string{RoutingKeyCompleted, RoutingKeyFailed} {
		if err := ch.QueueBind(QueueStatus, key, ExchangeEvents, false, nil); err != nil {
			return fmt.Errorf("bind %s: %w", key, err)
		}
	}

	return nil
}
