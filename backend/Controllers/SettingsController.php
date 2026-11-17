<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Env;
use App\Core\Model;
use App\Core\Request;
use App\Services\AiConfig;
use App\Services\Ffmpeg;
use App\Services\OpenCode;
use App\Services\Whisper;

final class SettingsController extends Controller
{
    public function index(array $params = []): void
    {
        @set_time_limit(60);
        $cfg = AiConfig::load();
        $currentModel = AiConfig::openCodeModel();
        $models = OpenCode::models();
        if ($currentModel !== '' && !in_array($currentModel, $models, true)) {
            array_unshift($models, $currentModel);
        }

        $key = (string) Env::get('OPENCODE_API_KEY', '');

        $this->view('settings.index', [
            'title'         => 'Ajustes - Plataforma de Ingles',
            'cfg'           => $cfg,
            'models'        => $models,
            'currentModel'  => $currentModel,
            'hasKey'        => $key !== '',
            'keyMasked'     => $key !== '' ? Env::mask($key) : '',
            'whisperModels' => Config::WHISPER_MODELS,
            'envPath'       => Env::path(),
            'envWritable'   => is_writable(dirname(Env::path())) || is_writable(Env::path()),
            'diagnostics'   => $this->diagnosticsData($cfg),
            'message'       => Request::query('status') === 'saved' ? 'Configuracion guardada.' : null,
            'error'         => match (Request::query('error')) {
                'env'  => 'No se pudo escribir .env. Revisa los permisos del usuario del servidor.',
                'save' => 'No se pudo guardar ai_config.json.',
                default => null,
            },
        ]);
    }

    public function save(array $params = []): void
    {
        $cfg = AiConfig::load();
        $cfg['backend'] = 'opencode';

        // Model and API key live in .env (like retro_app).
        $customModel = trim((string) Request::post('opencode_model_custom', ''));
        $model = $customModel !== ''
            ? $customModel
            : trim((string) Request::post('opencode_model', $cfg['opencode']['model'] ?? ''));
        if ($model !== '') {
            Env::set('OPENCODE_MODEL', $model);
            $cfg['opencode']['model'] = $model;
        }

        $key = trim((string) Request::post('opencode_key', ''));
        if ($key !== '') {
            Env::set('OPENCODE_API_KEY', $key);
        } elseif (Request::post('opencode_clear_key') === '1') {
            Env::forget('OPENCODE_API_KEY');
        }

        try {
            Env::save();
        } catch (\Throwable $e) {
            error_log('SettingsController: ' . $e->getMessage());
            $this->redirect('/settings?error=env');
            return;
        }

        $cfg['opencode']['binary'] = trim((string) Request::post('opencode_binary', $cfg['opencode']['binary'] ?? ''));
        $cfg['opencode']['timeout'] = max(10, (int) Request::post('opencode_timeout', $cfg['opencode']['timeout'] ?? 300));
        $cfg['opencode']['archive_sessions'] = Request::post('opencode_archive') === '1';

        $cfg['paths']['video_dir'] = trim((string) Request::post('video_dir', $cfg['paths']['video_dir'] ?? ''));

        $whisperModel = trim((string) Request::post('whisper_model', $cfg['whisper']['model'] ?? 'base'));
        $cfg['whisper']['model'] = in_array($whisperModel, Config::WHISPER_MODELS, true) ? $whisperModel : 'base';
        $cfg['whisper']['english_only'] = Request::post('whisper_english_only') === '1';
        $cfg['whisper']['binary'] = trim((string) Request::post('whisper_binary', $cfg['whisper']['binary'] ?? ''));
        $cfg['whisper']['ffmpeg'] = trim((string) Request::post('whisper_ffmpeg', $cfg['whisper']['ffmpeg'] ?? ''));
        $cfg['whisper']['models_dir'] = trim((string) Request::post('whisper_models_dir', $cfg['whisper']['models_dir'] ?? ''));

        $cfg['exam']['passing_score'] = min(100, max(1, (int) Request::post('exam_passing_score', $cfg['exam']['passing_score'] ?? 80)));
        $cfg['exam']['questions'] = min(50, max(3, (int) Request::post('exam_questions', $cfg['exam']['questions'] ?? 12)));

        // Drop cached binary/model info so the new settings apply immediately.
        foreach (['binaries.json', 'opencode_caps.json', 'opencode_version.json', 'opencode_models.json'] as $cacheFile) {
            @unlink(Config::tmpDir() . DIRECTORY_SEPARATOR . $cacheFile);
        }

        $this->redirect(AiConfig::save($cfg) ? '/settings?status=saved' : '/settings?error=save');
    }

    public function models(array $params = []): void
    {
        $models = OpenCode::models(true);
        $this->json([
            'ok'      => $models !== [],
            'backend' => 'opencode',
            'source'  => OpenCode::binary() !== null ? 'opencode models' : 'defaults',
            'models'  => $models,
        ]);
    }

    public function testAi(array $params = []): void
    {
        @set_time_limit(180);
        $result = OpenCode::test();
        $this->json([
            'ok'      => $result['ok'],
            'backend' => 'opencode',
            'model'   => $result['model'],
            'seconds' => $result['seconds'],
            'reply'   => $result['text'],
            'error'   => $result['error'],
        ]);
    }

    public function testWhisper(array $params = []): void
    {
        @set_time_limit(1800);
        $cfg = AiConfig::load();
        $model = (string) Request::query('model', $cfg['whisper']['model'] ?? 'base');
        if (!in_array($model, Config::WHISPER_MODELS, true)) {
            $model = 'base';
        }
        $this->json(Whisper::test($model));
    }

    public function doctor(array $params = []): void
    {
        $this->json($this->diagnosticsData(AiConfig::load()));
    }

    private function diagnosticsData(array $cfg): array
    {
        $db = Model::connection();
        $dbInfo = [
            'path'            => Config::dbPath(),
            'exists'          => is_file(Config::dbPath()),
            'integrity'       => (string) $db->query('PRAGMA integrity_check')->fetchColumn(),
            'schema_version'  => (int) $db->query('PRAGMA schema_version')->fetchColumn(),
        ];
        try {
            $dbInfo['lessons'] = (int) $db->query('SELECT COUNT(*) FROM lessons')->fetchColumn();
            $dbInfo['transcribed'] = (int) $db->query(
                "SELECT COUNT(*) FROM lessons WHERE COALESCE(transcription, '') <> ''"
            )->fetchColumn();
            $dbInfo['questions'] = (int) $db->query('SELECT COUNT(*) FROM exam_questions')->fetchColumn();
            $dbInfo['attempts'] = (int) $db->query('SELECT COUNT(*) FROM exam_attempts')->fetchColumn();
        } catch (\Throwable $e) {
            $dbInfo['error'] = $e->getMessage();
        }

        return [
            'php'      => [
                'version'    => PHP_VERSION,
                'sapi'       => PHP_SAPI,
                'user'       => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user())
                    : get_current_user(),
                'pdo_sqlite' => extension_loaded('pdo_sqlite'),
                'curl'       => extension_loaded('curl'),
                'mbstring'   => extension_loaded('mbstring'),
                'proc_open'  => function_exists('proc_open'),
                'shell_exec' => function_exists('shell_exec'),
            ],
            'env'      => [
                'path'     => Env::path(),
                'exists'   => is_file(Env::path()),
                'writable' => is_writable(Env::path()) || is_writable(dirname(Env::path())),
                'key'      => (string) Env::get('OPENCODE_API_KEY', '') !== '',
                'model'    => AiConfig::openCodeModel(),
            ],
            'opencode' => OpenCode::diagnostics(),
            'whisper'  => Whisper::diagnostics(),
            'ffmpeg'   => [
                'binary'  => Ffmpeg::binary(),
                'version' => Ffmpeg::version(),
            ],
            'storage'  => [
                'tmp'     => is_writable(Config::tmpDir()),
                'runtime' => is_writable(Config::runtimeDir()),
                'models'  => is_writable(dirname(Whisper::modelsDir())) || is_writable(Whisper::modelsDir()),
            ],
            'db'       => $dbInfo,
            'video'    => [
                'dir'  => AiConfig::videoDir(),
                'ok'   => AiConfig::videoDir() !== '' && is_dir(AiConfig::videoDir()),
            ],
            'backend'  => (string) ($cfg['backend'] ?? 'opencode'),
            'config'   => Config::aiConfigPath(),
        ];
    }
}
