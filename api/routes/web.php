<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Interface web do sistema. E uma pagina estatica que consome a propria API
 * REST via fetch — mesma origem, entao nao ha CORS envolvido. A autenticacao
 * usa o mesmo JWT das chamadas de API.
 */
Route::view('/', 'app')->name('app');
