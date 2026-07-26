// Package storage adapta o cliente S3/MinIO a porta ObjectStorage.
// Guardar video e ZIP fora do pod e o que permite escalar o worker
// horizontalmente: nenhum estado fica preso ao disco de uma replica.
package storage

import (
	"context"
	"fmt"
	"os"
	"path/filepath"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"
)

// MinioStorage implementa domain.ObjectStorage sobre a API S3.
type MinioStorage struct {
	client *minio.Client
	bucket string
}

// Config reune os parametros de conexao com o storage.
type Config struct {
	Endpoint  string
	AccessKey string
	SecretKey string
	Bucket    string
	UseSSL    bool
	Region    string
}

// NewMinioStorage conecta no storage e garante que o bucket existe.
func NewMinioStorage(ctx context.Context, cfg Config) (*MinioStorage, error) {
	client, err := minio.New(cfg.Endpoint, &minio.Options{
		Creds:  credentials.NewStaticV4(cfg.AccessKey, cfg.SecretKey, ""),
		Secure: cfg.UseSSL,
		Region: cfg.Region,
	})
	if err != nil {
		return nil, fmt.Errorf("conectar no storage: %w", err)
	}

	exists, err := client.BucketExists(ctx, cfg.Bucket)
	if err != nil {
		return nil, fmt.Errorf("verificar bucket %s: %w", cfg.Bucket, err)
	}
	if !exists {
		if err := client.MakeBucket(ctx, cfg.Bucket, minio.MakeBucketOptions{Region: cfg.Region}); err != nil {
			return nil, fmt.Errorf("criar bucket %s: %w", cfg.Bucket, err)
		}
	}

	return &MinioStorage{client: client, bucket: cfg.Bucket}, nil
}

// Download grava o objeto no caminho local informado.
func (s *MinioStorage) Download(ctx context.Context, objectKey, destPath string) error {
	if err := os.MkdirAll(filepath.Dir(destPath), 0o755); err != nil {
		return fmt.Errorf("preparar destino: %w", err)
	}
	if err := s.client.FGetObject(ctx, s.bucket, objectKey, destPath, minio.GetObjectOptions{}); err != nil {
		return fmt.Errorf("baixar %s: %w", objectKey, err)
	}
	return nil
}

// Upload envia o arquivo local e devolve o numero de bytes gravados.
func (s *MinioStorage) Upload(ctx context.Context, srcPath, objectKey, contentType string) (int64, error) {
	info, err := s.client.FPutObject(ctx, s.bucket, objectKey, srcPath, minio.PutObjectOptions{
		ContentType: contentType,
	})
	if err != nil {
		return 0, fmt.Errorf("enviar %s: %w", objectKey, err)
	}
	return info.Size, nil
}
