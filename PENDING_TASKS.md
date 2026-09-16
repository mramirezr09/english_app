# Plataforma de Lecciones de Ingles - Estado

Aplicacion web (PHP 8 + SQLite) que convierte videos de clase en lecciones
interactivas: sincroniza los `.mp4`, los transcribe con Whisper y los enriquece
con IA (explicacion en espanol + 5 ejercicios), con tutor IA por leccion.

## Arquitectura

Backend PHP sin dependencias con patron **MVC**, separado del frontend:

```
public/                 # Document root (unico expuesto por web)
  index.php             # Front controller / router
  assets/css|js/        # CSS y JS del frontend
frontend/
  views/                # Plantillas (layouts, lessons, sync, settings)
backend/
  bootstrap.php         # Autoload PSR-4 + helpers
  routes.php            # Tabla de rutas
  Core/                 # Router, Request, Response, View, Controller, Model, Config
  Controllers/          # Home, Lesson, Sync, Settings, Tutor
  Models/               # Lesson (acceso a datos)
  Services/             # AiConfig, AiService, LessonProcessor, Ollama, Whisper, Markdown
  helpers/              # str_limit
  workers/              # Scripts Python (ai_client, process_lessons, enrich_lesson, whisper_test)
config/ai_config.json   # Configuracion de IA (paths.video_dir = carpeta con los niveles)
data/lessons.db         # Base de datos SQLite
storage/tmp/            # Temporales (audio, logs, pruebas de Whisper)
bin/setup.php           # Crea la base de datos y la tabla
bin/migrate_levels.php  # Prefija con el nivel las filas antiguas planas (ej: 4A/)
```

### Rutas

| Ruta | Descripcion |
|---|---|
| `GET /` | Indice de lecciones |
| `GET /lessons/{id}` | Detalle de la leccion (video, transcripcion, ejercicios, tutor) |
| `GET /videos/{ruta}` | Streaming del video desde `paths.video_dir` (soporta HTTP Range) |
| `POST /api/lessons/{id}/complete` | Marca la leccion como completada |
| `GET /lessons/{id}/reset` | Resetea transcripcion/explicacion/ejercicios |
| `POST /api/tutor` | Mensaje al tutor IA |
| `GET /sync` | Panel de sincronizacion |
| `GET /sync/process` | Procesa los videos pendientes en segundo plano |
| `GET /sync/process/{file}` | Procesa un archivo concreto |
| `GET /sync/re-enrich/{id}` | Regenera explicacion y ejercicios |
| `GET /sync/live?file=&whisper=` | Procesado en vivo (SSE) |
| `GET|POST /settings` | Ajustes (listar, guardar) |
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

## Configuracion
- `/settings`: backend de IA (Ollama / OpenClaw), modelo Ollama (lista dinamica + campo
  libre para modelos *cloud* como `gemma4:31b-cloud`), modelo Whisper, timeouts y botones de prueba.
- `config/ai_config.json`: persistencia de esos ajustes.
- Por defecto se usa `gemma4:31b-cloud`: corre en los servidores de Ollama (suscripcion),
  asi que es rapido y no carga la CPU local (este equipo no tiene GPU).

## Despliegue
- `start_server.sh` arranca el servidor embebido con `public/` como document root.
- En produccion se usa el unit systemd `english_app.service` con:
  `php -S 0.0.0.0:8082 -t <proyecto>/public <proyecto>/public/index.php`.
- Los videos viven fuera del webroot y se sirven con `VideoController` (streaming con
  HTTP Range) leyendo `paths.video_dir`; no se usan enlaces simbolicos.
- Crear la base de datos con `php bin/setup.php`.
- Migrar filas antiguas (sin nivel) con `php bin/migrate_levels.php 4A`.

## Implementado
- [x] Arquitectura MVC con front controller y rutas limpias.
- [x] Separacion frontend (vistas + assets) / backend (Core, Controllers, Models, Services).
- [x] Cliente IA unico (`backend/workers/ai_client.py`) con backend Ollama y OpenClaw.
- [x] Enriquecimiento con explicacion + 5 ejercicios (JSON con esquema forzado y reintentos).
- [x] Tutor IA conectado de verdad al contexto de la leccion (`TutorController`).
- [x] Ejercicios verificables en la UI.
- [x] Sistema de progreso: boton "Marcar como completada".
- [x] Panel de ajustes con pruebas de conexion y de Whisper.
- [x] Procesado por lotes que omite lo ya hecho y detecta videos nuevos de forma recursiva.
- [x] Soporte de niveles mediante subcarpetas (`4A`, `4B`, ...) con ruta relativa en
  `lessons.file_name` y streaming por `VideoController`.
- [x] Explicaciones y respuestas del tutor renderizadas como HTML (`Services/Markdown`).
- [x] Streaming en vivo (`SyncController::live`).

## Notas
- Requiere PHP 8.0+ (usa `match`, tipos `mixed` y propiedades tipadas).
- Si falta la extension `mbstring` de PHP, `backend/helpers/utf8.php` incluye un
  truncado UTF-8 alternativo.
- Los scripts Python resuelven sus rutas desde `PROJECT_ROOT` (dos niveles arriba de
  `backend/workers/`), asi que son portables.
