// Package usecase contem a orquestracao do processamento de video.
// E o equivalente refatorado da funcao processVideo() do projeto base,
// agora sem dependencia direta de disco, ffmpeg ou HTTP.
package usecase

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"time"

	"github.com/fiapx/video-worker/internal/domain"
)

// ProcessVideo executa o fluxo completo: baixar o video, extrair os frames,
// compactar e devolver o ZIP ao storage.
type ProcessVideo struct {
	storage   domain.ObjectStorage
	extractor domain.FrameExtractor
	archiver  domain.Archiver
	publisher domain.EventPublisher
	logger    domain.Logger
	workDir   string
	maxRetry  int
}

// Deps agrupa as dependencias do caso de uso.
type Deps struct {
	Storage   domain.ObjectStorage
	Extractor domain.FrameExtractor
	Archiver  domain.Archiver
	Publisher domain.EventPublisher
	Logger    domain.Logger
	WorkDir   string
	MaxRetry  int
}

// New monta o caso de uso a partir das suas dependencias.
func New(d Deps) *ProcessVideo {
	if d.WorkDir == "" {
		d.WorkDir = os.TempDir()
	}
	if d.MaxRetry <= 0 {
		d.MaxRetry = 3
	}
	return &ProcessVideo{
		storage:   d.Storage,
		extractor: d.Extractor,
		archiver:  d.Archiver,
		publisher: d.Publisher,
		logger:    d.Logger,
		workDir:   d.WorkDir,
		maxRetry:  d.MaxRetry,
	}
}

// Execute processa um job. O erro devolvido informa ao consumidor se a mensagem
// deve ser reenfileirada (erro transitorio) ou descartada para a DLQ (permanente).
func (p *ProcessVideo) Execute(ctx context.Context, job domain.Job) error {
	started := time.Now()

	if err := job.Validate(); err != nil {
		// Job malformado nunca melhora com retentativa.
		p.fail(ctx, job, err, "job invalido")
		return domain.Permanent(err)
	}

	log := map[string]any{
		"video_id":       job.VideoID,
		"user_id":        job.UserID,
		"correlation_id": job.CorrelationID,
		"attempt":        job.Attempt,
	}
	p.logger.Info("processamento iniciado", log)

	// Cada job trabalha em um diretorio proprio, o que permite varios
	// processamentos simultaneos no mesmo pod sem colisao de arquivos.
	jobDir := filepath.Join(p.workDir, job.VideoID)
	framesDir := filepath.Join(jobDir, "frames")
	if err := os.MkdirAll(framesDir, 0o755); err != nil {
		return fmt.Errorf("criar diretorio de trabalho: %w", err)
	}
	defer os.RemoveAll(jobDir)

	videoPath := filepath.Join(jobDir, sanitizeName(job.Filename))
	if err := p.storage.Download(ctx, job.ObjectKey, videoPath); err != nil {
		return fmt.Errorf("baixar video %s: %w", job.ObjectKey, err)
	}

	frames, err := p.extractor.Extract(ctx, videoPath, framesDir, job.EffectiveFPS())
	if err != nil {
		// ffmpeg falhando em um arquivo especifico e problema do arquivo, nao do sistema.
		wrapped := fmt.Errorf("%w: %v", domain.ErrExtractionFailed, err)
		if p.isLastAttempt(job) {
			p.fail(ctx, job, wrapped, "extracao de frames falhou")
			return domain.Permanent(wrapped)
		}
		return wrapped
	}

	if len(frames) == 0 {
		p.fail(ctx, job, domain.ErrNoFramesFound, "video sem frames")
		return domain.Permanent(domain.ErrNoFramesFound)
	}

	zipPath := filepath.Join(jobDir, fmt.Sprintf("frames_%s.zip", job.VideoID))
	if err := p.archiver.Archive(frames, zipPath); err != nil {
		return fmt.Errorf("compactar frames: %w", err)
	}

	zipKey := fmt.Sprintf("outputs/%s/frames.zip", job.VideoID)
	size, err := p.storage.Upload(ctx, zipPath, zipKey, "application/zip")
	if err != nil {
		return fmt.Errorf("enviar zip: %w", err)
	}

	result := domain.Result{
		VideoID:      job.VideoID,
		UserID:       job.UserID,
		ZipObjectKey: zipKey,
		FrameCount:   len(frames),
		ZipSizeBytes: size,
		DurationMS:   time.Since(started).Milliseconds(),
		ProcessedAt:  time.Now().UTC(),
	}

	if err := p.publisher.PublishCompleted(ctx, result, job.CorrelationID); err != nil {
		// O ZIP ja esta no storage; falhar aqui apenas reenfileira o job,
		// e o reprocessamento sobrescreve o mesmo objeto (operacao idempotente).
		return fmt.Errorf("publicar conclusao: %w", err)
	}

	log["frame_count"] = result.FrameCount
	log["duration_ms"] = result.DurationMS
	p.logger.Info("processamento concluido", log)
	return nil
}

// isLastAttempt informa se o job ja esgotou o orcamento de retentativas.
func (p *ProcessVideo) isLastAttempt(job domain.Job) bool {
	return job.Attempt >= p.maxRetry-1
}

// fail registra e notifica a falha definitiva de um job.
func (p *ProcessVideo) fail(ctx context.Context, job domain.Job, cause error, msg string) {
	p.logger.Error(msg, map[string]any{
		"video_id":       job.VideoID,
		"user_id":        job.UserID,
		"correlation_id": job.CorrelationID,
		"error":          cause.Error(),
	})

	failure := domain.Failure{
		VideoID:  job.VideoID,
		UserID:   job.UserID,
		Filename: job.Filename,
		Reason:   cause.Error(),
		Attempts: job.Attempt + 1,
		FailedAt: time.Now().UTC(),
	}

	if err := p.publisher.PublishFailed(ctx, failure, job.CorrelationID); err != nil {
		p.logger.Error("nao foi possivel publicar evento de falha", map[string]any{
			"video_id": job.VideoID,
			"error":    err.Error(),
		})
	}
}

// sanitizeName evita que o nome enviado pelo usuario escape do diretorio de trabalho.
func sanitizeName(name string) string {
	base := filepath.Base(filepath.Clean(name))
	if base == "." || base == string(filepath.Separator) || base == "" {
		return "input.mp4"
	}
	return base
}
