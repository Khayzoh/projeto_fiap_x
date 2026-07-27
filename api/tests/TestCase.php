<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    /**
     * Fixa a configuração da suíte independentemente do ambiente.
     *
     * As variáveis declaradas no phpunit.xml não bastam quando os testes rodam
     * dentro do container: ali o env_file já injeta cache, storage e e-mail
     * reais no processo, e variável de ambiente real vence o arquivo. Sem isto,
     * a mesma suíte passa na máquina e falha em `docker compose exec` —
     * exatamente o comando que o README recomenda.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'mail.default' => 'array',
            'fiapx.storage.disk' => 'local',
            'fiapx.storage.public_disk' => 'local',
        ]);

        // O limitador de taxa do login guarda a contagem no cache. Com o driver
        // array, o estado sobrevive de um teste para o outro dentro do mesmo
        // processo, e os testes de autenticação passariam a receber 429
        // dependendo da ordem em que rodam.
        Cache::flush();
    }
}
