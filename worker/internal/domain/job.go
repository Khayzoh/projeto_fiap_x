// Package domain concentra as regras e contratos do processamento de video.
// Nao importa nada de infraestrutura: e a camada mais interna da arquitetura.
package domain

import (
	"errors"
	"time"
)

// Status representa a situacao de um video dentro do fluxo de processamento.
type Status string

const (
	StatusPending    Status = "PENDING"
	StatusProcessing Status = "PROCESSING"
	StatusCompleted  Status = "COMPLETED"
	StatusFailed     Status = "FAILED"
)

// Job e a unidade de trabalho consumida da fila: um video aguardando extracao de frames.
type Job struct {
	VideoID       string `json:"video_id"`
	UserID        int64  `json:"user_id"`
	ObjectKey     string `json:"object_key"`
	Filename      string `json:"filename"`
	FPS           int    `json:"fps"`
	CorrelationID string `json:"correlation_id"`
	Attempt       int    `json:"attempt"`
}

// Result descreve um processamento concluido com sucesso.
type Result struct {
	VideoID      string    `json:"video_id"`
	UserID       int64     `json:"user_id"`
	ZipObjectKey string    `json:"zip_object_key"`
	FrameCount   int       `json:"frame_count"`
	ZipSizeBytes int64     `json:"zip_size_bytes"`
	DurationMS   int64     `json:"duration_ms"`
	ProcessedAt  time.Time `json:"processed_at"`
}

// Failure descreve um processamento que falhou em definitivo.
type Failure struct {
	VideoID  string    `json:"video_id"`
	UserID   int64     `json:"user_id"`
	Filename string    `json:"filename"`
	Reason   string    `json:"reason"`
	Attempts int       `json:"attempts"`
	FailedAt time.Time `json:"failed_at"`
}

// Erros de dominio. Sao classificados entre permanentes e transitorios porque
// essa distincao decide se a mensagem volta para a fila ou vai para a DLQ.
var (
	ErrInvalidJob       = errors.New("mensagem de job invalida")
	ErrNoFramesFound    = errors.New("nenhum frame foi extraido do video")
	ErrVideoNotFound    = errors.New("video nao encontrado no storage")
	ErrExtractionFailed = errors.New("falha ao extrair frames do video")
)

// DefaultFPS e a taxa de amostragem herdada do projeto base: um frame por segundo.
const DefaultFPS = 1

// Validate garante que o job tem o minimo necessario para ser processado.
// Um job invalido nunca deve ser reenfileirado: iria falhar de novo indefinidamente.
func (j Job) Validate() error {
	if j.VideoID == "" || j.ObjectKey == "" || j.UserID == 0 {
		return ErrInvalidJob
	}
	return nil
}

// EffectiveFPS aplica o padrao quando o produtor nao especifica a taxa.
func (j Job) EffectiveFPS() int {
	if j.FPS <= 0 {
		return DefaultFPS
	}
	return j.FPS
}

// PermanentError marca uma falha que nao se resolve com nova tentativa
// (video corrompido, formato invalido, job malformado).
type PermanentError struct {
	Err error
}

func (e *PermanentError) Error() string { return e.Err.Error() }
func (e *PermanentError) Unwrap() error { return e.Err }

// Permanent embrulha um erro sinalizando que retentar nao adianta.
func Permanent(err error) error { return &PermanentError{Err: err} }

// IsPermanent informa se o erro dispensa novas tentativas.
func IsPermanent(err error) bool {
	var pe *PermanentError
	return errors.As(err, &pe)
}
