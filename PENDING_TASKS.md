# Estado de Implementacion

Documentacion del proyecto en `README.md`. Aca se registra lo implementado y lo pendiente.

## Implementado

- [x] Arquitectura MVC con front controller y rutas limpias.
- [x] Separacion frontend (vistas + assets) / backend (Core, Controllers, Models, Services).
- [x] Cliente IA unico (`backend/workers/ai_client.py`) con backend Ollama y OpenClaw.
- [x] Enriquecimiento con explicacion + 5 ejercicios (JSON con esquema forzado y reintentos).
- [x] Tutor IA conectado al contexto de la leccion (`TutorController`).
- [x] Ejercicios verificables en la UI.
- [x] Sistema de progreso: boton "Marcar como completada".
- [x] Panel de ajustes con pruebas de conexion y de Whisper.
- [x] Procesado por lotes que omite lo ya hecho y detecta videos nuevos de forma recursiva.
- [x] Soporte de niveles mediante subcarpetas (`4A`, `4B`, ...) con ruta relativa en
  `lessons.file_name` y streaming por `VideoController`.
- [x] Explicaciones y respuestas del tutor renderizadas como HTML (`Services/Markdown`).
- [x] Streaming en vivo (`SyncController::live`).
- [x] Boton "Leccion Aleatoria" en el indice (`GET /random`).
- [x] Seccion de examenes con banco de preguntas y scopes por leccion, por nivel y mixto.
- [x] Generacion del banco de examenes con IA (`backend/workers/generate_exam.py`).
- [x] Seleccion aleatoria de preguntas y de opciones (`Services/ExamBuilder`).
- [x] Correccion 100% server-side, con normalizacion de respuestas y umbral de aprobacion
  (`Services/ExamGrader`).
- [x] Escritura libre corregida con IA + rubrica, con fallback si la IA no esta disponible.
- [x] Historial de intentos y pagina de resultados con detalle y feedback.
- [x] Tablas `exam_questions` y `exam_attempts` + migracion (`bin/migrate_exams.php`) e
  instalacion en `bin/setup.php`.
- [x] Ajustes de examen en `/settings` (umbral de aprobacion y cantidad de preguntas).
- [x] Frontend responsive (meta viewport, grid fluido, tablas con scroll, botones que envuelven).

## Pendiente

- [ ] Correr `php bin/migrate_exams.php` en el deploy (o automatizarlo en `start_server.sh`).
- [ ] Boton "Generar examen" por leccion dentro de `/sync`.
- [ ] Corregir los ejercicios de la leccion en el servidor (hoy la respuesta viaja en el
  HTML: `frontend/views/lessons/show.php` y `public/assets/js/lesson.js`).
- [ ] Probar la generacion de examenes con IA en el server Linux (requiere Python + Ollama/OpenClaw).
- [ ] Limpieza de `storage/tmp` e intentos `exam_attempts` con estado `in_progress` huerfanos.
