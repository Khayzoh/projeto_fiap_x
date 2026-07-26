package observability

import (
	"context"
	"errors"
	"net/http"
	"time"

	"github.com/prometheus/client_golang/prometheus"
	"github.com/prometheus/client_golang/prometheus/promhttp"
)

// Metrics expoe os contadores do worker para o Prometheus.
type Metrics struct {
	Processed *prometheus.CounterVec
	Duration  prometheus.Histogram
	InFlight  prometheus.Gauge
	FramesZip prometheus.Histogram
	registry  *prometheus.Registry
}

// NewMetrics registra as metricas em um registry proprio.
func NewMetrics() *Metrics {
	registry := prometheus.NewRegistry()

	m := &Metrics{
		Processed: prometheus.NewCounterVec(prometheus.CounterOpts{
			Name: "fiapx_videos_processados_total",
			Help: "Total de videos processados por resultado.",
		}, []string{"resultado"}),

		Duration: prometheus.NewHistogram(prometheus.HistogramOpts{
			Name:    "fiapx_processamento_duracao_segundos",
			Help:    "Tempo de processamento de um video, do download ao upload do ZIP.",
			Buckets: []float64{1, 5, 10, 30, 60, 120, 300, 600},
		}),

		InFlight: prometheus.NewGauge(prometheus.GaugeOpts{
			Name: "fiapx_videos_em_processamento",
			Help: "Quantidade de videos sendo processados neste instante.",
		}),

		FramesZip: prometheus.NewHistogram(prometheus.HistogramOpts{
			Name:    "fiapx_frames_por_video",
			Help:    "Quantidade de frames extraidos por video.",
			Buckets: []float64{10, 50, 100, 500, 1000, 5000},
		}),

		registry: registry,
	}

	registry.MustRegister(m.Processed, m.Duration, m.InFlight, m.FramesZip)
	return m
}

// Server monta o endpoint /metrics e o /health do worker.
//
// O worker nao atende trafego de negocio, mas precisa de um servidor HTTP para
// que o Prometheus colete metricas e o Kubernetes execute os probes.
func (m *Metrics) Server(addr string) *http.Server {
	mux := http.NewServeMux()
	mux.Handle("/metrics", promhttp.HandlerFor(m.registry, promhttp.HandlerOpts{}))
	mux.HandleFunc("/health", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"status":"ok"}`))
	})

	return &http.Server{
		Addr:              addr,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
	}
}

// ListenAndServe sobe o servidor de metricas ignorando o fechamento gracioso.
func ListenAndServe(srv *http.Server, logger *Logger) {
	if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		logger.Error("servidor de metricas encerrado com erro", map[string]any{"error": err.Error()})
	}
}

// Shutdown encerra o servidor de metricas respeitando o timeout.
func Shutdown(ctx context.Context, srv *http.Server) error {
	return srv.Shutdown(ctx)
}
