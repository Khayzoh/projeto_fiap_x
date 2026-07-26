package domain

import "context"

// As portas abaixo sao implementadas na camada de adapters (MinIO, ffmpeg, RabbitMQ).
// O caso de uso depende apenas destas interfaces, o que permite testa-lo com fakes.

// ObjectStorage abstrai o armazenamento de objetos (S3/MinIO).
type ObjectStorage interface {
	// Download traz o objeto para um caminho local e devolve esse caminho.
	Download(ctx context.Context, objectKey, destPath string) error
	// Upload envia um arquivo local e devolve o tamanho em bytes enviado.
	Upload(ctx context.Context, srcPath, objectKey, contentType string) (int64, error)
}

// FrameExtractor extrai frames de um video para um diretorio de saida.
// Devolve os caminhos dos frames gerados, ja ordenados.
type FrameExtractor interface {
	Extract(ctx context.Context, videoPath, outputDir string, fps int) ([]string, error)
}

// Archiver compacta um conjunto de arquivos em um unico pacote.
type Archiver interface {
	Archive(files []string, destPath string) error
}

// EventPublisher publica os eventos de resultado de volta no barramento.
type EventPublisher interface {
	PublishCompleted(ctx context.Context, result Result, correlationID string) error
	PublishFailed(ctx context.Context, failure Failure, correlationID string) error
}

// Logger e o contrato minimo de log estruturado usado pelo caso de uso.
type Logger interface {
	Info(msg string, fields map[string]any)
	Error(msg string, fields map[string]any)
}
