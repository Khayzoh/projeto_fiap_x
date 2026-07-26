<x-mail::message>
# Falha no processamento do seu vídeo

Olá,

Não conseguimos processar o vídeo **{{ $nomeArquivo }}**.

<x-mail::panel>
**Motivo:** {{ $motivo }}

**Identificador:** {{ $videoId }}

**Tentativas realizadas:** {{ $tentativas }}
@if($ocorridoEm)

**Ocorrido em:** {{ $ocorridoEm }}
@endif
</x-mail::panel>

Você pode enviar o arquivo novamente. Se o problema continuar, verifique se o vídeo
não está corrompido e se o formato é um dos suportados (mp4, avi, mov, mkv, wmv, flv, webm).

<x-mail::button :url="config('app.url')">
Acessar meus vídeos
</x-mail::button>

Obrigado,<br>
{{ config('app.name') }}
</x-mail::message>
