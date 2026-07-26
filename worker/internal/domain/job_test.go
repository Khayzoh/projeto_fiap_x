package domain_test

import (
	"errors"
	"testing"

	"github.com/fiapx/video-worker/internal/domain"
)

func TestJobValidate(t *testing.T) {
	casos := []struct {
		nome    string
		job     domain.Job
		querErr bool
	}{
		{
			nome: "job completo e valido",
			job:  domain.Job{VideoID: "abc", UserID: 1, ObjectKey: "videos/abc/original.mp4"},
		},
		{
			nome:    "sem video id",
			job:     domain.Job{UserID: 1, ObjectKey: "videos/abc/original.mp4"},
			querErr: true,
		},
		{
			nome:    "sem object key",
			job:     domain.Job{VideoID: "abc", UserID: 1},
			querErr: true,
		},
		{
			nome:    "sem usuario",
			job:     domain.Job{VideoID: "abc", ObjectKey: "videos/abc/original.mp4"},
			querErr: true,
		},
	}

	for _, c := range casos {
		t.Run(c.nome, func(t *testing.T) {
			err := c.job.Validate()
			if c.querErr && !errors.Is(err, domain.ErrInvalidJob) {
				t.Fatalf("esperava ErrInvalidJob, veio %v", err)
			}
			if !c.querErr && err != nil {
				t.Fatalf("nao esperava erro, veio %v", err)
			}
		})
	}
}

func TestEffectiveFPS(t *testing.T) {
	casos := map[string]struct {
		fps  int
		quer int
	}{
		"fps informado": {fps: 5, quer: 5},
		"fps zero":      {fps: 0, quer: domain.DefaultFPS},
		"fps negativo":  {fps: -3, quer: domain.DefaultFPS},
	}

	for nome, c := range casos {
		t.Run(nome, func(t *testing.T) {
			job := domain.Job{FPS: c.fps}
			if got := job.EffectiveFPS(); got != c.quer {
				t.Fatalf("esperava %d, veio %d", c.quer, got)
			}
		})
	}
}

func TestPermanentError(t *testing.T) {
	t.Run("erro embrulhado e permanente", func(t *testing.T) {
		err := domain.Permanent(domain.ErrNoFramesFound)
		if !domain.IsPermanent(err) {
			t.Fatal("esperava erro permanente")
		}
		if !errors.Is(err, domain.ErrNoFramesFound) {
			t.Fatal("esperava preservar o erro original via Unwrap")
		}
	})

	t.Run("erro comum nao e permanente", func(t *testing.T) {
		if domain.IsPermanent(errors.New("timeout de rede")) {
			t.Fatal("erro transitorio nao deveria ser classificado como permanente")
		}
	})

	t.Run("permanente sobrevive a novo embrulho", func(t *testing.T) {
		// O caso de uso embrulha erros com fmt.Errorf; a classificacao precisa
		// atravessar essa cadeia, senao a mensagem seria reenfileirada por engano.
		wrapped := errors.Join(domain.Permanent(domain.ErrInvalidJob), errors.New("contexto extra"))
		if !domain.IsPermanent(wrapped) {
			t.Fatal("esperava que IsPermanent atravessasse o embrulho")
		}
	})
}
