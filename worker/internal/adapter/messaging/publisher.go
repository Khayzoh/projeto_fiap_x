package messaging

import (
	"context"
	"encoding/json"
	"fmt"
	"sync"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"

	"github.com/fiapx/video-worker/internal/domain"
)

// Publisher envia os eventos de resultado de volta ao barramento.
type Publisher struct {
	conn *amqp.Connection
	ch   *amqp.Channel
	mu   sync.Mutex
}

// NewPublisher abre uma conexao dedicada a publicacao.
//
// Conexao separada do consumidor de proposito: se o broker aplicar backpressure
// nas publicacoes, o consumo nao fica bloqueado junto.
func NewPublisher(url string) (*Publisher, error) {
	conn, err := amqp.Dial(url)
	if err != nil {
		return nil, fmt.Errorf("conectar publisher: %w", err)
	}

	ch, err := conn.Channel()
	if err != nil {
		conn.Close()
		return nil, fmt.Errorf("abrir canal do publisher: %w", err)
	}

	if err := declareTopology(ch); err != nil {
		ch.Close()
		conn.Close()
		return nil, err
	}

	// Confirmacao do broker: publish() so retorna sucesso apos o RabbitMQ
	// garantir que gravou a mensagem.
	if err := ch.Confirm(false); err != nil {
		ch.Close()
		conn.Close()
		return nil, fmt.Errorf("habilitar confirms: %w", err)
	}

	return &Publisher{conn: conn, ch: ch}, nil
}

// PublishCompleted anuncia um processamento bem-sucedido.
func (p *Publisher) PublishCompleted(ctx context.Context, result domain.Result, correlationID string) error {
	return p.publish(ctx, RoutingKeyCompleted, result, correlationID)
}

// PublishFailed anuncia uma falha definitiva, que a API converte em notificacao ao usuario.
func (p *Publisher) PublishFailed(ctx context.Context, failure domain.Failure, correlationID string) error {
	return p.publish(ctx, RoutingKeyFailed, failure, correlationID)
}

func (p *Publisher) publish(ctx context.Context, routingKey string, payload any, correlationID string) error {
	body, err := json.Marshal(payload)
	if err != nil {
		return fmt.Errorf("serializar evento %s: %w", routingKey, err)
	}

	p.mu.Lock()
	defer p.mu.Unlock()

	confirmation, err := p.ch.PublishWithDeferredConfirmWithContext(ctx,
		ExchangeEvents, routingKey, false, false,
		amqp.Publishing{
			ContentType:   "application/json",
			DeliveryMode:  amqp.Persistent,
			Body:          body,
			CorrelationId: correlationID,
			Timestamp:     time.Now().UTC(),
			Headers:       amqp.Table{HeaderCorrelationID: correlationID},
		})
	if err != nil {
		return fmt.Errorf("publicar %s: %w", routingKey, err)
	}

	ok, err := confirmation.WaitContext(ctx)
	if err != nil {
		return fmt.Errorf("aguardar confirmacao de %s: %w", routingKey, err)
	}
	if !ok {
		return fmt.Errorf("broker rejeitou o evento %s", routingKey)
	}
	return nil
}

// Close encerra canal e conexao do publicador.
func (p *Publisher) Close() {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.ch != nil {
		_ = p.ch.Close()
	}
	if p.conn != nil {
		_ = p.conn.Close()
	}
}
