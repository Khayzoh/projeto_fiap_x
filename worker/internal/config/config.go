// Package config le a configuracao do worker a partir de variaveis de ambiente,
// seguindo o principio de config externa ao build (12-factor).
package config

import (
	"fmt"
	"os"
	"strconv"
)

// Config reune todos os parametros de execucao do worker.
type Config struct {
	RabbitMQURL string
	Prefetch    int
	MaxRetry    int

	StorageEndpoint  string
	StorageAccessKey string
	StorageSecretKey string
	StorageBucket    string
	StorageUseSSL    bool
	StorageRegion    string

	FFmpegBinary string
	WorkDir      string
	MetricsAddr  string
	LogLevel     string
}

// Load monta a configuracao e falha cedo se algo obrigatorio estiver ausente.
func Load() (Config, error) {
	cfg := Config{
		RabbitMQURL: env("RABBITMQ_URL", "amqp://fiapx:fiapx@rabbitmq:5672/"),
		Prefetch:    envInt("WORKER_PREFETCH", 2),
		MaxRetry:    envInt("WORKER_MAX_RETRY", 3),

		StorageEndpoint:  env("STORAGE_ENDPOINT", "minio:9000"),
		StorageAccessKey: env("STORAGE_ACCESS_KEY", ""),
		StorageSecretKey: env("STORAGE_SECRET_KEY", ""),
		StorageBucket:    env("STORAGE_BUCKET", "fiapx-videos"),
		StorageUseSSL:    envBool("STORAGE_USE_SSL", false),
		StorageRegion:    env("STORAGE_REGION", "us-east-1"),

		FFmpegBinary: env("FFMPEG_BINARY", "ffmpeg"),
		WorkDir:      env("WORKER_WORKDIR", "/tmp/fiapx"),
		MetricsAddr:  env("METRICS_ADDR", ":9100"),
		LogLevel:     env("LOG_LEVEL", "info"),
	}

	if cfg.StorageAccessKey == "" || cfg.StorageSecretKey == "" {
		return cfg, fmt.Errorf("STORAGE_ACCESS_KEY e STORAGE_SECRET_KEY sao obrigatorias")
	}

	return cfg, nil
}

func env(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func envInt(key string, fallback int) int {
	if v := os.Getenv(key); v != "" {
		if parsed, err := strconv.Atoi(v); err == nil {
			return parsed
		}
	}
	return fallback
}

func envBool(key string, fallback bool) bool {
	if v := os.Getenv(key); v != "" {
		if parsed, err := strconv.ParseBool(v); err == nil {
			return parsed
		}
	}
	return fallback
}
