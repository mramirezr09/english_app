# Plataforma de Lecciones de Ingles

Aplicacion web (PHP 8 + SQLite) que convierte videos de clase en lecciones
interactivas: sincroniza los `.mp4`, los transcribe con **whisper.cpp** y los
enriquece con IA (explicacion en espanol + 5 ejercicios), con tutor IA por
leccion. Incluye una seccion de **examenes** autogenerados y corregidos en el
servidor, y **practica oral con microfono**: la IA te hace preguntas, escucha
tu respuesta (Whisper), te corrige palabra por palabra y te indica como
mejorar la pronunciacion.

Stack: PHP 8 (sin dependencias ni framework), SQLite, JavaScript vanilla,
**ffmpeg**, **whisper.cpp** (o `openai-whisper` opcional como fallback) y el
**CLI de OpenCode** (suscripcion OpenCode Go). Con whisper.cpp no requiere Python.

## Requisitos

- PHP 8.1+ con las extensiones `pdo_sqlite`, `curl` y (opcional) `mbstring`.
- **ffmpeg** para extraer el audio de los `.mp4`.
- **whisper.cpp** (`whisper-cli`) y un modelo `ggml-*.bin`, **o** el CLI de
  `openai-whisper` (Python) y sus modelos `.pt` ya instalados.
- **OpenCode CLI** instalado y una API key de OpenCode (`.env`).
- `proc_open` y `shell_exec` habilitados en PHP (los usan los workers y el cliente de IA).

### Windows (desarrollo)

```powershell
# ffmpeg (binario)
# https://www.gyan.dev/ffmpeg/builds/ -> ffmpeg-release-essentials.zip
# Descomprimir en storage/bin/ffmpeg/bin/ffmpeg.exe

# whisper.cpp (binario)
# https://github.com/ggml-org/whisper.cpp/releases
# -> whisper-bin-x64.zip; descomprimir en storage/bin/whisper/Release/

# modelo (141 MB)
php bin/download-whisper-model.php base

# OpenCode CLI
npm install -g @opencode/cli
```

### Ubuntu (produccion)

```bash
sudo apt install ffmpeg
# Opcion A (recomendada): whisper.cpp nativo
# https://github.com/ggml-org/whisper.cpp/releases -> whisper-bin-ubuntu-x64.tar.gz
mkdir -p storage/bin/whisper
tar -xzf whisper-bin-ubuntu-x64.tar.gz -C storage/bin/whisper --strip-components=1
chmod +x storage/bin/whisper/whisper-cli

# Opcion B (si ya tienes openai-whisper): no instales nada,
# deja "whisper.engine": "auto" y la app usara el comando `whisper`.

cd /var/www/english_app
php bin/download-whisper-model.php base   # solo para la opcion A

# OpenCode CLI
curl -fsSL https://opencode.ai/install | bash
# alternativa: sudo npm install -g @opencode/cli
```

## Arquitectura

Backend PHP sin dependencias con patron **MVC**, separado del frontend:

```
public/                       # Document root (unico expuesto por web)
  index.php                   # Front controller / router
  assets/css|js/              # CSS y JS del frontend
frontend/
  views/                      # Plantillas (layouts, lessons, sync, settings, exams)
backend/
  bootstrap.php               # Autoload PSR-4 + helpers + carga de .env
  routes.php                  # Tabla de rutas
  Core/                       # Router, Request, Response, View, Controller, Model, Config, Env
  Controllers/                # Home, Lesson, Sync, Settings, Tutor, Video, Exam, Speech
  Models/                     # Lesson, Exam (acceso a datos)
  Services/                   # AiConfig, AiService, OpenCode, Whisper, Ffmpeg, Shell,
                              #   Enricher, ExamGenerator, LessonPipeline, ProcessRunner,
                              #   ExamBuilder, ExamGrader, ExamProcessor, Markdown,
                              #   SpeechEvaluator
  helpers/                    # str_limit (UTF-8)
  workers/                    # Workers PHP: process_lessons, enrich_lesson, generate_exam
config/ai_config.json         # Configuracion local (rutas, timeouts, Whisper, examenes)
.env                          # Secretos: OPENCODE_API_KEY, OPENCODE_MODEL
data/lessons.db               # Base de datos SQLite
storage/
  bin/                        # ffmpeg / whisper-cli descargados (gitignored)
  models/whisper/             # modelos ggml (gitignored)
  runtime/opencode/           # HOME/XDG aislado del CLI (gitignored)
  tmp/                        # audios, logs y pruebas (gitignored)
bin/setup.php                 # Crea la base de datos y todas las tablas
bin/migrate_levels.php        # Prefija con el nivel las filas antiguas planas
bin/migrate_exams.php         # Crea las tablas de examenes en bases existentes
bin/doctor.php                # Diagnostico del entorno (PHP, binarios, .env, BD)
bin/test_speech.php           # Verifica la transcripcion de voz (Whisper + ffmpeg) sin navegador
bin/download-whisper-model.php# Descarga modelos ggml desde HuggingFace
```

### Modelo de datos

- `lessons`: id, file_name (ruta relativa con nivel), title, transcription, explanation,
  exercises (JSON), completed.
- `exam_questions`: banco de preguntas (lesson_id, level, type, prompt, options, answer,
  alt_answers, explanation, difficulty, source).
- `exam_attempts`: intentos de examen (scope, scope_ref, status, total, score, passed,
  paper, details, started_at, finished_at).

## Rutas

| Ruta | Descripcion |
|---|---|
| `GET /` | Indice de lecciones |
| `GET /random` | Redirige a una leccion aleatoria |
| `GET /lessons/{id}` | Detalle de la leccion (video, transcripcion, explicacion, ejercicios, tutor) |
| `GET /videos/{ruta}` | Streaming del video desde `paths.video_dir` (soporta HTTP Range) |
| `POST /api/lessons/{id}/complete` | Marca la leccion como completada |
| `POST /api/lessons/{id}/speech/evaluate` | Practica oral: recibe el audio del microfono (multipart), transcribe con Whisper y corrige con IA |
| `POST /api/lessons/{id}/speech/question` | Genera la pregunta de conversacion segun la leccion y el historial |
| `GET /lessons/{id}/reset` | Resetea transcripcion/explicacion/ejercicios |
| `POST /api/tutor` | Mensaje al tutor IA |
| `GET /exams` | Panel de examenes (banco, niveles, intentos) |
| `GET /exams/new?scope=&ref=` | Arma y muestra un examen (scope: `lesson`, `level`, `mixed`) |
| `GET /exams/generate?scope=&ref=` | Genera preguntas con IA en segundo plano |
| `GET /exams/results/{id}` | Resultado y detalle de un intento |
| `POST /api/exams/grade` | Corrige un examen en el servidor |
| `GET /sync` | Panel de sincronizacion y estado de requisitos |
| `GET /sync/process` | Procesa los videos pendientes en segundo plano |
| `GET /sync/process/{file}` | Procesa un archivo concreto |
| `GET /sync/re-enrich/{id}` | Regenera explicacion y ejercicios |
| `GET /sync/live?file=&whisper=` | Procesado en vivo (SSE, en proceso) |
| `GET\|POST /settings` | Ajustes (listar, guardar) |
| `GET /settings/models` | Lista de modelos de OpenCode (CLI) |
| `GET /settings/test-ai` | Prueba del backend de IA |
| `GET /settings/test-whisper?model=` | Prueba del motor Whisper activo con el ultimo audio |
| `GET /settings/doctor` | Diagnostico en JSON |

## Flujo de uso

1. Copiar los nuevos `.mp4` a una subcarpeta de `paths.video_dir` (p. ej. `<video_dir>/4A/`).
2. Abrir `/sync`: los videos nuevos se detectan automaticamente de forma recursiva.
3. Pulsar **Procesar Videos Pendientes** (o **Procesar en Vivo** por archivo).
   Solo se procesa lo pendiente; lo ya completado se omite.
4. Elegir el modelo Whisper en el propio panel si se desea.
5. Ver el resultado en `/lessons/{id}`: transcripcion, explicacion, ejercicios y tutor.
6. Para practica oral: en la leccion, pulsar **Responder con voz** en un ejercicio o
   **Iniciar conversacion** en "Practica Oral". Habla, detiene la grabacion y la app
   transcribe tu audio con Whisper, compara palabra por palabra con la respuesta
   esperada y la IA te explica en espanol que corregir. Requiere microfono y
   **HTTPS o localhost** (limitacion del navegador, no de la app).
7. Para examenes: abrir `/exams`, generar el banco con IA (por leccion o todas) y rendir
   un examen por leccion, por nivel o mixto.

## Configuracion

### `.env` (secretos y modelo)

```dotenv
OPENCODE_API_KEY=oc_sk_...
OPENCODE_MODEL=opencode-go/deepseek-v4.1-flash

# Opcionales
# OPENCODE_BIN=/usr/local/bin/opencode
# OPENCODE_SERVICE_PORT=28994
# WHISPER_ENGINE=auto
# WHISPER_BIN=/opt/whisper.cpp/build/bin/whisper-cli
# WHISPER_PY_BIN=/home/usuario/.local/bin/whisper
# WHISPER_LANGUAGE=English
# FFMPEG_BIN=/usr/bin/ffmpeg
# WHISPER_MODELS_DIR=storage/models/whisper
```

- Se edita desde `/settings` o directamente; la key se muestra enmascarada.
- El CLI de OpenCode usa un `HOME`/`XDG` aislado en `storage/runtime/opencode`
  para no mezclar las sesiones de la app con las del usuario interactivo.
- El servicio interno usa el puerto `28994` por defecto para no chocar con el
  servicio personal de OpenCode (`28894`).

### `config/ai_config.json`

- `paths.video_dir`: carpeta raiz de los videos (con subcarpetas por nivel).
- `paths.php`: binario de PHP que lanzan los workers (default `php`).
- `opencode.binary`, `opencode.timeout`, `opencode.archive_sessions`, `opencode.service_port`.
- `whisper.engine` (`auto`, `whisper_cpp`, `openai_whisper`), `whisper.model`
  (`tiny|base|small|medium|large-v3`), `whisper.speech_model` (modelo para la
  practica oral; `tiny` responde mas rapido), `whisper.language`, `whisper.english_only`,
  `whisper.binary` (whisper.cpp), `whisper.openai_binary` (CLI de Python),
  `whisper.models_dir`, `whisper.ffmpeg`.
- `exam.passing_score` (default 80) y `exam.questions` (default 12).

Los workers corren en segundo plano y dejan su salida en `storage/tmp/*.log`;
`/exams` y `/sync` muestran el estado y el log de cada uno.

### Motores de Whisper

- **`auto`** (default): usa whisper.cpp si encuentra `whisper-cli` y el modelo
  `ggml-*.bin`; si no, usa el CLI `whisper` de `openai-whisper` si esta
  instalado. Es la opcion recomendada para migrar sin cortar el servicio.
- **`whisper_cpp`**: obliga a whisper.cpp; si falta el binario o el modelo,
  falla con un mensaje claro.
- **`openai_whisper`**: obliga a usar el CLI de Python y sus modelos `.pt`
  (los que ya tenias en `~/.cache/whisper`). No descarga nada si ya estan.

En `/settings` puedes ver el estado de ambos motores y forzar el que quieras.
El comando de `openai-whisper` es el mismo que usaba el worker antiguo:
`whisper <wav> --model <modelo> --language English --output_format txt`.

## Diagnostico

```bash
php bin/doctor.php        # checks + resumen de la base
php bin/doctor.php --ai   # ademas envia un READY por OpenCode
php bin/doctor.php --json # salida para automatizar
php bin/test_speech.php   # pipeline de voz: ffmpeg + Whisper sobre un clip corto
```

Comprueba PHP y extensiones, `.env`, key, modelo, binario/version de OpenCode,
ffmpeg, ambos motores de Whisper y sus modelos, permisos de `storage/`,
`paths.video_dir` y la integridad/conteos de SQLite.

## Instalacion y migraciones

```bash
php bin/setup.php                 # Instalacion nueva: crea la BD y todas las tablas
php bin/migrate_levels.php 4A     # Migra filas antiguas planas a un nivel
php bin/migrate_exams.php         # Base existente: agrega las tablas de examenes
```

Los tres scripts son idempotentes (`CREATE TABLE/INDEX IF NOT EXISTS`).

> `data/lessons.db`, `config/ai_config.json` y `.env` son **locales de cada
> entorno** y no se versionan. Hay plantillas en `config/ai_config.example.json`
> y `.env.example`.

## Despliegue en Ubuntu

> En el servidor actual el proyecto esta en
> `/home/mauricio/.openclaw/workspace/english_app`; los ejemplos usan
> `/var/www/english_app`. Ajusta la ruta y el usuario del servicio.

1. **Respaldo antes de actualizar**

   ```bash
   sudo systemctl stop english_app
   cd /var/www/english_app
   cp data/lessons.db data/lessons.db.bak-$(date +%Y%m%d-%H%M)
   cp config/ai_config.json config/ai_config.json.bak-$(date +%Y%m%d-%H%M)
   ```

2. **Codigo y `.env`**

   ```bash
   git pull
   cp .env.example .env
   nano .env        # OPENCODE_API_KEY y OPENCODE_MODEL
   sudo chown www-data:www-data .env
   sudo chmod 600 .env
   ```

   No hace falta `opencode auth login` para `www-data`: el CLI se autentica con
   `OPENCODE_API_KEY`. El perfil aislado se crea en `storage/runtime/opencode`
   (debe ser escribible por el usuario del servicio).

3. **Permisos y binarios**

   ```bash
   sudo chown -R www-data:www-data storage
   sudo -u www-data php bin/doctor.php
   ```

   ffmpeg debe estar en el `PATH` (o configurar `WHISPER_BIN`/`FFMPEG_BIN`).
   whisper.cpp puede vivir en `/opt/whisper.cpp`; los modelos en
   `storage/models/whisper`.

4. **Arranque**

   ```bash
   sudo systemctl start english_app
   curl -I http://127.0.0.1:8082/
   ```

5. **Pruebas controladas** (en este orden)

   - Abrir `/` y una leccion existente: debe verse su contenido guardado.
   - `/settings` → **Probar IA** (`READY`) y **Probar Whisper**.
   - `Regenerar IA` en una leccion ya transcrita (no toca la transcripcion).
   - Procesar **un** video pendiente.
   - Generar **un** examen y rendirlo.

### Apache + HTTPS (practica oral)

El navegador solo habilita el microfono en **contexto seguro** (`localhost` o
HTTPS). Para practicar oral desde el celular u otra maquina de la red, sirve la
app por HTTPS con Apache y PHP-FPM (esto ademas evita el bloqueo de `php -S`,
que es de un solo proceso y congela el sitio mientras evalua un audio):

```bash
sudo apt install php8.2-fpm
sudo a2enmod ssl rewrite proxy_fcgi headers
sudo a2enconf php8.2-fpm

# Certificado propio para uso en red local (aceptalo una vez en el navegador):
sudo openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
  -keyout /etc/apache2/ssl/english_app.key \
  -out /etc/apache2/ssl/english_app.crt \
  -subj "/CN=tu-host-o-ip" -addext "subjectAltName=IP:192.168.1.50,DNS:english.local"
```

```apache
<VirtualHost *:443>
    ServerName english.local
    DocumentRoot /var/www/english_app/public

    <Directory /var/www/english_app/public>
        AllowOverride All
        Require all granted
    </Directory>

    SetHandler "proxy:unix:/run/php/php8.2-fpm.sock|fcgi://localhost"

    SSLEngine on
    SSLCertificateFile    /etc/apache2/ssl/english_app.crt
    SSLCertificateKeyFile /etc/apache2/ssl/english_app.key

    # El microfono debe estar permitido para la propia pagina.
    Header always set Permissions-Policy "microphone=(self)"
</VirtualHost>
```

El `AllowOverride All` activa `public/.htaccess` (front controller a
`index.php`). Con dominio real, `sudo certbot --apache` evita el certificado
propio. Verifica el pipeline de voz en el servidor sin navegador:

```bash
php bin/test_speech.php            # usa storage/tmp/lesson_audio.wav
php bin/test_speech.php clip.webm  # o cualquier grabacion corta
```

Si `whisper.engine` resuelve a openai-whisper (Python), la practica oral
funciona pero sin timings; con whisper.cpp se obtienen segmentos con tiempos
en Windows y Ubuntu.

### systemd

```ini
[Unit]
Description=English Lessons App
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/english_app
ExecStart=/usr/bin/php -S 0.0.0.0:8082 -t /var/www/english_app/public /var/www/english_app/public/index.php
Restart=always

[Install]
WantedBy=multi-user.target
```

Si usas Apache/php-fpm, revisa AppArmor/permissions para que el usuario del
servicio pueda ejecutar `opencode`, `ffmpeg`, `whisper-cli` (o `whisper` de
Python) y escribir en `storage/` y `storage/runtime/opencode/`.

## Notas

- Requiere PHP 8.1+ (usa `match`, tipos `mixed`, propiedades tipadas y `str_contains`).
- Python **no es necesario** con whisper.cpp. `openai-whisper` es un fallback
  opcional para reutilizar una instalacion existente mientras migras.
- Si falta `mbstring`, `backend/helpers/utf8.php` incluye un truncado UTF-8 alternativo.
- La correccion de examenes es 100% server-side; las respuestas no viajan al cliente.
- La app pide JSON a OpenCode mediante instrucciones + esquema en el prompt (el CLI
  no expone `response_format`); los normalizadores y reintentos viven en
  `Enricher` y `ExamGenerator`.
- Las sesiones que crea la app se archivan con `--archived` cuando el CLI lo soporta.
- El estado de implementacion y el backlog viven en `PENDING_TASKS.md`.
