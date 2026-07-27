/* =========================================================================
   FIAP X — interface do sistema de processamento de vídeos

   Consome a própria API REST (mesma origem). O token JWT fica em
   localStorage; como o processamento é assíncrono, a lista se atualiza
   sozinha enquanto houver vídeo em andamento e para quando tudo termina.
   ========================================================================= */

(function () {
    'use strict';

    var API = '/api';
    var CHAVE_TOKEN = 'fiapx.token';
    var CHAVE_USER = 'fiapx.user';
    var CHAVE_TEMA = 'fiapx.tema';
    var INTERVALO_ATUALIZACAO = 2000;

    var el = {
        auth: document.getElementById('auth'),
        app: document.getElementById('app'),
        authForm: document.getElementById('auth-form'),
        authAlert: document.getElementById('auth-alert'),
        authSubmit: document.getElementById('auth-submit'),
        userName: document.getElementById('user-name'),
        logout: document.getElementById('logout'),
        drop: document.getElementById('drop'),
        fileInput: document.getElementById('file-input'),
        queue: document.getElementById('queue'),
        rows: document.getElementById('rows'),
        empty: document.getElementById('empty'),
        stats: document.getElementById('stats'),
        liveHint: document.getElementById('live-hint'),
        toasts: document.getElementById('toasts'),
        themeToggle: document.getElementById('theme-toggle')
    };

    var estado = {
        token: localStorage.getItem(CHAVE_TOKEN),
        usuario: JSON.parse(localStorage.getItem(CHAVE_USER) || 'null'),
        modo: 'login',
        filtro: '',
        videos: [],
        timer: null,
        enviando: 0
    };

    /* ------------------------------------------------------------ utils -- */

    function tamanho(bytes) {
        if (!bytes && bytes !== 0) return '—';
        if (bytes < 1024) return bytes + ' B';
        var kb = bytes / 1024;
        if (kb < 1024) return kb.toFixed(0) + ' KB';
        var mb = kb / 1024;
        if (mb < 1024) return mb.toFixed(mb < 10 ? 1 : 0) + ' MB';
        return (mb / 1024).toFixed(1) + ' GB';
    }

    function quando(iso) {
        if (!iso) return '—';
        var seg = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
        if (seg < 10) return 'agora';
        if (seg < 60) return 'há ' + seg + ' s';
        var min = Math.floor(seg / 60);
        if (min < 60) return 'há ' + min + ' min';
        var h = Math.floor(min / 60);
        if (h < 24) return 'há ' + h + ' h';
        var d = Math.floor(h / 24);
        if (d < 7) return 'há ' + d + (d === 1 ? ' dia' : ' dias');
        return new Date(iso).toLocaleDateString('pt-BR');
    }

    var ROTULO = {
        PENDING: 'Na fila',
        PROCESSING: 'Processando',
        COMPLETED: 'Pronto',
        FAILED: 'Falhou'
    };

    function escapar(txt) {
        var d = document.createElement('div');
        d.textContent = txt == null ? '' : String(txt);
        return d.innerHTML;
    }

    function toast(msg, tipo) {
        var t = document.createElement('div');
        t.className = 'toast';
        t.dataset.kind = tipo || 'info';
        t.innerHTML = '<span class="toast__bar"></span><span>' + escapar(msg) + '</span>';
        el.toasts.appendChild(t);
        setTimeout(function () {
            t.style.opacity = '0';
            t.style.transition = 'opacity .25s ease';
            setTimeout(function () { t.remove(); }, 250);
        }, 4200);
    }

    /* -------------------------------------------------------------- api -- */

    function chamar(caminho, opcoes) {
        opcoes = opcoes || {};
        var cabecalhos = opcoes.headers || {};
        cabecalhos['Accept'] = 'application/json';
        if (estado.token) cabecalhos['Authorization'] = 'Bearer ' + estado.token;

        return fetch(API + caminho, {
            method: opcoes.method || 'GET',
            headers: cabecalhos,
            body: opcoes.body
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (corpo) {
                if (!r.ok) {
                    var erro = new Error(corpo.message || 'Falha na requisição.');
                    erro.status = r.status;
                    erro.errors = corpo.errors;
                    throw erro;
                }
                return corpo;
            });
        });
    }

    /* ----------------------------------------------------------- sessão -- */

    function entrar(dados) {
        estado.token = dados.token;
        estado.usuario = dados.user;
        localStorage.setItem(CHAVE_TOKEN, dados.token);
        localStorage.setItem(CHAVE_USER, JSON.stringify(dados.user));
        mostrarApp();
    }

    function sair() {
        estado.token = null;
        estado.usuario = null;
        estado.videos = [];
        localStorage.removeItem(CHAVE_TOKEN);
        localStorage.removeItem(CHAVE_USER);
        pararAtualizacao();
        el.app.hidden = true;
        el.auth.hidden = false;
        el.authForm.reset();
    }

    function mostrarApp() {
        el.auth.hidden = true;
        el.app.hidden = false;
        el.userName.textContent = estado.usuario ? estado.usuario.name : '';
        carregar();
    }

    /* ------------------------------------------------- formulário de auth - */

    function trocarModo(modo) {
        estado.modo = modo;
        el.authAlert.hidden = true;
        limparErros();

        Array.prototype.forEach.call(document.querySelectorAll('.auth__tab'), function (b) {
            b.classList.toggle('is-active', b.dataset.mode === modo);
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-only="register"]'), function (n) {
            n.hidden = modo !== 'register';
        });

        el.authSubmit.textContent = modo === 'login' ? 'Entrar' : 'Criar conta';
        document.getElementById('f-password').setAttribute(
            'autocomplete', modo === 'login' ? 'current-password' : 'new-password'
        );
    }

    function limparErros() {
        Array.prototype.forEach.call(document.querySelectorAll('.field__error'), function (n) {
            n.textContent = '';
        });
        Array.prototype.forEach.call(el.authForm.querySelectorAll('input'), function (i) {
            i.removeAttribute('aria-invalid');
        });
    }

    function mostrarErros(erro) {
        limparErros();

        if (erro.errors) {
            Object.keys(erro.errors).forEach(function (campo) {
                var alvo = document.querySelector('[data-error="' + campo + '"]');
                if (alvo) alvo.textContent = erro.errors[campo][0];
                var input = el.authForm.querySelector('[name="' + campo + '"]');
                if (input) input.setAttribute('aria-invalid', 'true');
            });
            return;
        }

        // 429 vem do limite de tentativas de login — a mensagem crua do
        // framework não explica o que fazer.
        var msg = erro.status === 429
            ? 'Muitas tentativas seguidas. Aguarde um minuto e tente de novo.'
            : erro.message;

        el.authAlert.textContent = msg;
        el.authAlert.hidden = false;
    }

    el.authForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        el.authAlert.hidden = true;
        limparErros();

        var dados = Object.fromEntries(new FormData(el.authForm).entries());
        var rota = estado.modo === 'login' ? '/auth/login' : '/auth/register';

        if (estado.modo === 'login') {
            delete dados.name;
            delete dados.password_confirmation;
        }

        el.authSubmit.disabled = true;
        el.authSubmit.textContent = estado.modo === 'login' ? 'Entrando…' : 'Criando…';

        chamar(rota, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(dados)
        }).then(entrar).catch(mostrarErros).finally(function () {
            el.authSubmit.disabled = false;
            el.authSubmit.textContent = estado.modo === 'login' ? 'Entrar' : 'Criar conta';
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('.auth__tab'), function (b) {
        b.addEventListener('click', function () { trocarModo(b.dataset.mode); });
    });

    el.logout.addEventListener('click', sair);

    /* ------------------------------------------------------------ envio -- */

    function enviar(arquivo) {
        var item = document.createElement('li');
        item.className = 'queue__item';
        item.innerHTML =
            '<span class="queue__name">' + escapar(arquivo.name) + '</span>' +
            '<span class="queue__pct">0%</span>' +
            '<span class="queue__bar"><span class="queue__fill"></span></span>';
        el.queue.appendChild(item);
        el.queue.hidden = false;

        var pct = item.querySelector('.queue__pct');
        var fill = item.querySelector('.queue__fill');

        var form = new FormData();
        form.append('video', arquivo);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', API + '/videos');
        xhr.setRequestHeader('Authorization', 'Bearer ' + estado.token);
        xhr.setRequestHeader('Accept', 'application/json');

        estado.enviando++;

        // XHR em vez de fetch justamente por causa deste evento: fetch não
        // expõe progresso de upload.
        xhr.upload.addEventListener('progress', function (ev) {
            if (!ev.lengthComputable) return;
            var p = Math.round((ev.loaded / ev.total) * 100);
            pct.textContent = p + '%';
            fill.style.width = p + '%';
        });

        xhr.addEventListener('load', function () {
            estado.enviando--;
            var corpo = {};
            try { corpo = JSON.parse(xhr.responseText); } catch (e) { /* resposta vazia */ }

            if (xhr.status === 202) {
                pct.textContent = 'na fila';
                fill.style.width = '100%';
                setTimeout(function () { removerDaFila(item); }, 1400);
                carregar();
            } else {
                var msg = corpo.errors && corpo.errors.video
                    ? corpo.errors.video[0]
                    : (corpo.message || 'Não foi possível enviar.');
                item.dataset.state = 'erro';
                pct.textContent = 'erro';
                fill.style.width = '100%';
                toast(arquivo.name + ': ' + msg, 'erro');
                setTimeout(function () { removerDaFila(item); }, 5000);
            }
        });

        xhr.addEventListener('error', function () {
            estado.enviando--;
            item.dataset.state = 'erro';
            pct.textContent = 'erro';
            toast('Falha de conexão ao enviar ' + arquivo.name, 'erro');
            setTimeout(function () { removerDaFila(item); }, 5000);
        });

        xhr.send(form);
    }

    function removerDaFila(item) {
        item.remove();
        if (!el.queue.children.length) el.queue.hidden = true;
    }

    function receber(arquivos) {
        if (!arquivos || !arquivos.length) return;
        Array.prototype.forEach.call(arquivos, enviar);
    }

    el.drop.addEventListener('click', function () { el.fileInput.click(); });
    el.drop.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            el.fileInput.click();
        }
    });

    el.fileInput.addEventListener('change', function () {
        receber(el.fileInput.files);
        el.fileInput.value = '';
    });

    ['dragenter', 'dragover'].forEach(function (evt) {
        el.drop.addEventListener(evt, function (ev) {
            ev.preventDefault();
            el.drop.classList.add('is-over');
        });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        el.drop.addEventListener(evt, function (ev) {
            ev.preventDefault();
            el.drop.classList.remove('is-over');
        });
    });
    el.drop.addEventListener('drop', function (ev) {
        receber(ev.dataTransfer.files);
    });

    // Soltar um arquivo fora da zona não deve fazer o navegador abri-lo.
    window.addEventListener('dragover', function (ev) { ev.preventDefault(); });
    window.addEventListener('drop', function (ev) { ev.preventDefault(); });

    /* ------------------------------------------------------------ lista -- */

    function carregar() {
        if (!estado.token) return;

        chamar('/videos?per_page=100').then(function (resposta) {
            estado.videos = resposta.data || [];
            desenhar();
            ajustarAtualizacao();
        }).catch(function (erro) {
            if (erro.status === 401) {
                sair();
                toast('Sua sessão expirou. Entre novamente.', 'erro');
                return;
            }
            toast(erro.message, 'erro');
        });
    }

    function desenhar() {
        var lista = estado.filtro
            ? estado.videos.filter(function (v) { return v.status === estado.filtro; })
            : estado.videos;

        el.rows.innerHTML = lista.map(linha).join('');
        // Enquanto há arquivo subindo, dizer "nenhum vídeo por aqui"
        // contradiz a fila de envio logo acima.
        el.empty.hidden = lista.length > 0 || estado.enviando > 0;

        // Mensagem do estado vazio muda conforme haja ou não filtro ativo.
        var vazioTitulo = el.empty.querySelector('strong');
        var vazioTexto = el.empty.querySelector('span');
        if (estado.filtro && estado.videos.length) {
            vazioTitulo.textContent = 'Nenhum vídeo nesta situação';
            vazioTexto.textContent = 'Escolha outro filtro para ver os demais.';
        } else {
            vazioTitulo.textContent = 'Nenhum vídeo por aqui';
            vazioTexto.textContent = 'Envie um arquivo acima para começar.';
        }

        atualizarResumo();
    }

    function linha(v) {
        var rodando = v.status === 'PROCESSING';
        var erro = v.status === 'FAILED' && v.error_message
            ? '<div class="errline">' + escapar(v.error_message) + '</div>'
            : '';

        var acao = v.downloadable
            ? '<button type="button" class="btn btn--ghost btn--sm" data-baixar="' + v.id + '">Baixar .zip</button>'
            : '';

        return '' +
            '<tr class="' + (rodando ? 'is-running' : '') + '">' +
                '<td>' +
                    '<div class="cell-file">' +
                        '<span class="cell-file__name" title="' + escapar(v.filename) + '">' + escapar(v.filename) + '</span>' +
                        '<span class="cell-file__id">' + escapar(v.id.slice(0, 8)) + '</span>' +
                    '</div>' + erro +
                '</td>' +
                '<td>' +
                    '<span class="pill" data-s="' + v.status + '">' +
                        '<span class="pill__dot"></span>' + ROTULO[v.status] +
                    '</span>' +
                '</td>' +
                '<td class="num' + (v.frame_count ? '' : ' num--muted') + '">' +
                    (v.frame_count != null ? v.frame_count : '—') +
                '</td>' +
                '<td class="num num--muted">' + tamanho(v.zip_size_bytes || v.size_bytes) + '</td>' +
                '<td class="when">' + quando(v.created_at) + '</td>' +
                '<td class="acts">' + acao + '</td>' +
            '</tr>';
    }

    function atualizarResumo() {
        var conta = { total: estado.videos.length, andamento: 0, prontos: 0, falhas: 0 };

        estado.videos.forEach(function (v) {
            if (v.status === 'PENDING' || v.status === 'PROCESSING') conta.andamento++;
            else if (v.status === 'COMPLETED') conta.prontos++;
            else if (v.status === 'FAILED') conta.falhas++;
        });

        Object.keys(conta).forEach(function (chave) {
            var caixa = el.stats.querySelector('[data-stat="' + chave + '"]');
            if (!caixa) return;
            caixa.querySelector('.stat__n').textContent = conta[chave];
            caixa.dataset.zero = conta[chave] === 0 ? '1' : '0';
        });
    }

    /* --------------------------------------------- atualização automática - */

    function haTrabalho() {
        return estado.enviando > 0 || estado.videos.some(function (v) {
            return v.status === 'PENDING' || v.status === 'PROCESSING';
        });
    }

    function ajustarAtualizacao() {
        // Só consulta o servidor enquanto houver algo em andamento; com tudo
        // concluído a página fica parada, sem tráfego desnecessário.
        if (haTrabalho()) {
            el.liveHint.hidden = false;
            if (!estado.timer) estado.timer = setInterval(carregar, INTERVALO_ATUALIZACAO);
        } else {
            pararAtualizacao();
        }
    }

    function pararAtualizacao() {
        el.liveHint.hidden = true;
        if (estado.timer) {
            clearInterval(estado.timer);
            estado.timer = null;
        }
    }

    // Não faz sentido continuar consultando com a aba em segundo plano.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) pararAtualizacao();
        else if (estado.token) carregar();
    });

    /* --------------------------------------------------------- download -- */

    el.rows.addEventListener('click', function (ev) {
        var botao = ev.target.closest('[data-baixar]');
        if (!botao) return;

        botao.disabled = true;
        botao.textContent = 'Gerando…';

        chamar('/videos/' + botao.dataset.baixar + '/download').then(function (r) {
            // O arquivo vem direto do storage por link assinado; a API só assina.
            window.location.assign(r.download_url);
            toast('Download iniciado.', 'ok');
        }).catch(function (erro) {
            toast(erro.message, 'erro');
        }).finally(function () {
            botao.disabled = false;
            botao.textContent = 'Baixar .zip';
        });
    });

    /* ---------------------------------------------------------- filtros -- */

    Array.prototype.forEach.call(document.querySelectorAll('.chip'), function (c) {
        c.addEventListener('click', function () {
            estado.filtro = c.dataset.filter;
            Array.prototype.forEach.call(document.querySelectorAll('.chip'), function (o) {
                o.classList.toggle('is-active', o === c);
            });
            desenhar();
        });
    });

    /* ------------------------------------------------------------- tema -- */

    function aplicarTema(tema) {
        document.documentElement.dataset.theme = tema;
        localStorage.setItem(CHAVE_TEMA, tema);
    }

    el.themeToggle.addEventListener('click', function () {
        var atual = document.documentElement.dataset.theme;
        aplicarTema(atual === 'dark' ? 'light' : 'dark');
    });

    /* ------------------------------------------------------------ início - */

    var temaSalvo = localStorage.getItem(CHAVE_TEMA);
    aplicarTema(temaSalvo || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));

    trocarModo('login');

    if (estado.token) {
        mostrarApp();
    } else {
        el.auth.hidden = false;
    }
})();
