<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter as MonologJsonFormatter;

/**
 * Aplica formatacao JSON a um canal de log.
 *
 * Usado como "tap" em config/logging.php. Os logs saem em uma linha JSON por
 * evento, no mesmo formato do worker Go (slog), o que permite correlacionar
 * uma requisicao entre os dois servicos pelo campo correlation_id.
 */
class JsonFormatter
{
    /**
     * O tap recebe o Logger do Laravel, que encapsula o do Monolog;
     * os handlers ficam no objeto interno.
     */
    public function __invoke(Logger $logger): void
    {
        $formatter = new MonologJsonFormatter(
            batchMode: MonologJsonFormatter::BATCH_MODE_NEWLINES,
            appendNewline: true,
            ignoreEmptyContextAndExtra: true,
            includeStacktraces: true,
        );

        foreach ($logger->getLogger()->getHandlers() as $handler) {
            $handler->setFormatter($formatter);
        }
    }
}
