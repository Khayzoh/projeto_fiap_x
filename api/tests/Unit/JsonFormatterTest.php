<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\JsonFormatter;
use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter as MonologJsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Tests\TestCase;

class JsonFormatterTest extends TestCase
{
    private function logger(TestHandler ...$handlers): Logger
    {
        return new Logger(new Monolog('teste', $handlers));
    }

    public function test_aplica_o_formatador_json_no_handler(): void
    {
        $handler = new TestHandler;
        (new JsonFormatter)($this->logger($handler));

        $this->assertInstanceOf(MonologJsonFormatter::class, $handler->getFormatter());
    }

    public function test_aplica_em_todos_os_handlers_do_canal(): void
    {
        $primeiro = new TestHandler;
        $segundo = new TestHandler;

        (new JsonFormatter)($this->logger($primeiro, $segundo));

        $this->assertInstanceOf(MonologJsonFormatter::class, $primeiro->getFormatter());
        $this->assertInstanceOf(MonologJsonFormatter::class, $segundo->getFormatter());
    }

    public function test_registro_sai_como_json_com_o_contexto(): void
    {
        $handler = new TestHandler;
        (new JsonFormatter)($this->logger($handler));

        $logger = new Monolog('fiapx', [$handler]);
        $logger->info('video recebido', ['correlation_id' => 'abc-123', 'video_id' => 'xyz']);

        $linha = $handler->getRecords()[0]['formatted'];
        $decodificado = json_decode($linha, true);

        $this->assertIsArray($decodificado, 'a linha de log precisa ser JSON valido');
        $this->assertSame('video recebido', $decodificado['message']);
        // O correlation_id e o que permite rastrear a requisicao ate o worker.
        $this->assertSame('abc-123', $decodificado['context']['correlation_id']);
    }

    public function test_cada_registro_ocupa_uma_linha(): void
    {
        $handler = new TestHandler;
        (new JsonFormatter)($this->logger($handler));

        $logger = new Monolog('fiapx', [$handler]);
        $logger->info('primeiro');
        $logger->warning('segundo');

        foreach ($handler->getRecords() as $registro) {
            // Coletor de log (Fluent Bit, docker logs) le uma linha por evento:
            // uma quebra no meio do JSON partiria o registro em dois.
            $this->assertSame(1, substr_count(trim($registro['formatted']), "\n") + 1);
        }
    }
}
