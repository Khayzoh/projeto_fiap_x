<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>FIAP X · Processamento de vídeos</title>
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><rect width='16' height='16' rx='2' fill='%232A3D7C'/><rect x='3' y='5' width='10' height='6' fill='%23fff'/><rect x='3' y='3' width='2' height='1.2' fill='%23fff'/><rect x='7' y='3' width='2' height='1.2' fill='%23fff'/><rect x='11' y='3' width='2' height='1.2' fill='%23fff'/></svg>">
</head>
<body>

{{-- ============================ AUTENTICAÇÃO ============================ --}}
<div id="auth" class="auth" hidden>
    <div class="auth__panel">
        <div class="auth__brand">
            <svg class="mark" viewBox="0 0 28 28" aria-hidden="true">
                <rect x="1" y="7" width="26" height="14" rx="1.5" class="mark__body"></rect>
                <g class="mark__perf">
                    <rect x="3" y="3.2" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="9.3" y="3.2" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="15.6" y="3.2" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="21.9" y="3.2" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="3" y="22.6" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="9.3" y="22.6" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="15.6" y="22.6" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="21.9" y="22.6" width="3.4" height="2.2" rx=".4"></rect>
                </g>
            </svg>
            <div>
                <strong>FIAP X</strong>
                <span>Processamento de vídeos</span>
            </div>
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
            <svg class="mark mark--sm" viewBox="0 0 28 28" aria-hidden="true">
                <rect x="1" y="7" width="26" height="14" rx="1.5" class="mark__body"></rect>
                <g class="mark__perf">
                    <rect x="3" y="3.2" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="9.3" y="3.2" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="15.6" y="3.2" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="21.9" y="3.2" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="3" y="22.6" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="9.3" y="22.6" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="15.6" y="22.6" width="3.4" height="2.2" rx=".4"></rect>
                    <rect x="21.9" y="22.6" width="3.4" height="2.2" rx=".4"></rect>
                </g>
            </svg>
            <span>FIAP X</span>
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
