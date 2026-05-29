<?php

use App\Http\Controllers\Admin\AiGenerationController;
use App\Http\Controllers\Admin\GmailIntegrationController;
use App\Http\Controllers\Admin\ClientAssignmentController;
use App\Http\Controllers\Admin\ClientContactController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ContractController;
use App\Http\Controllers\Admin\ProcessController;
use App\Http\Controllers\Admin\ProcessStageController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'verified'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // Usuarios — solo director
        Route::middleware('role:director')->group(function () {
            Route::resource('users', UserController::class)->except(['show']);
            Route::patch('users/{user}/toggle-active', [UserController::class, 'toggleActive'])
                ->name('users.toggle-active');
        });

        // Clientes — accesible por permiso
        Route::middleware('permission:clients.view|clients.view_assigned')
            ->get('clients', [ClientController::class, 'index'])
            ->name('clients.index');

        Route::middleware('permission:clients.view|clients.view_assigned')
            ->get('clients/{client}', [ClientController::class, 'show'])
            ->name('clients.show');

        Route::middleware('permission:clients.create')->group(function () {
            Route::get('clients/create/new', [ClientController::class, 'create'])->name('clients.create');
            Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
        });

        Route::middleware('permission:clients.update')->group(function () {
            Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
            Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');

            Route::post('clients/{client}/contacts', [ClientContactController::class, 'store'])->name('clients.contacts.store');
            Route::put('clients/{client}/contacts/{contact}', [ClientContactController::class, 'update'])->name('clients.contacts.update');
            Route::delete('clients/{client}/contacts/{contact}', [ClientContactController::class, 'destroy'])->name('clients.contacts.destroy');

            Route::post('clients/{client}/assignments', [ClientAssignmentController::class, 'store'])->name('clients.assignments.store');
            Route::delete('clients/{client}/assignments/{user}', [ClientAssignmentController::class, 'destroy'])->name('clients.assignments.destroy');
        });

        Route::middleware('permission:clients.delete')
            ->delete('clients/{client}', [ClientController::class, 'destroy'])
            ->name('clients.destroy');

        // Contratos
        Route::middleware('permission:contracts.view')->group(function () {
            Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
            Route::get('contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
        });

        Route::middleware('permission:contracts.create')->group(function () {
            Route::get('contracts/create/new', [ContractController::class, 'create'])->name('contracts.create');
            Route::post('contracts', [ContractController::class, 'store'])->name('contracts.store');
        });

        Route::middleware('permission:contracts.update')->group(function () {
            Route::get('contracts/{contract}/edit', [ContractController::class, 'edit'])->name('contracts.edit');
            Route::put('contracts/{contract}', [ContractController::class, 'update'])->name('contracts.update');
        });

        Route::middleware('permission:contracts.delete')
            ->delete('contracts/{contract}', [ContractController::class, 'destroy'])
            ->name('contracts.destroy');

        // Procesos
        Route::middleware('permission:processes.view|processes.view_assigned')->group(function () {
            Route::get('processes', [ProcessController::class, 'index'])->name('processes.index');
            Route::get('processes/{process}', [ProcessController::class, 'show'])->name('processes.show');
        });

        Route::middleware('permission:processes.create')->group(function () {
            Route::get('processes/create/new', [ProcessController::class, 'create'])->name('processes.create');
            Route::post('processes', [ProcessController::class, 'store'])->name('processes.store');
        });

        Route::middleware('permission:processes.update')->group(function () {
            Route::get('processes/{process}/edit', [ProcessController::class, 'edit'])->name('processes.edit');
            Route::put('processes/{process}', [ProcessController::class, 'update'])->name('processes.update');
            Route::delete('processes/{process}', [ProcessController::class, 'destroy'])->name('processes.destroy');
        });

        // Etapas y checklist
        Route::middleware('permission:stages.update')->group(function () {
            Route::patch('processes/{process}/stages/{stage}', [ProcessStageController::class, 'update'])
                ->name('processes.stages.update');
            Route::patch('processes/{process}/stages/{stage}/reopen', [ProcessStageController::class, 'reopen'])
                ->name('processes.stages.reopen');
            Route::patch('processes/{process}/stages/{stage}/checklist/{response}', [ProcessStageController::class, 'toggleChecklistItem'])
                ->name('processes.stages.checklist.toggle');
        });

        Route::middleware('permission:stages.complete')
            ->patch('processes/{process}/stages/{stage}/complete', [ProcessStageController::class, 'complete'])
            ->name('processes.stages.complete');

        // === IA ===
        Route::middleware('permission:ai.use')
            ->post('processes/{process}/ai/generate', [AiGenerationController::class, 'store'])
            ->name('processes.ai.generate');

        Route::middleware('permission:ai.use')
            ->post('processes/{process}/ai/document', [AiGenerationController::class, 'storeAsDocument'])
            ->name('processes.ai.document');

        Route::middleware('permission:ai.use')
            ->post('processes/{process}/ai/comment', [AiGenerationController::class, 'storeAsComment'])
            ->name('processes.ai.comment');

        Route::middleware('permission:ai.use')
            ->get('ai/playground', [AiGenerationController::class, 'playground'])
            ->name('ai.playground');

        Route::middleware('permission:ai.usage_view')
            ->get('ai/usage', [AiGenerationController::class, 'index'])
            ->name('ai.usage');

        // === Gmail ===
        Route::middleware('role:director')
            ->prefix('integrations/gmail')
            ->name('integrations.gmail.')
            ->group(function () {
                Route::get('status', [GmailIntegrationController::class, 'status'])->name('status');
                Route::get('connect', [GmailIntegrationController::class, 'connect'])->name('connect');
                Route::get('callback', [GmailIntegrationController::class, 'callback'])->name('callback');
                Route::post('disconnect', [GmailIntegrationController::class, 'disconnect'])->name('disconnect');
            });
    });

require __DIR__.'/auth.php';
