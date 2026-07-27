<x-mail::message>
# Seu vídeo está pronto

Olá,

Terminamos de processar o vídeo **{{ $nomeArquivo }}**. O arquivo com os frames
já está disponível para download.

<x-mail::panel>
**Frames extraídos:** {{ $quantidadeFrames }}

**Tamanho do arquivo:** {{ $tamanhoZip }}
@if($concluidoEm)

**Concluído em:** {{ $concluidoEm }}
@endif
</x-mail::panel>

<x-mail::button :url="config('app.url')">
Baixar o arquivo
</x-mail::button>

Obrigado,<br>
{{ config('app.name') }}
</x-mail::message>
