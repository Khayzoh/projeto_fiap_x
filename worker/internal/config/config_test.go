package config_test

import (
	"testing"

	"github.com/fiapx/video-worker/internal/config"
)

func TestLoadExigeCredenciaisDeStorage(t *testing.T) {
	// Sem as credenciais o worker subiria e so falharia no primeiro job:
	// melhor falhar imediatamente no boot do pod.
	t.Setenv("STORAGE_ACCESS_KEY", "")
	t.Setenv("STORAGE_SECRET_KEY", "")

	if _, err := config.Load(); err == nil {
		t.Fatal("esperava erro quando as credenciais de storage estao ausentes")
	}
}

func TestLoadAplicaPadroes(t *testing.T) {
	t.Setenv("STORAGE_ACCESS_KEY", "chave")
	t.Setenv("STORAGE_SECRET_KEY", "segredo")

	cfg, err := config.Load()
	if err != nil {
		t.Fatalf("nao esperava erro: %v", err)
	}

	if cfg.Prefetch != 2 {
		t.Errorf("prefetch padrao deveria ser 2, veio %d", cfg.Prefetch)
	}
	if cfg.MaxRetry != 3 {
		t.Errorf("max retry padrao deveria ser 3, veio %d", cfg.MaxRetry)
	}
	if cfg.StorageBucket != "fiapx-videos" {
		t.Errorf("bucket padrao inesperado: %s", cfg.StorageBucket)
	}
	if cfg.FFmpegBinary != "ffmpeg" {
		t.Errorf("binario padrao inesperado: %s", cfg.FFmpegBinary)
	}
	if cfg.StorageUseSSL {
		t.Error("SSL deveria vir desligado por padrao no ambiente local")
	}
}

func TestLoadSobrescrevePorAmbiente(t *testing.T) {
	t.Setenv("STORAGE_ACCESS_KEY", "chave")
	t.Setenv("STORAGE_SECRET_KEY", "segredo")
	t.Setenv("WORKER_PREFETCH", "8")
	t.Setenv("WORKER_MAX_RETRY", "5")
	t.Setenv("STORAGE_BUCKET", "outro-bucket")
	t.Setenv("STORAGE_USE_SSL", "true")
	t.Setenv("LOG_LEVEL", "debug")

	cfg, err := config.Load()
	if err != nil {
		t.Fatalf("nao esperava erro: %v", err)
	}

	if cfg.Prefetch != 8 {
		t.Errorf("esperava prefetch 8, veio %d", cfg.Prefetch)
	}
	if cfg.MaxRetry != 5 {
		t.Errorf("esperava max retry 5, veio %d", cfg.MaxRetry)
	}
	if cfg.StorageBucket != "outro-bucket" {
		t.Errorf("esperava bucket sobrescrito, veio %s", cfg.StorageBucket)
	}
	if !cfg.StorageUseSSL {
		t.Error("esperava SSL habilitado")
	}
	if cfg.LogLevel != "debug" {
		t.Errorf("esperava log level debug, veio %s", cfg.LogLevel)
	}
}

func TestLoadIgnoraValoresNumericosInvalidos(t *testing.T) {
	t.Setenv("STORAGE_ACCESS_KEY", "chave")
	t.Setenv("STORAGE_SECRET_KEY", "segredo")
	t.Setenv("WORKER_PREFETCH", "muitos")

	cfg, err := config.Load()
	if err != nil {
		t.Fatalf("nao esperava erro: %v", err)
	}
	if cfg.Prefetch != 2 {
		t.Errorf("valor invalido deveria cair no padrao 2, veio %d", cfg.Prefetch)
	}
}
