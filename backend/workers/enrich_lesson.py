#!/usr/bin/env python3
"""Generate a Spanish explanation and exercises for a lesson using the AI client."""

import json
import os
import re
import sqlite3
import sys

WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(os.path.dirname(WORKER_DIR))
DB_PATH = os.path.join(PROJECT_ROOT, 'data', 'lessons.db')

sys.path.insert(0, WORKER_DIR)
import ai_client  # noqa: E402

EXERCISES_SCHEMA = {
    'type': 'object',
    'properties': {
        'exercises': {
            'type': 'array',
            'items': {
                'type': 'object',
                'properties': {
                    'question': {'type': 'string'},
                    'answer': {'type': 'string'},
                },
                'required': ['question', 'answer'],
            },
        },
    },
    'required': ['exercises'],
}


def extract_json_array(text):
    """Best-effort extraction of a JSON array from an LLM response."""
    if not text:
        return None

    cleaned = text.replace('```json', '').replace('```', '').strip()

    def pick(value):
        if isinstance(value, list):
            return value
        if isinstance(value, dict):
            if 'question' in value or 'pregunta' in value:
                return [value]
            for item in value.values():
                if isinstance(item, list):
                    return item
        return None

    try:
        result = pick(json.loads(cleaned))
        if result is not None:
            return result
    except ValueError:
        pass

    # Fallback: find the first balanced [ ... ] block.
    start = cleaned.find('[')
    if start == -1:
        return None
    depth = 0
    for index in range(start, len(cleaned)):
        char = cleaned[index]
        if char == '[':
            depth += 1
        elif char == ']':
            depth -= 1
            if depth == 0:
                try:
                    return pick(json.loads(cleaned[start:index + 1]))
                except ValueError:
                    return None
    return None


def normalize_exercises(items):
    exercises = []
    if not isinstance(items, list):
        return exercises
    for item in items:
        if not isinstance(item, dict):
            continue
        question = item.get('question') or item.get('pregunta')
        answer = item.get('answer') or item.get('respuesta')
        if question and answer is not None:
            exercises.append({'question': str(question), 'answer': str(answer)})
    return exercises


def enrich_lesson(lesson_id, transcription, force=False):
    print(f'Enriqueciendo leccion {lesson_id}...')

    if not force:
        conn = sqlite3.connect(DB_PATH)
        row = conn.execute(
            'SELECT explanation, exercises FROM lessons WHERE id = ?', (lesson_id,)
        ).fetchone()
        conn.close()
        if row and row[0] and row[1]:
            print('Ya enriquecida. Usa --force para regenerar.')
            return True

    explanation_prompt = (
        'Eres un profesor de ingles experto que ensena a hispanohablantes. '
        'A partir de la transcripcion de la clase, redacta una explicacion '
        'pedagogica clara Y EN ESPANOL sobre la regla gramatical o el vocabulario '
        'del video. Usa ejemplos tomados de la transcripcion y separa el contenido '
        'en secciones cortas. No inventes contenido que no aparezca en la clase. '
        'Formatea la respuesta en Markdown simple: encabezados con "###", negritas '
        'con "**texto**" y listas con "- ". No uses LaTeX ni el simbolo "$"; para '
        'flechas escribe el caracter "->".\n\n'
        f'TRANSCRIPCION:\n{transcription}'
    )

    explanation = None
    for attempt in range(3):
        explanation = ai_client.ask_ai(explanation_prompt)
        if explanation:
            break
        print(f'Intento {attempt + 1}: explicacion vacia, reintentando...', flush=True)

    if not explanation:
        print('Aviso: no se pudo generar la explicacion.', flush=True)

    exercises_prompt = (
        'Eres un profesor de ingles experto. A partir de la transcripcion, crea '
        'exactamente 5 ejercicios para practicar la regla de la clase. '
        'Devuelve un objeto JSON con la clave "exercises", cuyo valor sea un arreglo '
        'de 5 objetos. Cada objeto debe tener las claves "question" (en espanol) y '
        '"answer" (en ingles). Usa comillas dobles.\n\n'
        f'TRANSCRIPCION:\n{transcription}'
    )

    exercises = []
    for attempt in range(3):
        raw = ai_client.ask_ai(exercises_prompt, schema=EXERCISES_SCHEMA)
        exercises = normalize_exercises(extract_json_array(raw))
        if len(exercises) >= 3:
            break
        print(f'Intento {attempt + 1}: ejercicios insuficientes, reintentando...', flush=True)

    if not exercises:
        print('Aviso: no se pudieron generar ejercicios validos.', flush=True)

    if explanation is None and not exercises:
        print('Error: la IA no devolvio ni explicacion ni ejercicios.')
        return False

    # Keep the previous value when only one part could be generated.
    conn = sqlite3.connect(DB_PATH)
    if explanation is not None and exercises:
        conn.execute(
            'UPDATE lessons SET explanation = ?, exercises = ? WHERE id = ?',
            (explanation, json.dumps(exercises, ensure_ascii=False), lesson_id),
        )
    elif explanation is not None:
        conn.execute('UPDATE lessons SET explanation = ? WHERE id = ?', (explanation, lesson_id))
    else:
        conn.execute(
            'UPDATE lessons SET exercises = ? WHERE id = ?',
            (json.dumps(exercises, ensure_ascii=False), lesson_id),
        )
    conn.commit()
    conn.close()

    print('Leccion enriquecida exitosamente.')
    return True


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    force = '--force' in sys.argv

    if not args:
        print('Uso: python3 enrich_lesson.py <lesson_id> [--force]')
        return 1

    lesson_id = int(args[0])
    conn = sqlite3.connect(DB_PATH)
    row = conn.execute(
        'SELECT transcription FROM lessons WHERE id = ?', (lesson_id,)
    ).fetchone()
    conn.close()

    if not row or not row[0]:
        print(f'La leccion {lesson_id} no tiene transcripcion todavia.')
        return 1

    return 0 if enrich_lesson(lesson_id, row[0], force=force) else 1


if __name__ == '__main__':
    sys.exit(main())
