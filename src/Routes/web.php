<?php declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/* ---------- Welcome View ---------- */
Route::get('/', fn(): View => view('welcome'));
