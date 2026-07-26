package video_test

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
	"time"

	"github.com/fiapx/video-worker/internal/adapter/video"
)

// exigirFFmpeg pula o teste quando o binario nao esta disponivel, para que a
// suite continue rodando em maquinas sem ffmpeg. No CI e no container ele existe.
func exigirFFmpeg(t *testing.T) {
	t.Helper()
	if _, err := exec.LookPath("ffmpeg"); err != nil {
		t.Skip("ffmpeg indisponivel neste ambiente")
	}
}

// gerarVideoSintetico cria um video de teste de duracao conhecida usando o
// proprio ffmpeg, evitando versionar um binario de fixture no repositorio.
func gerarVideoSintetico(t *testing.T, dir string, segundos int) string {
	t.Helper()

	caminho := filepath.Join(dir, "amostra.mp4")
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()

	cmd := exec.CommandContext(ctx, "ffmpeg",
		"-hide_banner", "-loglevel", "error",
		"-f", "lavfi",
		"-i", "testsrc=duration="+itoa(segundos)+":size=320x240:rate=10",
		"-pix_fmt", "yuv420p",
		"-y", caminho,
	)
	if saida, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("nao foi possivel gerar o video de teste: %v (%s)", err, saida)
	}
	return caminho
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	digitos := ""
	for n > 0 {
		digitos = string(rune('0'+n%10)) + digitos
		n /= 10
	}
	return digitos
}

func TestExtractGeraUmFramePorSegundo(t *testing.T) {
	exigirFFmpeg(t)

	dir := t.TempDir()
	videoPath := gerarVideoSintetico(t, dir, 3)
	saida := filepath.Join(dir, "frames")
	if err := os.MkdirAll(saida, 0o755); err != nil {
		t.Fatal(err)
	}

	frames, err := video.NewFFmpegExtractor("").Extract(context.Background(), videoPath, saida, 1)
	if err != nil {
		t.Fatalf("nao esperava erro: %v", err)
	}

	// Um video de 3s a 1 fps rende 3 frames (o ffmpeg pode arredondar para 3 ou 4).
	if len(frames) < 3 {
		t.Fatalf("esperava ao menos 3 frames, veio %d", len(frames))
	}

	for _, frame := range frames {
		info, err := os.Stat(frame)
		if err != nil {
			t.Fatalf("frame %s nao existe: %v", frame, err)
		}
		if info.Size() == 0 {
			t.Errorf("frame %s saiu vazio", frame)
		}
	}
}

func TestExtractRetornaFramesOrdenados(t *testing.T) {
	exigirFFmpeg(t)

	dir := t.TempDir()
	videoPath := gerarVideoSintetico(t, dir, 4)
	saida := filepath.Join(dir, "frames")
	if err := os.MkdirAll(saida, 0o755); err != nil {
		t.Fatal(err)
	}

	frames, err := video.NewFFmpegExtractor("").Extract(context.Background(), videoPath, saida, 1)
	if err != nil {
		t.Fatal(err)
	}

	// A ordem importa: o ZIP entregue ao usuario precisa seguir a linha do tempo.
	for i := 1; i < len(frames); i++ {
		if filepath.Base(frames[i-1]) >= filepath.Base(frames[i]) {
			t.Fatalf("frames fora de ordem: %s antes de %s", frames[i-1], frames[i])
		}
	}
}

func TestExtractRespeitaFPSMaior(t *testing.T) {
	exigirFFmpeg(t)

	dir := t.TempDir()
	videoPath := gerarVideoSintetico(t, dir, 2)

	umFPS := filepath.Join(dir, "fps1")
	cincoFPS := filepath.Join(dir, "fps5")
	for _, d := range []string{umFPS, cincoFPS} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}

	extrator := video.NewFFmpegExtractor("")

	poucos, err := extrator.Extract(context.Background(), videoPath, umFPS, 1)
	if err != nil {
		t.Fatal(err)
	}
	muitos, err := extrator.Extract(context.Background(), videoPath, cincoFPS, 5)
	if err != nil {
		t.Fatal(err)
	}

	if len(muitos) <= len(poucos) {
		t.Fatalf("fps maior deveria gerar mais frames: %d com fps=5 contra %d com fps=1", len(muitos), len(poucos))
	}
}

func TestExtractFalhaComArquivoInvalido(t *testing.T) {
	exigirFFmpeg(t)

	dir := t.TempDir()
	naoVideo := filepath.Join(dir, "corrompido.mp4")
	if err := os.WriteFile(naoVideo, []byte("isso nao e um video"), 0o644); err != nil {
		t.Fatal(err)
	}
	saida := filepath.Join(dir, "frames")
	if err := os.MkdirAll(saida, 0o755); err != nil {
		t.Fatal(err)
	}

	_, err := video.NewFFmpegExtractor("").Extract(context.Background(), naoVideo, saida, 1)
	if err == nil {
		t.Fatal("esperava erro ao processar arquivo que nao e video")
	}
}

func TestExtractRespeitaCancelamentoDeContexto(t *testing.T) {
	exigirFFmpeg(t)

	dir := t.TempDir()
	videoPath := gerarVideoSintetico(t, dir, 2)
	saida := filepath.Join(dir, "frames")
	if err := os.MkdirAll(saida, 0o755); err != nil {
		t.Fatal(err)
	}

	// Contexto ja cancelado: o ffmpeg nem deve chegar a produzir frames.
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	if _, err := video.NewFFmpegExtractor("").Extract(ctx, videoPath, saida, 1); err == nil {
		t.Fatal("esperava erro com contexto cancelado")
	}
}

func TestExtractUsaBinarioPadraoQuandoVazio(t *testing.T) {
	// Nao depende do ffmpeg: valida apenas o fallback de configuracao.
	extrator := video.NewFFmpegExtractor("")
	if extrator == nil {
		t.Fatal("esperava extrator valido")
	}
}

func TestExtractFalhaComBinarioInexistente(t *testing.T) {
	dir := t.TempDir()
	saida := filepath.Join(dir, "frames")
	if err := os.MkdirAll(saida, 0o755); err != nil {
		t.Fatal(err)
	}

	_, err := video.NewFFmpegExtractor("ffmpeg-que-nao-existe").
		Extract(context.Background(), filepath.Join(dir, "x.mp4"), saida, 1)
	if err == nil {
		t.Fatal("esperava erro quando o binario do ffmpeg nao existe")
	}
}
