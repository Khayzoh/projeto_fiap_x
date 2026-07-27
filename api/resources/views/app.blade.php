<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>FIAP X · Processamento de vídeos</title>
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    {{-- Preto com o magenta da marca, como a assinatura visual da FIAP. --}}
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='5' fill='%23000'/><path d='M9 9 L23 23 M23 9 L9 23' stroke='%23ED145B' stroke-width='3.4' stroke-linecap='round'/></svg>">
</head>
<body>

{{-- ============================ AUTENTICAÇÃO ============================ --}}
<div id="auth" class="auth" hidden>
    <div class="auth__panel">
        <div class="auth__brand">
            {{-- Logotipo oficial da FIAP, em vetor. A cor da marca não acompanha
                 o tema da interface: magenta em fundo claro ou escuro. --}}
            <span class="logo">
                <svg viewBox="0 0 101 25" role="img" aria-label="FIAP">
                    <path d="M30.7193 0H28.709V24.8265H30.7193V0Z"/>
                    <path d="M17.26 11.8854H7.47571V13.5916H17.26V11.8854Z"/>
                    <path d="M0 0V24.8265H2.01026V1.70619H22.7725V0H0Z"/>
                    <path d="M90.9643 0.101257H75.0078V24.9277H77.0181V15.6738H77.0338V13.9677H77.0181V1.80745H90.8229C95.6758 1.80745 98.9896 3.86065 98.9896 7.79356V7.86586C98.9896 11.553 95.5973 13.9677 90.5873 13.9677H84.2739V15.6738H90.4774C96.0999 15.6738 101 12.9844 101 7.76465V7.69235C100.968 2.89189 96.8851 0.101257 90.9643 0.101257Z"/>
                    <path d="M63.3389 13.9676L53.9943 0H52.0626L35.4151 25H37.4882L52.9892 2.02429L61.0774 13.9676H63.3389Z"/>
                    <path d="M66.4172 18.5511H64.187L68.5374 25H70.7361L66.4172 18.5511Z"/>
                </svg>
                <b class="logo__x">X</b>
            </span>
            <p class="auth__sub">Processamento de vídeos</p>
        </div>

        <div class="auth__tabs" role="tablist">
            <button type="button" class="auth__tab is-active" data-mode="login" role="tab">Entrar</button>
            <button type="button" class="auth__tab" data-mode="register" role="tab">Criar conta</button>
        </div>

        <form id="auth-form" class="auth__form" novalidate>
            <div class="field" data-only="register" hidden>
                <label for="f-name">Nome</label>
                <input type="text" id="f-name" name="name" autocomplete="name" spellcheck="false">
                <em class="field__error" data-error="name"></em>
            </div>

            <div class="field">
                <label for="f-email">E-mail</label>
                <input type="email" id="f-email" name="email" autocomplete="username" spellcheck="false" required>
                <em class="field__error" data-error="email"></em>
            </div>

            <div class="field">
                <label for="f-password">Senha</label>
                <input type="password" id="f-password" name="password" autocomplete="current-password" required>
                <em class="field__error" data-error="password"></em>
                <em class="field__hint" data-only="register" hidden>Mínimo de 8 caracteres, com letras e números.</em>
            </div>

            <div class="field" data-only="register" hidden>
                <label for="f-password2">Confirmar senha</label>
                <input type="password" id="f-password2" name="password_confirmation" autocomplete="new-password">
            </div>

            <p id="auth-alert" class="alert alert--error" hidden></p>

            <button type="submit" class="btn btn--primary btn--block" id="auth-submit">Entrar</button>
        </form>
    </div>
</div>

{{-- ============================== APLICAÇÃO ============================== --}}
<div id="app" class="app" hidden>

    <header class="topbar">
        <div class="topbar__brand">
            <span class="logo logo--sm">
                <svg viewBox="0 0 101 25" role="img" aria-label="FIAP">
                    <path d="M30.7193 0H28.709V24.8265H30.7193V0Z"/>
                    <path d="M17.26 11.8854H7.47571V13.5916H17.26V11.8854Z"/>
                    <path d="M0 0V24.8265H2.01026V1.70619H22.7725V0H0Z"/>
                    <path d="M90.9643 0.101257H75.0078V24.9277H77.0181V15.6738H77.0338V13.9677H77.0181V1.80745H90.8229C95.6758 1.80745 98.9896 3.86065 98.9896 7.79356V7.86586C98.9896 11.553 95.5973 13.9677 90.5873 13.9677H84.2739V15.6738H90.4774C96.0999 15.6738 101 12.9844 101 7.76465V7.69235C100.968 2.89189 96.8851 0.101257 90.9643 0.101257Z"/>
                    <path d="M63.3389 13.9676L53.9943 0H52.0626L35.4151 25H37.4882L52.9892 2.02429L61.0774 13.9676H63.3389Z"/>
                    <path d="M66.4172 18.5511H64.187L68.5374 25H70.7361L66.4172 18.5511Z"/>
                </svg>
                <b class="logo__x">X</b>
            </span>
        </div>

        <div class="topbar__right">
            <button type="button" class="iconbtn" id="theme-toggle" title="Alternar tema" aria-label="Alternar tema">
                <svg viewBox="0 0 20 20" aria-hidden="true" class="icon-sun">
                    <circle cx="10" cy="10" r="3.6"></circle>
                    <path d="M10 1.6v2.2M10 16.2v2.2M18.4 10h-2.2M3.8 10H1.6M15.9 4.1l-1.6 1.6M5.7 14.3l-1.6 1.6M15.9 15.9l-1.6-1.6M5.7 5.7L4.1 4.1"></path>
                </svg>
                <svg viewBox="0 0 20 20" aria-hidden="true" class="icon-moon">
                    <path d="M16.5 12.4A7 7 0 0 1 7.6 3.5a7 7 0 1 0 8.9 8.9z"></path>
                </svg>
            </button>
            <div class="user">
                <span class="user__name" id="user-name"></span>
                <button type="button" class="btn btn--ghost btn--sm" id="logout">Sair</button>
            </div>
        </div>
    </header>

    <main class="main">

        {{-- painel de envio --}}
        <section class="panel" aria-labelledby="t-envio">
            <div class="panel__head">
                <h1 id="t-envio">Enviar vídeos</h1>
                <p>Os frames são extraídos a 1 por segundo e entregues em um arquivo .zip.</p>
            </div>

            <div class="drop" id="drop" tabindex="0" role="button"
                 aria-label="Selecionar vídeos para enviar">
                <input type="file" id="file-input" accept="video/*" multiple hidden>
                <svg class="drop__icon" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 15.5V4m0 0L7.5 8.5M12 4l4.5 4.5"></path>
                    <path d="M3.5 14v3.5A2.5 2.5 0 0 0 6 20h12a2.5 2.5 0 0 0 2.5-2.5V14"></path>
                </svg>
                <div class="drop__text">
                    <strong>Arraste os vídeos aqui</strong>
                    <span>ou <u>escolha os arquivos</u> — mp4, avi, mov, mkv, wmv, flv, webm · até 500 MB</span>
                </div>
            </div>

            <ul class="queue" id="queue" hidden></ul>
        </section>

        {{-- resumo --}}
        <section class="stats" id="stats" aria-label="Resumo">
            <div class="stat" data-stat="total">
                <span class="stat__n">0</span>
                <span class="stat__l">Total</span>
            </div>
            <div class="stat" data-stat="andamento">
                <span class="stat__n">0</span>
                <span class="stat__l">Em andamento</span>
            </div>
            <div class="stat" data-stat="prontos">
                <span class="stat__n">0</span>
                <span class="stat__l">Prontos</span>
            </div>
            <div class="stat" data-stat="falhas">
                <span class="stat__n">0</span>
                <span class="stat__l">Com falha</span>
            </div>
        </section>

        {{-- lista --}}
        <section class="panel panel--flush" aria-labelledby="t-lista">
            <div class="panel__head panel__head--row">
                <div>
                    <h2 id="t-lista">Meus vídeos</h2>
                    <p id="live-hint" class="live" hidden>
                        <span class="live__dot"></span>atualizando automaticamente
                    </p>
                </div>
                <div class="filters" role="group" aria-label="Filtrar por situação">
                    <button type="button" class="chip is-active" data-filter="">Todos</button>
                    <button type="button" class="chip" data-filter="PENDING">Na fila</button>
                    <button type="button" class="chip" data-filter="PROCESSING">Processando</button>
                    <button type="button" class="chip" data-filter="COMPLETED">Prontos</button>
                    <button type="button" class="chip" data-filter="FAILED">Falhas</button>
                </div>
            </div>

            <div class="tablewrap">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Arquivo</th>
                            <th>Situação</th>
                            <th class="num">Frames</th>
                            <th class="num">Tamanho</th>
                            <th>Enviado</th>
                            <th class="acts"><span class="sr">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody id="rows"></tbody>
                </table>
            </div>

            <div class="empty" id="empty" hidden>
                <svg viewBox="0 0 40 28" aria-hidden="true">
                    <rect x="1" y="7" width="38" height="18" rx="2"></rect>
                    <path d="M4 3.5h4M12 3.5h4M20 3.5h4M28 3.5h4"></path>
                </svg>
                <strong>Nenhum vídeo por aqui</strong>
                <span>Envie um arquivo acima para começar.</span>
            </div>
        </section>
    </main>

    <div class="toasts" id="toasts" aria-live="polite"></div>
</div>

<script src="{{ asset('assets/app.js') }}"></script>
</body>
</html>
