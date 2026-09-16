<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\LessonController;
use App\Controllers\SettingsController;
use App\Controllers\SyncController;
use App\Controllers\TutorController;
use App\Controllers\VideoController;
use App\Core\Router;

return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/index.php', [HomeController::class, 'index']);
    $router->get('/random', [HomeController::class, 'random']);

    $router->get('/videos/{path...}', [VideoController::class, 'stream']);
    $router->head('/videos/{path...}', [VideoController::class, 'stream']);

    $router->get('/lessons/{id}', [LessonController::class, 'show']);
    $router->get('/lessons/{id}/reset', [LessonController::class, 'reset']);
    $router->post('/api/lessons/{id}/complete', [LessonController::class, 'complete']);
    $router->post('/api/tutor', [TutorController::class, 'message']);

    $router->get('/sync', [SyncController::class, 'index']);
    $router->get('/sync/process', [SyncController::class, 'processAll']);
    $router->get('/sync/process/{file}', [SyncController::class, 'processOne']);
    $router->get('/sync/re-enrich/{id}', [SyncController::class, 'reEnrich']);
    $router->get('/sync/live', [SyncController::class, 'live']);

    $router->get('/settings', [SettingsController::class, 'index']);
    $router->post('/settings', [SettingsController::class, 'save']);
    $router->get('/settings/models', [SettingsController::class, 'models']);
    $router->get('/settings/test-ai', [SettingsController::class, 'testAi']);
    $router->get('/settings/test-whisper', [SettingsController::class, 'testWhisper']);
};
