package video

import (
	"archive/zip"
	"fmt"
	"io"
	"os"
	"path/filepath"
)

// ZipArchiver compacta arquivos em um pacote ZIP com compressao Deflate.
type ZipArchiver struct{}

// NewZipArchiver cria o compactador.
func NewZipArchiver() *ZipArchiver { return &ZipArchiver{} }

// Archive escreve todos os arquivos informados em destPath.
func (a *ZipArchiver) Archive(files []string, destPath string) error {
	out, err := os.Create(destPath)
	if err != nil {
		return fmt.Errorf("criar zip: %w", err)
	}
	defer out.Close()

	writer := zip.NewWriter(out)
	for _, file := range files {
		if err := addFile(writer, file); err != nil {
			writer.Close()
			return err
		}
	}

	// Close explicito: o defer nao permitiria detectar falha ao gravar o indice central.
	if err := writer.Close(); err != nil {
		return fmt.Errorf("finalizar zip: %w", err)
	}
	return nil
}

func addFile(writer *zip.Writer, filename string) error {
	file, err := os.Open(filename)
	if err != nil {
		return fmt.Errorf("abrir %s: %w", filename, err)
	}
	defer file.Close()

	info, err := file.Stat()
	if err != nil {
		return fmt.Errorf("stat %s: %w", filename, err)
	}

	header, err := zip.FileInfoHeader(info)
	if err != nil {
		return fmt.Errorf("header %s: %w", filename, err)
	}
	header.Name = filepath.Base(filename)
	header.Method = zip.Deflate

	entry, err := writer.CreateHeader(header)
	if err != nil {
		return fmt.Errorf("entrada %s: %w", filename, err)
	}

	if _, err := io.Copy(entry, file); err != nil {
		return fmt.Errorf("copiar %s: %w", filename, err)
	}
	return nil
}
