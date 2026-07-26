<?php

declare(strict_types=1);

namespace App\Providers;

use App\Messaging\RabbitMqConnection;
use App\Messaging\VideoEventPublisher;
use App\Support\Jwt;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Conexao unica por processo: abrir um socket AMQP por requisicao
        // seria caro e esgotaria os file descriptors sob carga.
        $this->app->singleton(RabbitMqConnection::class, fn () => new RabbitMqConnection(
            config('fiapx.rabbitmq')
        ));

        $this->app->singleton(VideoEventPublisher::class, fn ($app) => new VideoEventPublisher(
            $app->make(RabbitMqConnection::class),
            config('fiapx.rabbitmq'),
        ));

        $this->app->singleton(Jwt::class);
    }

    public function boot(): void
    {
        //
    }
}
