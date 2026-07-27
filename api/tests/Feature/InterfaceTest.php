<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class InterfaceTest extends TestCase
{
    public function test_a_raiz_serve_a_interface(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('FIAP X', escape: false)
            ->assertSee('Processamento de vídeos', escape: false);
    }

    public function test_interface_e_publica(): void
    {
        // A tela de login precisa abrir sem token; a protecao esta na API.
        $this->get('/')->assertOk();
    }

    public function test_interface_carrega_os_assets_versionados(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('assets/app.css', $html);
        $this->assertStringContainsString('assets/app.js', $html);
    }

    public function test_arquivos_de_interface_existem_no_public(): void
    {
        // Um caminho errado no Blade so apareceria como tela sem estilo em
        // producao; aqui falha no CI.
        $this->assertFileExists(public_path('assets/app.css'));
        $this->assertFileExists(public_path('assets/app.js'));
    }
}
