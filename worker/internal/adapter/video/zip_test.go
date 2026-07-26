package video_test

import (
	"archive/zip"
	"io"
	"os"
	"path/filepath"
	"testing"

	"github.com/fiapx/video-worker/internal/adapter/video"
)

func criarFrames(t *testing.T, dir string, nomes ...string) []string {
	t.Helper()
	caminhos := make([]string, 0, len(nomes))
	for i, nome := range nomes {
		caminho := filepath.Join(dir, nome)
		conteudo := make([]byte, 128*(i+1))
		if err := os.WriteFile(caminho, conteudo, 0o644); err != nil {
			t.Fatalf("criar fixture %s: %v", nome, err)
		}
		caminhos = append(caminhos, caminho)
	}
	return caminhos
}

func TestArchiveGeraZipComTodosOsFrames(t *testing.T) {
	dir := t.TempDir()
	frames := criarFrames(t, dir, "frame_0001.png", "frame_0002.png", "frame_0003.png")
	destino := filepath.Join(dir, "frames.zip")

	if err := video.NewZipArchiver().Archive(frames, destino); err != nil {
		t.Fatalf("nao esperava erro: %v", err)
	}

	leitor, err := zip.OpenReader(destino)
	if err != nil {
		t.Fatalf("zip gerado nao pode ser aberto: %v", err)
	}
	defer leitor.Close()

	if len(leitor.File) != 3 {
		t.Fatalf("esperava 3 arquivos no zip, veio %d", len(leitor.File))
	}

	for i, entrada := range leitor.File {
		// O nome dentro do zip deve ser apenas o basename, sem a arvore de diretorios
		// temporarios do worker vazando para o usuario final.
		if filepath.Dir(entrada.Name) != "." {
			t.Errorf("entrada %d guardou caminho completo: %s", i, entrada.Name)
		}
		if entrada.Method != zip.Deflate {
			t.Errorf("entrada %s deveria usar compressao Deflate", entrada.Name)
		}
	}
}

func TestArchiveComListaVazia(t *testing.T) {
	dir := t.TempDir()
	destino := filepath.Join(dir, "vazio.zip")

	if err := video.NewZipArchiver().Archive(nil, destino); err != nil {
		t.Fatalf("zip vazio deveria ser valido: %v", err)
	}

	leitor, err := zip.OpenReader(destino)
	if err != nil {
		t.Fatalf("zip vazio deveria ser legivel: %v", err)
	}
	defer leitor.Close()

	if len(leitor.File) != 0 {
		t.Fatalf("esperava zip sem entradas, veio %d", len(leitor.File))
	}
}

func TestArchiveFalhaComArquivoInexistente(t *testing.T) {
	dir := t.TempDir()
	destino := filepath.Join(dir, "frames.zip")

	err := video.NewZipArchiver().Archive([]string{filepath.Join(dir, "nao-existe.png")}, destino)
	if err == nil {
		t.Fatal("esperava erro ao compactar arquivo inexistente")
	}
}

func TestArchiveFalhaComDestinoInvalido(t *testing.T) {
	dir := t.TempDir()
	frames := criarFrames(t, dir, "frame_0001.png")

	// Diretorio inexistente no caminho do destino.
	err := video.NewZipArchiver().Archive(frames, filepath.Join(dir, "sem", "tal", "pasta.zip"))
	if err == nil {
		t.Fatal("esperava erro ao gravar em caminho inexistente")
	}
}

func TestArchivePreservaConteudo(t *testing.T) {
	dir := t.TempDir()
	origem := filepath.Join(dir, "frame_0001.png")
	conteudo := []byte("conteudo-do-frame-original")
	if err := os.WriteFile(origem, conteudo, 0o644); err != nil {
		t.Fatal(err)
	}

	destino := filepath.Join(dir, "frames.zip")
	if err := video.NewZipArchiver().Archive([]string{origem}, destino); err != nil {
		t.Fatal(err)
	}

	leitor, err := zip.OpenReader(destino)
	if err != nil {
		t.Fatal(err)
	}
	defer leitor.Close()

	arquivo, err := leitor.File[0].Open()
	if err != nil {
		t.Fatal(err)
	}
	defer arquivo.Close()

	lido, err := io.ReadAll(arquivo)
	if err != nil {
		t.Fatal(err)
	}
	if string(lido) != string(conteudo) {
		t.Fatalf("conteudo corrompido: esperava %q, veio %q", conteudo, lido)
	}
}
