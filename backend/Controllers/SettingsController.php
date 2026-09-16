<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Services\AiConfig;
use App\Services\AiService;
use App\Services\Ollama;
use App\Services\Whisper;

final class SettingsController extends Controller
{
    public function index(array $params = []): void
    {
        $cfg = AiConfig::load();
        $models = Ollama::models($cfg['ollama']['endpoint']);
        $currentModel = (string) $cfg['ollama']['model'];
        if ($models && !in_array($currentModel, $models, true)) {
            array_unshift($models, $currentModel);
        }

        $this->view('settings.index', [
            'title'         => 'Ajustes - Plataforma de Ingles',
            'cfg'           => $cfg,
            'models'        => $models,
            'currentModel'  => $currentModel,
            'whisperModels' => Config::WHISPER_MODELS,
            'message'       => Request::query('status') === 'saved' ? 'Configuracion guardada.' : null,
            'error'         => Request::query('error') === 'save' ? 'No se pudo guardar ai_config.json.' : null,
        ]);
    }

    public function save(array $params = []): void
    {
        $cfg = AiConfig::load();
        $backend = (string) Request::post('backend', '');
        $custom = trim((string) Request::post('ollama_model_custom', ''));

        $cfg['backend'] = in_array($backend, ['ollama', 'openclaw'], true) ? $backend : 'ollama';
        $cfg['ollama']['endpoint'] = trim((string) Request::post('ollama_endpoint', $cfg['ollama']['endpoint']));
        $cfg['ollama']['model'] = $custom !== ''
            ? $custom
            : trim((string) Request::post('ollama_model', $cfg['ollama']['model']));
        $cfg['ollama']['timeout'] = max(10, (int) Request::post('ollama_timeout', $cfg['ollama']['timeout']));
        $cfg['openclaw']['binary'] = trim((string) Request::post('openclaw_binary', $cfg['openclaw']['binary']));
        $cfg['openclaw']['timeout'] = max(10, (int) Request::post('openclaw_timeout', $cfg['openclaw']['timeout']));
        $cfg['whisper']['model'] = trim((string) Request::post('whisper_model', $cfg['whisper']['model']));

        $this->redirect(AiConfig::save($cfg) ? '/settings?status=saved' : '/settings?error=save');
    }

    public function models(array $params = []): void
    {
        $cfg = AiConfig::load();
        $models = Ollama::models($cfg['ollama']['endpoint']);
        $this->json([
            'ok'       => (bool) $models,
            'endpoint' => $cfg['ollama']['endpoint'],
            'models'   => $models,
        ]);
    }

    public function testAi(array $params = []): void
    {
        $cfg = AiConfig::load();
        $started = microtime(true);
        $reply = AiService::ask('Reply with exactly: READY');

        $this->json([
            'ok'      => $reply !== null && $reply !== '',
            'backend' => $cfg['backend'],
            'model'   => $cfg['ollama']['model'],
            'seconds' => round(microtime(true) - $started, 1),
            'reply'   => $reply,
        ]);
    }

    public function testWhisper(array $params = []): void
    {
        $cfg = AiConfig::load();
        $model = (string) Request::query('model', $cfg['whisper']['model']);
        $this->json(Whisper::test($model));
    }
}
