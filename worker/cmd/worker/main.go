// Command worker consome jobs de processamento de video da fila e devolve
// um ZIP com os frames extraidos.
//
// E a evolucao do projeto base (legacy/main.go): a mesma extracao com ffmpeg,
// agora assincrona, escalavel horizontalmente e resiliente a picos.
package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/fiapx/video-worker/internal/adapter/messaging"
	"github.com/fiapx/video-worker/internal/adapter/storage"
	"github.com/fiapx/video-worker/internal/adapter/video"
	"github.com/fiapx/video-worker/internal/config"
	"github.com/fiapx/video-worker/internal/domain"
	"github.com/fiapx/video-worker/internal/observability"
	"github.com/fiapx/video-worker/internal/usecase"
)

func main() {
	cfg, err := config.Load()
	logger := observability.NewLogger("fiapx-worker", cfg.LogLevel)
	if err != nil {
		logger.Error("configuracao invalida", map[string]any{"error": err.Error()})
		os.Exit(1)
	}

	// Contexto cancelado por SIGTERM: o Kubernetes usa esse sinal para drenar o pod.
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	metrics := observability.NewMetrics()
	metricsSrv := metrics.Server(cfg.MetricsAddr)
	go observability.ListenAndServe(metricsSrv, logger)

	objectStorage, err := storage.NewMinioStorage(ctx, storage.Config{
		Endpoint:  cfg.StorageEndpoint,
		AccessKey: cfg.StorageAccessKey,
		SecretKey: cfg.StorageSecretKey,
		Bucket:    cfg.StorageBucket,
		UseSSL:    cfg.StorageUseSSL,
		Region:    cfg.StorageRegion,
	})
	if err != nil {
		logger.Error("falha ao inicializar storage", map[string]any{"error": err.Error()})
		os.Exit(1)
	}

	publisher, err := messaging.NewPublisher(cfg.RabbitMQURL)
	if err != nil {
		logger.Error("falha ao inicializar publisher", map[string]any{"error": err.Error()})
		os.Exit(1)
	}
	defer publisher.Close()

	processor := usecase.New(usecase.Deps{
		Storage:   objectStorage,
		Extractor: video.NewFFmpegExtractor(cfg.FFmpegBinary),
		Archiver:  video.NewZipArchiver(),
		Publisher: publisher,
		Logger:    logger,
		WorkDir:   cfg.WorkDir,
		MaxRetry:  cfg.MaxRetry,
	})

	consumer, err := messaging.NewConsumer(messaging.ConsumerConfig{
		URL:      cfg.RabbitMQURL,
		Prefetch: cfg.Prefetch,
		Logger:   logger,
	})
	if err != nil {
		logger.Error("falha ao inicializar consumidor", map[string]any{"error": err.Error()})
		os.Exit(1)
	}
	defer consumer.Close()

	logger.Info("worker pronto", map[string]any{
		"prefetch":  cfg.Prefetch,
		"bucket":    cfg.StorageBucket,
		"max_retry": cfg.MaxRetry,
	})

	handler := instrument(processor, metrics)
	if err := consumer.Consume(ctx, handler); err != nil {
		logger.Error("consumo encerrado com erro", map[string]any{"error": err.Error()})
	}

	logger.Info("encerrando worker", nil)
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = observability.Shutdown(shutdownCtx, metricsSrv)
}

// instrument envolve o caso de uso com as metricas do Prometheus, mantendo
// a camada de negocio livre de dependencia de observabilidade.
func instrument(p *usecase.ProcessVideo, m *observability.Metrics) messaging.Handler {
	return func(ctx context.Context, job domain.Job) error {
		m.InFlight.Inc()
		defer m.InFlight.Dec()

		started := time.Now()
		resultado, err := p.Execute(ctx, job)
		m.Duration.Observe(time.Since(started).Seconds())

		switch {
		case err == nil:
			m.Processed.WithLabelValues("sucesso").Inc()
			if resultado != nil {
				m.FramesZip.Observe(float64(resultado.FrameCount))
			}
		case domain.IsPermanent(err):
			m.Processed.WithLabelValues("falha_permanente").Inc()
		default:
			m.Processed.WithLabelValues("falha_transitoria").Inc()
		}
		return err
	}
}
