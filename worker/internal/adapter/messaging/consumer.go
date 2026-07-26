package messaging

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sync"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"

	"github.com/fiapx/video-worker/internal/domain"
)

// Handler processa um job. Devolver erro nao-permanente reenfileira a mensagem.
type Handler func(ctx context.Context, job domain.Job) error

// Consumer le jobs da fila de processamento com ack manual.
type Consumer struct {
	url      string
	prefetch int
	logger   domain.Logger

	conn *amqp.Connection
	ch   *amqp.Channel
	mu   sync.Mutex
}

// ConsumerConfig parametriza o consumidor.
type ConsumerConfig struct {
	URL string
	// Prefetch e quantos jobs uma replica processa em paralelo.
	// Combinado com o numero de replicas, define a concorrencia total do sistema.
	Prefetch int
	Logger   domain.Logger
}

// NewConsumer conecta no broker e declara a topologia.
func NewConsumer(cfg ConsumerConfig) (*Consumer, error) {
	if cfg.Prefetch <= 0 {
		cfg.Prefetch = 2
	}
	c := &Consumer{url: cfg.URL, prefetch: cfg.Prefetch, logger: cfg.Logger}
	if err := c.connect(); err != nil {
		return nil, err
	}
	return c, nil
}

func (c *Consumer) connect() error {
	conn, err := amqp.Dial(c.url)
	if err != nil {
		return fmt.Errorf("conectar no rabbitmq: %w", err)
	}

	ch, err := conn.Channel()
	if err != nil {
		conn.Close()
		return fmt.Errorf("abrir canal: %w", err)
	}

	if err := declareTopology(ch); err != nil {
		ch.Close()
		conn.Close()
		return err
	}

	// QoS limita quantas mensagens nao confirmadas o broker entrega a esta replica.
	// Sem isso o RabbitMQ despacharia a fila inteira para o primeiro consumidor.
	if err := ch.Qos(c.prefetch, 0, false); err != nil {
		ch.Close()
		conn.Close()
		return fmt.Errorf("configurar qos: %w", err)
	}

	c.mu.Lock()
	c.conn, c.ch = conn, ch
	c.mu.Unlock()
	return nil
}

// Consume roda o laco de consumo ate o contexto ser cancelado.
// Em caso de queda do broker, reconecta com espera fixa.
func (c *Consumer) Consume(ctx context.Context, handler Handler) error {
	for {
		if err := c.consumeOnce(ctx, handler); err != nil {
			if ctx.Err() != nil {
				return nil
			}
			c.logger.Error("conexao com o broker perdida, reconectando", map[string]any{"error": err.Error()})

			select {
			case <-ctx.Done():
				return nil
			case <-time.After(5 * time.Second):
			}

			if err := c.connect(); err != nil {
				c.logger.Error("falha ao reconectar", map[string]any{"error": err.Error()})
			}
			continue
		}
		return nil
	}
}

func (c *Consumer) consumeOnce(ctx context.Context, handler Handler) error {
	c.mu.Lock()
	ch := c.ch
	c.mu.Unlock()

	if ch == nil {
		return errors.New("canal indisponivel")
	}

	// autoAck=false: a mensagem so sai da fila depois do processamento terminar.
	// Se o pod morrer no meio do ffmpeg, o job volta para a fila.
	deliveries, err := ch.Consume(QueueProcessing, "", false, false, false, false, nil)
	if err != nil {
		return fmt.Errorf("registrar consumidor: %w", err)
	}

	closed := ch.NotifyClose(make(chan *amqp.Error, 1))

	// Semaforo com a mesma capacidade do prefetch: processa em paralelo
	// respeitando exatamente o limite negociado com o broker.
	sem := make(chan struct{}, c.prefetch)
	var wg sync.WaitGroup

	for {
		select {
		case <-ctx.Done():
			// Encerramento gracioso: aguarda os jobs em voo antes de sair.
			wg.Wait()
			return nil

		case amqpErr := <-closed:
			wg.Wait()
			if amqpErr != nil {
				return fmt.Errorf("canal fechado: %s", amqpErr.Reason)
			}
			return errors.New("canal fechado")

		case delivery, ok := <-deliveries:
			if !ok {
				wg.Wait()
				return errors.New("fluxo de entregas encerrado")
			}

			sem <- struct{}{}
			wg.Add(1)
			go func(d amqp.Delivery) {
				defer wg.Done()
				defer func() { <-sem }()
				c.handleDelivery(ctx, d, handler)
			}(delivery)
		}
	}
}

func (c *Consumer) handleDelivery(ctx context.Context, d amqp.Delivery, handler Handler) {
	var job domain.Job
	if err := json.Unmarshal(d.Body, &job); err != nil {
		// Payload ilegivel: descartar direto para a DLQ, sem reenfileirar.
		c.logger.Error("mensagem ilegivel descartada", map[string]any{"error": err.Error()})
		_ = d.Nack(false, false)
		return
	}

	job.Attempt = attemptFromHeaders(d.Headers)
	if job.CorrelationID == "" {
		job.CorrelationID = correlationFromHeaders(d.Headers, d.CorrelationId)
	}

	err := handler(ctx, job)
	switch {
	case err == nil:
		if ackErr := d.Ack(false); ackErr != nil {
			c.logger.Error("falha ao confirmar mensagem", map[string]any{
				"video_id": job.VideoID,
				"error":    ackErr.Error(),
			})
		}

	case domain.IsPermanent(err):
		c.logger.Error("falha permanente, enviando para a DLQ", map[string]any{
			"video_id": job.VideoID,
			"error":    err.Error(),
		})
		_ = d.Nack(false, false)

	default:
		// Erro transitorio (storage fora do ar, broker instavel): republica
		// com o contador incrementado para nao retentar indefinidamente.
		c.logger.Error("falha transitoria, reenfileirando", map[string]any{
			"video_id": job.VideoID,
			"attempt":  job.Attempt,
			"error":    err.Error(),
		})
		if reErr := c.requeue(ctx, d, job); reErr != nil {
			c.logger.Error("falha ao reenfileirar, devolvendo a fila", map[string]any{"error": reErr.Error()})
			_ = d.Nack(false, true)
			return
		}
		_ = d.Ack(false)
	}
}

// requeue republica a mensagem com x-attempt incrementado e um atraso simples.
func (c *Consumer) requeue(ctx context.Context, d amqp.Delivery, job domain.Job) error {
	c.mu.Lock()
	ch := c.ch
	c.mu.Unlock()
	if ch == nil {
		return errors.New("canal indisponivel")
	}

	headers := amqp.Table{}
	for k, v := range d.Headers {
		headers[k] = v
	}
	headers[HeaderAttempt] = int32(job.Attempt + 1)

	return ch.PublishWithContext(ctx, ExchangeEvents, RoutingKeyUploaded, false, false, amqp.Publishing{
		ContentType:   "application/json",
		DeliveryMode:  amqp.Persistent,
		Body:          d.Body,
		Headers:       headers,
		CorrelationId: job.CorrelationID,
		Timestamp:     time.Now().UTC(),
	})
}

// Close libera canal e conexao.
func (c *Consumer) Close() {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.ch != nil {
		_ = c.ch.Close()
	}
	if c.conn != nil {
		_ = c.conn.Close()
	}
}

func attemptFromHeaders(h amqp.Table) int {
	if h == nil {
		return 0
	}
	switch v := h[HeaderAttempt].(type) {
	case int32:
		return int(v)
	case int64:
		return int(v)
	case int:
		return v
	case float64:
		return int(v)
	default:
		return 0
	}
}

func correlationFromHeaders(h amqp.Table, fallback string) string {
	if h != nil {
		if v, ok := h[HeaderCorrelationID].(string); ok && v != "" {
			return v
		}
	}
	return fallback
}
