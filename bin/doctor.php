<?php

declare(strict_types=1);

/**
 * Read-only environment diagnostic for Windows and Ubuntu.
 *
 * Usage:
 *   php bin/doctor.php            # checks
 *   php bin/doctor.php --ai       # also sends a READY prompt through OpenCode
 *   php bin/doctor.php --json     # machine-readable output
 */

require_once dirname(__DIR__) . '/backend/bootstrap.php';

use App\Core\Config;
use App\Core\Env;
use App\Core\Model;
use App\Services\AiConfig;
use App\Services\Ffmpeg;
use App\Services\OpenCode;
use App\Services\Whisper;

$options = $argv ?? [];
$runAi = in_array('--ai', $options, true);
$asJson = in_array('--json', $options, true);

$user = getenv('USERNAME') ?: getenv('USER') ?: '';
if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
    $user = posix_getpwuid(posix_geteuid())['name'] ?? $user;
}

$checks = [];
$check = static function (string $name, bool $ok, string $detail, bool $critical = false) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'critical' => $critical];
};

$check('PHP', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION . ' (' . PHP_SAPI . ') / usuario: ' . $user, true);
$check('pdo_sqlite', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? 'ok' : 'falta', true);
$check('curl', extension_loaded('curl'), extension_loaded('curl') ? 'ok' : 'falta (solo afecta descargas)');
$check('mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'ok' : 'falta (se usa fallback)');
$check('proc_open', function_exists('proc_open'), function_exists('proc_open') ? 'ok' : 'deshabilitado', true);
$check('shell_exec', function_exists('shell_exec'), function_exists('shell_exec') ? 'ok' : 'deshabilitado', true);

$envPath = Env::path();
$check('.env', is_file($envPath), is_file($envPath) ? $envPath : 'no existe (crea .env desde .env.example)');

$key = (string) Env::get('OPENCODE_API_KEY', '');
$model = AiConfig::openCodeModel();
$check('OPENCODE_API_KEY', $key !== '', $key !== '' ? Env::mask($key) : 'no configurada');
$check('OPENCODE_MODEL', $model !== '', $model !== '' ? $model : 'no configurado');

$opencodeBinary = OpenCode::binary();
$check('opencode', $opencodeBinary !== null, $opencodeBinary ?? 'no encontrado', true);
$check('opencode version', true, (string) (OpenCode::version() ?? 'desconocida'));
$check('opencode service port', true, (string) OpenCode::servicePort());

$ffmpeg = Ffmpeg::binary();
$check('ffmpeg', $ffmpeg !== null, $ffmpeg ?? 'no encontrado', true);

$whisper = Whisper::binary();
$check('whisper-cli', $whisper !== null, $whisper ?? 'no encontrado', true);

$whisperModel = Whisper::defaultModel();
$modelFile = Whisper::modelFile($whisperModel);
$modelExists = is_file($modelFile);
$check(
    'modelo Whisper',
    $modelExists,
    ($modelExists ? $modelFile . ' (' . round(filesize($modelFile) / 1048576) . ' MB)' : $modelFile . ' no existe -> ' . Whisper::modelUrl($whisperModel)),
    true
);

$check('storage/tmp', is_writable(Config::tmpDir()), Config::tmpDir() . (is_writable(Config::tmpDir()) ? ' escribible' : ' NO escribible'), true);
$check('storage/runtime', is_writable(Config::runtimeDir()), Config::runtimeDir() . (is_writable(Config::runtimeDir()) ? ' escribible' : ' NO escribible'), true);
$check('storage/models', is_dir(Whisper::modelsDir()) || is_writable(dirname(Whisper::modelsDir())), Whisper::modelsDir());

$videoDir = AiConfig::videoDir();
$check('paths.video_dir', $videoDir !== '' && is_dir($videoDir), $videoDir !== '' ? $videoDir : 'no configurado');

$dbInfo = ['path' => Config::dbPath(), 'integrity' => 'no disponible', 'lessons' => 0, 'transcribed' => 0, 'questions' => 0, 'attempts' => 0, 'schema_version' => 0];
try {
    $db = Model::connection();
    $dbInfo['integrity'] = (string) $db->query('PRAGMA integrity_check')->fetchColumn();
    $dbInfo['schema_version'] = (int) $db->query('PRAGMA schema_version')->fetchColumn();
    $dbInfo['lessons'] = (int) $db->query('SELECT COUNT(*) FROM lessons')->fetchColumn();
    $dbInfo['transcribed'] = (int) $db->query("SELECT COUNT(*) FROM lessons WHERE COALESCE(transcription, '') <> ''")->fetchColumn();
    $dbInfo['questions'] = (int) $db->query('SELECT COUNT(*) FROM exam_questions')->fetchColumn();
    $dbInfo['attempts'] = (int) $db->query('SELECT COUNT(*) FROM exam_attempts')->fetchColumn();
    $check('base de datos', $dbInfo['integrity'] === 'ok', $dbInfo['path'] . ' / integrity=' . $dbInfo['integrity'], true);
} catch (\Throwable $e) {
    $check('base de datos', false, $dbInfo['path'] . ': ' . $e->getMessage(), true);
}

$ai = null;
if ($runAi) {
    $ai = OpenCode::test();
    $checks[] = [
        'name'     => 'prueba IA',
        'ok'       => (bool) $ai['ok'],
        'detail'   => $ai['ok']
            ? 'reply=' . trim((string) $ai['text']) . ' / ' . $ai['seconds'] . ' s'
            : (string) ($ai['error'] ?? 'sin detalle'),
        'critical' => true,
    ];
}

$failed = array_values(array_filter($checks, static fn (array $c): bool => $c['critical'] && !$c['ok']));
$exitCode = $failed === [] ? 0 : 1;

if ($asJson) {
    echo json_encode([
        'ok'       => $exitCode === 0,
        'checks'   => $checks,
        'ai'       => $ai,
        'db'       => $dbInfo,
        'env'      => ['path' => $envPath, 'model' => $model],
        'video_dir' => $videoDir,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

echo 'Doctor de english_app' . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;
foreach ($checks as $item) {
    $status = $item['ok'] ? 'OK  ' : ($item['critical'] ? 'FAIL' : 'WARN');
    echo '[' . $status . '] ' . str_pad($item['name'], 22) . ' ' . $item['detail'] . PHP_EOL;
}
echo str_repeat('-', 60) . PHP_EOL;
echo 'Lecciones: ' . $dbInfo['lessons'] . ' / transcritas: ' . $dbInfo['transcribed']
    . ' / preguntas: ' . $dbInfo['questions'] . ' / intentos: ' . $dbInfo['attempts'] . PHP_EOL;

if ($exitCode !== 0) {
    echo PHP_EOL . 'Corrige los FAIL antes de procesar videos o generar examenes.' . PHP_EOL;
} else {
    echo PHP_EOL . 'Todo listo.' . PHP_EOL;
}

exit($exitCode);
