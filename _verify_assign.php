<?php

use App\Models\Task;
use App\Models\User;

$task = Task::whereNull('asignado_a')->first();
if (! $task) {
    echo 'No hay tareas sin asignar para probar.' . PHP_EOL;
    return;
}
$user = User::where('is_active', true)->first();
echo "Tarea id {$task->id}: '{$task->titulo}' — asignado actual: " . ($task->asignado_a ?? 'NULL') . PHP_EOL;

// Asignar
$task->asignado_a = $user->id;
$task->save();
$task->refresh()->load('asignado:id,name');
echo "Tras asignar -> {$task->asignado_a} ({$task->asignado?->name})" . PHP_EOL;

// Revertir (dejar como estaba)
$task->asignado_a = null;
$task->save();
echo 'Revertido a NULL: ' . ($task->fresh()->asignado_a === null ? 'OK' : 'FALLO') . PHP_EOL;
