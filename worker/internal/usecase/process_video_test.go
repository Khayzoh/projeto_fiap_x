package usecase_test

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"testing"

	"github.com/fiapx/video-worker/internal/domain"
	"github.com/fiapx/video-worker/internal/usecase"
)

// ---- dublês das portas do domínio ----

type fakeStorage struct {
	downloadErr error
	uploadErr   error
	uploaded    string
	downloaded  string
	size        int64
}

func (f *fakeStorage) Download(_ context.Context, objectKey, destPath string) error {
	if f.downloadErr != nil {
		return f.downloadErr
	}
	f.downloaded = objectKey
	return os.WriteFile(destPath, []byte("conteudo-de-video"), 0o644)
}

func (f *fakeStorage) Upload(_ context.Context, _, objectKey, _ string) (int64, error) {
	if f.uploadErr != nil {
		return 0, f.uploadErr
	}
	f.uploaded = objectKey
	if f.size == 0 {
		f.size = 2048
	}
	return f.size, nil
}

type fakeExtractor struct {
	frames  []string
	err     error
	fpsUsed int
}

func (f *fakeExtractor) Extract(_ context.Context, _, outputDir string, fps int) ([]string, error) {
	f.fpsUsed = fps
	if f.err != nil {
		return nil, f.err
	}
	criados := make([]string, 0, len(f.frames))
	for _, nome := range f.frames {
		caminho := filepath.Join(outputDir, nome)
		if err := os.WriteFile(caminho, []byte("frame"), 0o644); err != nil {
			return nil, err
		}
		criados = append(criados, caminho)
	}
	return criados, nil
}

type fakeArchiver struct {
	err        error
	arquivados int
}

func (f *fakeArchiver) Archive(files []string, destPath string) error {
	if f.err != nil {
		return f.err
	}
	f.arquivados = len(files)
	return os.WriteFile(destPath, []byte("zip"), 0o644)
}

type fakePublisher struct {
	completed []domain.Result
	failed    []domain.Failure
	err       error
}

func (f *fakePublisher) PublishCompleted(_ context.Context, r domain.Result, _ string) error {
	if f.err != nil {
		return f.err
	}
	f.completed = append(f.completed, r)
	return nil
}

func (f *fakePublisher) PublishFailed(_ context.Context, fail domain.Failure, _ string) error {
	f.failed = append(f.failed, fail)
	return nil
}

type silentLogger struct{}

func (silentLogger) Info(string, map[string]any)  {}
func (silentLogger) Error(string, map[string]any) {}

// ---- helpers ----

type cenario struct {
	storage   *fakeStorage
	extractor *fakeExtractor
	archiver  *fakeArchiver
	publisher *fakePublisher
	proc      *usecase.ProcessVideo
	workDir   string
}

func montar(t *testing.T, ajustes ...func(*cenario)) *cenario {
	t.Helper()

	c := &cenario{
		storage:   &fakeStorage{},
		extractor: &fakeExtractor{frames: []string{"frame_0001.png", "frame_0002.png"}},
		archiver:  &fakeArchiver{},
		publisher: &fakePublisher{},
		workDir:   t.TempDir(),
	}
	for _, ajuste := range ajustes {
		ajuste(c)
	}

	c.proc = usecase.New(usecase.Deps{
		Storage:   c.storage,
		Extractor: c.extractor,
		Archiver:  c.archiver,
		Publisher: c.publisher,
		Logger:    silentLogger{},
		WorkDir:   c.workDir,
		MaxRetry:  3,
	})
	return c
}

func jobValido() domain.Job {
	return domain.Job{
		VideoID:       "video-123",
		UserID:        42,
		ObjectKey:     "videos/video-123/original.mp4",
		Filename:      "ferias.mp4",
		CorrelationID: "corr-1",
	}
}

// ---- testes ----

func TestExecuteFluxoFeliz(t *testing.T) {
	c := montar(t)

	if _, err := c.proc.Execute(context.Background(), jobValido()); err != nil {
		t.Fatalf("nao esperava erro: %v", err)
	}

	if len(c.publisher.completed) != 1 {
		t.Fatalf("esperava 1 evento de conclusao, veio %d", len(c.publisher.completed))
	}

	resultado := c.publisher.completed[0]
	if resultado.FrameCount != 2 {
		t.Errorf("esperava 2 frames, veio %d", resultado.FrameCount)
	}
	if resultado.ZipObjectKey != "outputs/video-123/frames.zip" {
		t.Errorf("chave do zip inesperada: %s", resultado.ZipObjectKey)
	}
	if resultado.ZipSizeBytes != 2048 {
		t.Errorf("esperava tamanho 2048, veio %d", resultado.ZipSizeBytes)
	}
	if c.extractor.fpsUsed != domain.DefaultFPS {
		t.Errorf("esperava fps padrao %d, veio %d", domain.DefaultFPS, c.extractor.fpsUsed)
	}
	if len(c.publisher.failed) != 0 {
		t.Error("nao deveria publicar falha em fluxo bem-sucedido")
	}
}

func TestExecuteLimpaDiretorioDeTrabalho(t *testing.T) {
	c := montar(t)

	if _, err := c.proc.Execute(context.Background(), jobValido()); err != nil {
		t.Fatalf("nao esperava erro: %v", err)
	}

	// Sem essa limpeza o disco do pod encheria depois de alguns videos.
	if _, err := os.Stat(filepath.Join(c.workDir, "video-123")); !os.IsNotExist(err) {
		t.Fatal("o diretorio temporario do job deveria ter sido removido")
	}
}

func TestExecuteJobInvalidoEhPermanente(t *testing.T) {
	c := montar(t)

	_, err := c.proc.Execute(context.Background(), domain.Job{VideoID: "", UserID: 0})

	if !domain.IsPermanent(err) {
		t.Fatalf("job invalido deveria ser falha permanente, veio %v", err)
	}
	if len(c.publisher.failed) != 1 {
		t.Fatalf("esperava notificacao de falha, veio %d", len(c.publisher.failed))
	}
}

func TestExecuteSemFramesEhPermanente(t *testing.T) {
	c := montar(t, func(c *cenario) { c.extractor.frames = nil })

	_, err := c.proc.Execute(context.Background(), jobValido())

	if !domain.IsPermanent(err) {
		t.Fatalf("video sem frames deveria ser falha permanente, veio %v", err)
	}
	if !errors.Is(err, domain.ErrNoFramesFound) {
		t.Errorf("esperava ErrNoFramesFound, veio %v", err)
	}
	if len(c.publisher.failed) != 1 {
		t.Fatalf("esperava notificacao de falha, veio %d", len(c.publisher.failed))
	}
}

func TestExecuteFalhaDeExtracaoRetentaAntesDeDesistir(t *testing.T) {
	c := montar(t, func(c *cenario) { c.extractor.err = errors.New("ffmpeg exit 1") })

	job := jobValido()
	job.Attempt = 0

	_, err := c.proc.Execute(context.Background(), job)

	if err == nil {
		t.Fatal("esperava erro")
	}
	if domain.IsPermanent(err) {
		t.Fatal("na primeira tentativa a falha deve ser transitoria para permitir retry")
	}
	if len(c.publisher.failed) != 0 {
		t.Error("nao deve notificar o usuario enquanto ainda ha tentativas disponiveis")
	}
}

func TestExecuteFalhaDeExtracaoNaUltimaTentativaNotifica(t *testing.T) {
	c := montar(t, func(c *cenario) { c.extractor.err = errors.New("ffmpeg exit 1") })

	job := jobValido()
	job.Attempt = 2 // MaxRetry = 3, entao esta e a ultima chance

	_, err := c.proc.Execute(context.Background(), job)

	if !domain.IsPermanent(err) {
		t.Fatalf("esgotadas as tentativas, a falha deve ser permanente; veio %v", err)
	}
	if len(c.publisher.failed) != 1 {
		t.Fatalf("esperava notificacao de falha ao usuario, veio %d", len(c.publisher.failed))
	}
	if c.publisher.failed[0].Attempts != 3 {
		t.Errorf("esperava 3 tentativas registradas, veio %d", c.publisher.failed[0].Attempts)
	}
}

func TestExecuteFalhaDeDownloadEhTransitoria(t *testing.T) {
	c := montar(t, func(c *cenario) { c.storage.downloadErr = errors.New("minio indisponivel") })

	_, err := c.proc.Execute(context.Background(), jobValido())

	if err == nil {
		t.Fatal("esperava erro")
	}
	// Storage fora do ar e problema de infraestrutura: a mensagem deve voltar para a fila.
	if domain.IsPermanent(err) {
		t.Fatal("indisponibilidade do storage nao deve descartar o job")
	}
}

func TestExecuteFalhaDeUploadEhTransitoria(t *testing.T) {
	c := montar(t, func(c *cenario) { c.storage.uploadErr = errors.New("timeout no upload") })

	_, err := c.proc.Execute(context.Background(), jobValido())

	if err == nil || domain.IsPermanent(err) {
		t.Fatalf("falha de upload deveria ser transitoria, veio %v", err)
	}
	if len(c.publisher.completed) != 0 {
		t.Error("nao deve anunciar conclusao se o zip nao subiu")
	}
}

func TestExecuteRespeitaFPSCustomizado(t *testing.T) {
	c := montar(t)

	job := jobValido()
	job.FPS = 10

	if _, err := c.proc.Execute(context.Background(), job); err != nil {
		t.Fatalf("nao esperava erro: %v", err)
	}
	if c.extractor.fpsUsed != 10 {
		t.Errorf("esperava fps 10, veio %d", c.extractor.fpsUsed)
	}
}

func TestExecuteNomeDeArquivoComTravessiaDeDiretorio(t *testing.T) {
	c := montar(t)

	job := jobValido()
	// Nome malicioso vindo do upload nao pode escrever fora do diretorio do job.
	job.Filename = "../../../etc/passwd"

	if _, err := c.proc.Execute(context.Background(), job); err != nil {
		t.Fatalf("nao esperava erro: %v", err)
	}
	if c.storage.downloaded != job.ObjectKey {
		t.Errorf("esperava download da chave %s, veio %s", job.ObjectKey, c.storage.downloaded)
	}
}

func TestExecuteDevolveOResultadoNoSucesso(t *testing.T) {
	c := montar(t)

	// O resultado sobe para a camada externa poder instrumentar a contagem de
	// frames sem que o dominio conheca o coletor de metricas.
	resultado, err := c.proc.Execute(context.Background(), jobValido())
	if err != nil {
		t.Fatalf("nao esperava erro: %v", err)
	}
	if resultado == nil {
		t.Fatal("esperava o resultado do processamento, veio nil")
	}
	if resultado.FrameCount != 2 {
		t.Errorf("esperava 2 frames no resultado, veio %d", resultado.FrameCount)
	}
	if resultado.VideoID != "video-123" {
		t.Errorf("resultado veio com o video errado: %s", resultado.VideoID)
	}
}

func TestExecuteNaoDevolveResultadoQuandoFalha(t *testing.T) {
	c := montar(t, func(c *cenario) { c.extractor.frames = nil })

	resultado, err := c.proc.Execute(context.Background(), jobValido())
	if err == nil {
		t.Fatal("esperava erro")
	}
	if resultado != nil {
		t.Fatal("nao deve haver resultado quando o processamento falha")
	}
}
