// Package observability reune log estruturado e metricas do worker.
package observability

import (
	"log/slog"
	"os"
)

// Logger emite logs em JSON, formato exigido para correlacao de requisicoes
// entre a API e o worker.
type Logger struct {
	inner   *slog.Logger
	service string
}

// NewLogger cria o logger no nivel informado ("debug", "info", "warn", "error").
func NewLogger(service, level string) *Logger {
	var lvl slog.Level
	switch level {
	case "debug":
		lvl = slog.LevelDebug
	case "warn":
		lvl = slog.LevelWarn
	case "error":
		lvl = slog.LevelError
	default:
		lvl = slog.LevelInfo
	}

	handler := slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: lvl})
	return &Logger{
		inner:   slog.New(handler).With("service", service),
		service: service,
	}
}

// Info registra um evento normal de operacao.
func (l *Logger) Info(msg string, fields map[string]any) {
	l.inner.Info(msg, toArgs(fields)...)
}

// Error registra uma falha.
func (l *Logger) Error(msg string, fields map[string]any) {
	l.inner.Error(msg, toArgs(fields)...)
}

func toArgs(fields map[string]any) []any {
	args := make([]any, 0, len(fields)*2)
	for k, v := range fields {
		args = append(args, k, v)
	}
	return args
}
