<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'book-list')->name('home');
Route::livewire('/create', 'create-book')->name('book.create');
