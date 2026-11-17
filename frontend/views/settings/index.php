<?php
/** @var array $cfg */
/** @var string[] $models */
/** @var string $currentModel */
/** @var bool $hasKey */
/** @var string $keyMasked */
/** @var string[] $whisperModels */
/** @var string $envPath */
/** @var bool $envWritable */
/** @var array $diagnostics */
/** @var string|null $message */
/** @var string|null $error */

$h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$ok = static fn (bool $condition): string => $condition ? '&#9989;' : '&#10060;';
$optional = static function (bool $found, bool $active): string {
    if ($found) {
        return '&#9989;';
    }
    return $active ? '&#10060;' : '&#9898;';
};
$modelState = static function (bool $exists, bool $active): string {
    if (!$active) {
        return '&#9898;';
    }
    return $exists ? '&#9989;' : '&#9888;';
};
$opencode = $diagnostics['opencode'];
$whisper = $diagnostics['whisper'];
$ffmpeg = $diagnostics['ffmpeg'];
$db = $diagnostics['db'];
$php = $diagnostics['php'];
$environment = $diagnostics['env'];
$video = $diagnostics['video'];
$storage = $diagnostics['storage'];
?>
<a href="/" style="text-decoration: none; color: #3498db;">&larr; Volver al indice</a>
<h1>&#9881;&#65039; Ajustes</h1>

<?php if ($message): ?><p class="success"><?php echo $h($message); ?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?php echo $h($error); ?></p><?php endif; ?>

<form method="post" action="/settings" class="content-section">
    <h2>Motor de IA</h2>
    <p style="color:#666; margin-top:0;">
        OpenCode CLI usa tu suscripcion (modelos <code>opencode-go/*</code>) o los modelos gratuitos
        (<code>opencode/*-free</code>). La API key y el modelo se guardan en <code><?php echo $h($envPath); ?></code>.
    </p>

    <label class="field">Modelo:
        <select name="opencode_model" class="form-control">
            <?php if (!$models): ?><option value="<?php echo $h($currentModel); ?>"><?php echo $h($currentModel); ?></option><?php endif; ?>
            <?php foreach ($models as $m): ?>
                <option value="<?php echo $h($m); ?>" <?php echo $m === $currentModel ? 'selected' : ''; ?>><?php echo $h($m); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">Otro modelo (opcional, tiene prioridad):
        <input type="text" name="opencode_model_custom" class="form-control" placeholder="ej: opencode-go/deepseek-v4.1-flash#high">
    </label>

    <label class="field">API key (<code>OPENCODE_API_KEY</code>):
        <input type="password" name="opencode_key" class="form-control" autocomplete="new-password"
               placeholder="<?php echo $hasKey ? $h('Guardada: ' . $keyMasked) : 'Sin configurar'; ?>">
    </label>
    <label style="display:flex; align-items:center; gap:8px; margin-bottom:14px;">
        <input type="checkbox" name="opencode_clear_key" value="1">
        <span>Eliminar la API key guardada</span>
    </label>
    <p style="color:#666; margin-top:0; font-size:0.9em;">
        Deja el campo vacio para conservar la actual. Puedes crear una key en
        <a href="https://opencode.ai/console" target="_blank" rel="noopener">OpenCode Console &rarr; API keys</a>.
    </p>

    <label class="field">Binario de opencode (opcional, se autodetecta):
        <input type="text" name="opencode_binary" class="form-control" value="<?php echo $h($cfg['opencode']['binary'] ?? ''); ?>"
               placeholder="ej: /usr/local/bin/opencode o C:\Users\...\opencode.exe">
    </label>
    <label class="field">Timeout (s):
        <input type="number" name="opencode_timeout" class="form-control" value="<?php echo (int) ($cfg['opencode']['timeout'] ?? 300); ?>" min="10">
    </label>
    <label style="display:flex; align-items:center; gap:8px; margin-bottom:14px;">
        <input type="checkbox" name="opencode_archive" value="1" <?php echo !empty($cfg['opencode']['archive_sessions']) ? 'checked' : ''; ?>>
        <span>Archivar las sesiones que crea la app (recomendado)</span>
    </label>

    <h3>Videos</h3>
    <label class="field">Carpeta raiz de los videos:
        <input type="text" name="video_dir" class="form-control" value="<?php echo $h($cfg['paths']['video_dir'] ?? ''); ?>"
               placeholder="ej: C:\videos\ingles o /home/usuario/ingles">
    </label>
    <p style="color:#666; margin-top:0; font-size:0.9em;">
        Dentro de esta carpeta van las subcarpetas por nivel (<code>4A/</code>, <code>4B/</code>, ...).
    </p>

    <h3>Whisper (transcripcion local)</h3>
    <label class="field">Motor:
        <select name="whisper_engine" class="form-control">
            <?php $engine = $cfg['whisper']['engine'] ?? 'auto'; ?>
            <option value="auto" <?php echo $engine === 'auto' ? 'selected' : ''; ?>>auto (whisper.cpp si esta listo; si no, openai-whisper)</option>
            <option value="whisper_cpp" <?php echo $engine === 'whisper_cpp' ? 'selected' : ''; ?>>whisper.cpp (nativo, sin Python)</option>
            <option value="openai_whisper" <?php echo $engine === 'openai_whisper' ? 'selected' : ''; ?>>openai-whisper (CLI de Python)</option>
        </select>
    </label>
    <label class="field">Modelo:
        <select name="whisper_model" class="form-control">
            <?php foreach ($whisperModels as $w): ?>
                <option value="<?php echo $h($w); ?>" <?php echo $w === ($cfg['whisper']['model'] ?? 'base') ? 'selected' : ''; ?>>
                    <?php echo $h($w); ?><?php echo in_array($w, ['tiny', 'base', 'small', 'medium'], true) ? ' (english)' : ''; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">Idioma del audio:
        <input type="text" name="whisper_language" class="form-control" value="<?php echo $h($cfg['whisper']['language'] ?? 'English'); ?>" placeholder="English">
    </label>
    <label style="display:flex; align-items:center; gap:8px; margin-bottom:14px;">
        <input type="checkbox" name="whisper_english_only" value="1" <?php echo !empty($cfg['whisper']['english_only']) ? 'checked' : ''; ?>>
        <span>Usar modelos <code>.en</code> de whisper.cpp (recomendado para clases de ingles)</span>
    </label>
    <label class="field">Binario de whisper-cli (whisper.cpp, opcional):
        <input type="text" name="whisper_binary" class="form-control" value="<?php echo $h($cfg['whisper']['binary'] ?? ''); ?>"
               placeholder="ej: /opt/whisper.cpp/build/bin/whisper-cli o C:\whisper\whisper-cli.exe">
    </label>
    <label class="field">Binario de whisper (openai-whisper, opcional):
        <input type="text" name="whisper_openai_binary" class="form-control" value="<?php echo $h($cfg['whisper']['openai_binary'] ?? ''); ?>"
               placeholder="ej: /home/usuario/.local/bin/whisper">
    </label>
    <label class="field">Binario de ffmpeg (opcional, se autodetecta):
        <input type="text" name="whisper_ffmpeg" class="form-control" value="<?php echo $h($cfg['whisper']['ffmpeg'] ?? ''); ?>"
               placeholder="ej: /usr/bin/ffmpeg o C:\ffmpeg\bin\ffmpeg.exe">
    </label>
    <label class="field">Carpeta de modelos ggml:
        <input type="text" name="whisper_models_dir" class="form-control" value="<?php echo $h($cfg['whisper']['models_dir'] ?? ''); ?>"
               placeholder="<?php echo $h($whisper['models_dir']); ?>">
    </label>

    <h3>Examenes</h3>
    <label class="field">Preguntas por examen:
        <input type="number" name="exam_questions" class="form-control" min="3" max="50" value="<?php echo (int) ($cfg['exam']['questions'] ?? 12); ?>">
    </label>
    <label class="field">Puntaje minimo para aprobar (%):
        <input type="number" name="exam_passing_score" class="form-control" min="1" max="100" value="<?php echo (int) ($cfg['exam']['passing_score'] ?? 80); ?>">
    </label>

    <button type="submit" class="btn">Guardar</button>
</form>

<div class="content-section">
    <h2>Pruebas</h2>
    <div class="actions">
        <button class="btn" type="button" onclick="refreshModels()">Listar modelos OpenCode</button>
        <button class="btn" style="background:#8e44ad" type="button" onclick="testAi()">Probar IA</button>
        <button class="btn" style="background:#16a085" type="button" onclick="testWhisper()">Probar Whisper</button>
        <button class="btn" style="background:#34495e" type="button" onclick="runDoctor()">Diagnostico</button>
    </div>
    <pre id="result" style="background:#f4f4f4; padding:15px; border-radius:8px; white-space:pre-wrap; min-height:60px;">Sin resultados todavia.</pre>
</div>

<div class="content-section">
    <h2>Diagnostico del entorno</h2>
    <div class="table-wrap">
        <table class="table">
            <tbody>
                <tr><th>PHP</th><td><?php echo $h($php['version'] . ' (' . $php['sapi'] . ')'); ?> / usuario: <?php echo $h($php['user']); ?></td></tr>
                <tr><th>Extensiones</th><td>pdo_sqlite <?php echo $ok((bool) $php['pdo_sqlite']); ?> / curl <?php echo $ok((bool) $php['curl']); ?> / mbstring <?php echo $ok((bool) $php['mbstring']); ?> / proc_open <?php echo $ok((bool) $php['proc_open']); ?> / shell_exec <?php echo $ok((bool) $php['shell_exec']); ?></td></tr>
                <tr><th>.env</th><td><?php echo $ok((bool) $environment['exists'] && (bool) $environment['writable']); ?> <?php echo $h($environment['path']); ?> / key <?php echo $ok((bool) $environment['key']); ?></td></tr>
                <tr><th>Modelo OpenCode</th><td><?php echo $h($environment['model']); ?></td></tr>
                <tr><th>opencode</th><td><?php echo $ok($opencode['binary'] !== null); ?> <?php echo $h($opencode['binary'] ?? 'no encontrado'); ?> <?php echo $h($opencode['version'] ?? ''); ?></td></tr>
                <tr><th>API key OpenCode</th><td><?php echo $ok($opencode['key'] === 'ok'); ?> <?php echo $h($opencode['key_masked']); ?></td></tr>
                <tr><th>Motor Whisper</th><td><?php echo $ok((bool) $whisper['ready']); ?> <?php echo $h($whisper['engine_label']); ?> (config: <?php echo $h($whisper['configured_engine']); ?>, idioma: <?php echo $h($whisper['language']); ?>)</td></tr>
                <tr><th>whisper.cpp</th><td><?php echo $optional($whisper['cpp']['binary'] !== null, $whisper['engine'] === 'whisper_cpp'); ?> <?php echo $h($whisper['cpp']['binary'] ?? 'no encontrado'); ?> <?php echo $h($whisper['cpp']['version'] ?? ''); ?><?php echo $whisper['engine'] === 'whisper_cpp' ? '' : ' (opcional)'; ?></td></tr>
                <tr><th>Modelo whisper.cpp</th><td><?php echo $modelState((bool) $whisper['cpp']['model_exists'], $whisper['engine'] === 'whisper_cpp'); ?> <?php echo $h($whisper['cpp']['model_file']); ?></td></tr>
                <tr><th>openai-whisper</th><td><?php echo $optional($whisper['openai']['binary'] !== null, $whisper['engine'] === 'openai_whisper'); ?> <?php echo $h($whisper['openai']['binary'] ?? 'no encontrado'); ?><?php echo $whisper['engine'] === 'openai_whisper' ? '' : ' (opcional)'; ?></td></tr>
                <tr><th>Modelo openai-whisper</th><td><?php echo $modelState((bool) $whisper['openai']['model_exists'], $whisper['engine'] === 'openai_whisper'); ?> <?php echo $h($whisper['openai']['model_file']); ?> <?php echo $whisper['openai']['model_exists'] ? '' : '(se descargara al usar)'; ?></td></tr>
                <tr><th>ffmpeg</th><td><?php echo $ok($ffmpeg['binary'] !== null); ?> <?php echo $h($ffmpeg['binary'] ?? 'no encontrado'); ?></td></tr>
                <tr><th>Carpeta de videos</th><td><?php echo $ok((bool) $video['ok']); ?> <?php echo $h($video['dir']); ?></td></tr>
                <tr><th>storage</th><td>tmp <?php echo $ok((bool) $storage['tmp']); ?> / runtime <?php echo $ok((bool) $storage['runtime']); ?> / modelos <?php echo $ok((bool) $storage['models']); ?></td></tr>
                <tr><th>Base de datos</th><td><?php echo $ok(($db['integrity'] ?? '') === 'ok'); ?> <?php echo $h($db['path']); ?> / lecciones: <?php echo (int) ($db['lessons'] ?? 0); ?> / transcritas: <?php echo (int) ($db['transcribed'] ?? 0); ?> / preguntas: <?php echo (int) ($db['questions'] ?? 0); ?> / intentos: <?php echo (int) ($db['attempts'] ?? 0); ?> / schema: <?php echo (int) ($db['schema_version'] ?? 0); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>
<script src="/assets/js/settings.js"></script>
