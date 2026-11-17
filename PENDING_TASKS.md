# Estado de Implementacion

Documentacion del proyecto en `README.md`. Aca se registra lo implementado y lo pendiente.

## Implementado

- [x] Arquitectura MVC con front controller y rutas limpias.
- [x] Separacion frontend (vistas + assets) / backend (Core, Controllers, Models, Services).
- [x] Cliente IA nativo de OpenCode (`Services/OpenCode`) sobre el CLI (`run --format json`),
      con prompt por archivo/redireccion, timeout, parseo JSONL y reintentos.
- [x] Secretos y modelo en `.env` (`App\Core\Env`), editables desde `/settings`.
- [x] Perfil aislado del CLI (`storage/runtime/opencode`, HOME/XDG, puerto propio) y
      sesiones archivadas (`--archived` cuando esta disponible).
- [x] Transcripcion local sin Python: ffmpeg + whisper.cpp (`Services/Whisper`,
      `Services/Ffmpeg`, modelos ggml en `storage/models/whisper`).
- [x] Descarga de modelos ggml (`bin/download-whisper-model.php`).
- [x] Workers PHP: `process_lessons.php`, `enrich_lesson.php`, `generate_exam.php`.
- [x] Enriquecimiento con explicacion + 5 ejercicios (esquema en prompt, normalizacion y reintentos).
- [x] Tutor IA conectado al contexto de la leccion (`TutorController`).
- [x] Ejercicios verificables en la UI.
- [x] Sistema de progreso: boton "Marcar como completada".
- [x] Panel de ajustes con pruebas de IA/Whisper y diagnostico del entorno.
- [x] Procesado por lotes que omite lo ya hecho y detecta videos nuevos de forma recursiva.
- [x] Soporte de niveles mediante subcarpetas (`4A`, `4B`, ...) con ruta relativa en
      `lessons.file_name` y streaming por `VideoController`.
- [x] Explicaciones y respuestas del tutor renderizadas como HTML (`Services/Markdown`).
- [x] Streaming en vivo (`SyncController::live`) en proceso, compatible Windows/Linux.
- [x] Boton "Leccion Aleatoria" en el indice (`GET /random`).
- [x] Seccion de examenes con banco de preguntas y scopes por leccion, por nivel y mixto.
- [x] Generacion del banco de examenes con IA (`generate_exam.php`).
- [x] Seleccion aleatoria de preguntas y de opciones (`Services/ExamBuilder`).
- [x] Correccion 100% server-side, con normalizacion de respuestas y umbral de aprobacion
      (`Services/ExamGrader`).
- [x] Escritura libre corregida con IA + rubrica, con fallback si la IA no esta disponible.
- [x] Historial de intentos y pagina de resultados con detalle y feedback.
- [x] Tablas `exam_questions` y `exam_attempts` + migracion (`bin/migrate_exams.php`) e
      instalacion en `bin/setup.php`.
- [x] Ajustes de examen en `/settings` (umbral de aprobacion y cantidad de preguntas).
- [x] Frontend responsive (meta viewport, grid fluido, tablas con scroll, botones que envuelven).
- [x] Workers de IA/Whisper en segundo plano con log y PID (`Services/ProcessRunner`),
      interprete PHP configurable (`paths.php`) y estado/errores visibles en `/exams` y `/sync`.
- [x] Diagnostico `bin/doctor.php` (PHP, extensiones, `.env`, OpenCode, ffmpeg, whisper,
      modelo, permisos, integridad y conteos de la BD).
- [x] Carpeta de videos configurable desde `/settings`.
- [x] Limpieza de locks de workers en Windows (PID 0) cuando el log deja de cambiar.
- [x] Datos de runtime desversionados: `data/lessons.db`, `config/ai_config.json`, `.env`,
      `storage/bin`, `storage/models` y `storage/runtime` ya no estan en git.
- [x] Motor de Whisper seleccionable: `auto`, `whisper.cpp` nativo u
      `openai-whisper` (Python) para reutilizar instalaciones existentes.
- [x] Eliminado el backend Python/Ollama/OpenClaw del flujo principal: la IA es
      nativa y la transcripcion usa whisper.cpp; openai-whisper queda como
      fallback opcional configurable.

## Pendiente

- [ ] Correr `php bin/migrate_exams.php` en el deploy (o automatizarlo en `start_server.sh`).
- [ ] Boton "Generar examen" por leccion dentro de `/sync`.
- [ ] Corregir los ejercicios de la leccion en el servidor (hoy la respuesta viaja en el
      HTML: `frontend/views/lessons/show.php` y `public/assets/js/lesson.js`).
- [ ] Validar el despliegue completo en Ubuntu (ffmpeg + whisper.cpp + OpenCode + permisos
      de `www-data`) y medir tiempos reales por video.
- [ ] Una vez validado whisper.cpp en Ubuntu, cambiar `whisper.engine` a `whisper_cpp`
      y retirar el fallback de Python si ya no se usa.
- [ ] Limpieza de `storage/tmp` y de intentos `exam_attempts` con estado `in_progress` huerfanos.
- [ ] Opcional: modo de inferencia directa por HTTP (`/inference/go`) para reducir la
      latencia del tutor frente al CLI.
