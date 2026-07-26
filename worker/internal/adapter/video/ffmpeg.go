// Package video adapta o ffmpeg e o empacotamento ZIP as portas do dominio.
// A logica de extracao e a mesma do projeto base, agora isolada, cancelavel
// e com o binario configuravel.
package video

import (
	"context"
	"fmt"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
)

// FFmpegExtractor extrai frames chamando o binario ffmpeg.
type FFmpegExtractor struct {
	binary string
}

// NewFFmpegExtractor cria o extrator. Binario vazio assume "ffmpeg" no PATH.
func NewFFmpegExtractor(binary string) *FFmpegExtractor {
	if binary == "" {
		binary = "ffmpeg"
	}
	return &FFmpegExtractor{binary: binary}
}

// Extract gera um PNG por intervalo definido em fps e devolve os caminhos ordenados.
func (e *FFmpegExtractor) Extract(ctx context.Context, videoPath, outputDir string, fps int) ([]string, error) {
	if fps <= 0 {
		fps = 1
	}
	pattern := filepath.Join(outputDir, "frame_%04d.png")

	// CommandContext garante que o ffmpeg morre junto com o cancelamento do job,
	// evitando processos orfaos quando o pod recebe SIGTERM.
	cmd := exec.CommandContext(ctx, e.binary,
		"-hide_banner",
		"-loglevel", "error",
		"-i", videoPath,
		"-vf", fmt.Sprintf("fps=%d", fps),
		"-y",
		pattern,
	)

	output, err := cmd.CombinedOutput()
	if err != nil {
		return nil, fmt.Errorf("ffmpeg: %w (saida: %s)", err, strings.TrimSpace(string(output)))
	}

	frames, err := filepath.Glob(filepath.Join(outputDir, "*.png"))
	if err != nil {
		return nil, fmt.Errorf("listar frames: %w", err)
	}

	// Glob nao garante ordem; o ZIP deve sair na sequencia temporal do video.
	sort.Strings(frames)
	return frames, nil
}
