# Plataforma de Lecciones de Ingles

Aplicacion web (PHP 8 + SQLite) que convierte videos de clase en lecciones
interactivas: sincroniza los `.mp4`, los transcribe con Whisper y los enriquece
con IA (explicacion en espanol + 5 ejercicios), con tutor IA por leccion.
Incluye una seccion de **examenes** autogenerados y corregidos en el servidor.

Stack: PHP 8 (sin dependencias ni framework), SQLite, JavaScript vanilla,
Python 3 (Whisper + cliente de IA) y un backend de IA (Ollama u OpenClaw).

## Requisitos

- PHP 8.0+ con la extension PDO SQLite.
- Python 3 con `ffmpeg` y Whisper (`pip install openai-whisper`) para transcribir.
- Un backend de IA accesible: Ollama (local o cloud) u OpenClaw CLI.
- Opcional: extension `mbstring` de PHP (si falta, se usa un truncado UTF-8 alternativo).

## Arquitectura

Backend PHP sin dependencias con patron **MVC**, separado del frontend:

```
public/                       # Document root (unico expuesto por web)
  index.php                   # Front controller / router
  .htaccess
  assets/css|js/              # CSS y JS del frontend
frontend/
  views/                      # Plantillas (layouts, lessons, sync, settings, exams)
backend/
  bootstrap.php               # Autoload PSR-4 + helpers
  routes.php                  # Tabla de rutas
  Core/                       # Router, Request, Response, View, Controller, Model, Config
  Controllers/                # Home, Lesson, Sync, Settings, Tutor, Video, Exam
  Models/                     # Lesson, Exam (acceso a datos)
  Services/                   # AiConfig, AiService, LessonProcessor, ExamBuilder,
                              #   ExamGrader, ExamProcessor, Ollama, Whisper, Markdown
  helpers/                    # str_limit (UTF-8)
  workers/                    # Scripts Python (ai_client, process_lessons, enrich_lesson,
                              #   generate_exam, whisper_test)
config/ai_config.json         # Configuracion de IA (paths.video_dir = carpeta con los niveles)
data/lessons.db               # Base de datos SQLite
storage/tmp/                  # Temporales (audio, logs, pruebas de Whisper)
bin/setup.php                 # Crea la base de datos y todas las tablas
bin/migrate_levels.php        # Prefija con el nivel las filas antiguas planas (ej: 4A/)
bin/migrate_exams.php         # Crea las tablas de examenes en bases existentes
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
| `GET /lessons/{id}/reset` | Resetea transcripcion/explicacion/ejercicios |
| `POST /api/tutor` | Mensaje al tutor IA |
| `GET /exams` | Panel de examenes (banco, niveles, intentos) |
| `GET /exams/new?scope=&ref=` | Arma y muestra un examen (scope: `lesson`, `level`, `mixed`) |
| `GET /exams/generate?scope=&ref=` | Genera preguntas con IA en segundo plano |
| `GET /exams/results/{id}` | Resultado y detalle de un intento |
| `POST /api/exams/grade` | Corrige un examen en el servidor |
| `GET /sync` | Panel de sincronizacion |
| `GET /sync/process` | Procesa los videos pendientes en segundo plano |
| `GET /sync/process/{file}` | Procesa un archivo concreto |
| `GET /sync/re-enrich/{id}` | Regenera explicacion y ejercicios |
| `GET /sync/live?file=&whisper=` | Procesado en vivo (SSE) |
| `GET\|POST /settings` | Ajustes (listar, guardar) |
| `GET /settings/models` | Lista de modelos Ollama |
| `GET /settings/test-ai` | Prueba del backend de IA |
| `GET /settings/test-whisper?model=` | Prueba de Whisper |

## Flujo de uso

1. Copiar los nuevos `.mp4` a una subcarpeta de `paths.video_dir` (p. ej. `<video_dir>/4A/`).
2. Abrir `/sync`: los videos nuevos se detectan automaticamente de forma recursiva.
3. Pulsar **Procesar Videos Pendientes** (o **Procesar en Vivo** por archivo).
   Solo se procesa lo pendiente; lo ya completado se omite.
4. Elegir el modelo Whisper en el propio panel si se desea.
5. Ver el resultado en `/lessons/{id}`: transcripcion, explicacion, ejercicios y tutor.
6. Para examenes: abrir `/exams`, generar el banco con IA (por leccion o todas) y rendir
   un examen por leccion, por nivel o mixto.

## Configuracion

- `/settings`: backend de IA (Ollama / OpenClaw), modelo Ollama (lista dinamica + campo
  libre para modelos *cloud* como `gemma4:31b-cloud`), modelo Whisper, timeouts y botones de prueba.
- `config/ai_config.json`: persistencia de esos ajustes. Secciones relevantes:
  - `paths.video_dir`: carpeta raiz de los videos (con subcarpetas por nivel).
  - `paths.python`: interprete usado por los workers Python (default `python3`).
  - `exam.passing_score` (default 80): puntaje minimo para aprobar.
  - `exam.questions` (default 12): cantidad de preguntas por examen.
- Los procesos de IA/Whisper corren en segundo plano y dejan su salida en
  `storage/tmp/*.log`; `/exams` y `/sync` muestran el estado y el log de cada uno.
- Por defecto se usa `gemma4:31b-cloud`: corre en los servidores de Ollama (suscripcion),
  asi que es rapido y no carga la CPU local (este equipo no tiene GPU).

## Instalacion y migraciones

```bash
php bin/setup.php                 # Instalacion nueva: crea la BD y todas las tablas
php bin/migrate_levels.php 4A     # Migra filas antiguas planas a un nivel
php bin/migrate_exams.php         # Base existente: agrega las tablas de examenes
```

Los tres scripts son idempotentes (`CREATE TABLE/INDEX IF NOT EXISTS`), por lo que
pueden ejecutarse mas de una vez sin romper datos.

> `data/lessons.db` y `config/ai_config.json` son **locales de cada entorno** y no se
> versionan. La base se crea con `php bin/setup.php` y la config se guarda desde
> `/settings`; hay una plantilla en `config/ai_config.example.json`.

## Despliegue

- `start_server.sh` arranca el servidor embebido con `public/` como document root.
- En produccion se usa el unit systemd `english_app.service` con:
  `php -S 0.0.0.0:8082 -t <proyecto>/public <proyecto>/public/index.php`.
- **Antes de arrancar**, en bases ya existentes correr `php bin/migrate_exams.php`.
- Los videos viven fuera del webroot y se sirven con `VideoController` (streaming con
  HTTP Range) leyendo `paths.video_dir`; no se usan enlaces simbolicos.

## Notas

- Requiere PHP 8.0+ (usa `match`, tipos `mixed` y propiedades tipadas).
- Si falta la extension `mbstring` de PHP, `backend/helpers/utf8.php` incluye un
  truncado UTF-8 alternativo.
- Los scripts Python resuelven sus rutas desde `PROJECT_ROOT` (dos niveles arriba de
  `backend/workers/`), asi que son portables.
- La correccion de examenes es 100% server-side; las respuestas no viajan al cliente.
- El estado de implementacion y el backlog viven en `PENDING_TASKS.md`.
