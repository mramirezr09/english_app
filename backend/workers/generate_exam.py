#!/usr/bin/env python3
"""Generate a bank of exam questions for a lesson using the AI client.

Usage:
    python3 generate_exam.py --lesson 3
    python3 generate_exam.py --lesson 3 --force
    python3 generate_exam.py --all [--force]
    python3 generate_exam.py --lesson 3 --count 15
"""

import argparse
import json
import os
import sqlite3
import sys
from datetime import datetime

WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(os.path.dirname(WORKER_DIR))
DB_PATH = os.path.join(PROJECT_ROOT, 'data', 'lessons.db')

sys.path.insert(0, WORKER_DIR)
import ai_client  # noqa: E402

ALLOWED_TYPES = {'mcq', 'truefalse', 'cloze', 'order', 'translation', 'open'}

QUESTIONS_SCHEMA = {
    'type': 'object',
    'properties': {
        'questions': {
            'type': 'array',
            'items': {
                'type': 'object',
                'properties': {
                    'type': {'type': 'string', 'enum': sorted(ALLOWED_TYPES)},
                    'prompt': {'type': 'string'},
                    'options': {'type': 'array', 'items': {'type': 'string'}},
                    'answer': {'type': 'string'},
                    'alt_answers': {'type': 'array', 'items': {'type': 'string'}},
                    'explanation': {'type': 'string'},
                    'difficulty': {'type': 'integer'},
                },
                'required': ['type', 'prompt', 'answer'],
            },
        },
    },
    'required': ['questions'],
}


def extract_questions(text):
    """Best-effort extraction of a list of question dicts from an LLM reply."""
    if not text:
        return []

    cleaned = text.replace('```json', '').replace('```', '').strip()

    def pick(value):
        if isinstance(value, dict):
            for key in ('questions', 'preguntas'):
                if isinstance(value.get(key), list):
                    return value[key]
            for item in value.values():
                if isinstance(item, list):
                    return item
        if isinstance(value, list):
            return value
        return None

    try:
        result = pick(json.loads(cleaned))
        if result is not None:
            return result
    except ValueError:
        pass

    start = cleaned.find('[')
    if start == -1:
        return []
    depth = 0
    for index in range(start, len(cleaned)):
        char = cleaned[index]
        if char == '[':
            depth += 1
        elif char == ']':
            depth -= 1
            if depth == 0:
                try:
                    return pick(json.loads(cleaned[start:index + 1])) or []
                except ValueError:
                    return []
    return []


def normalize_question(item):
    """Validate and normalize a single question, or return None if unusable."""
    if not isinstance(item, dict):
        return None

    qtype = str(item.get('type', '')).lower().strip()
    if qtype not in ALLOWED_TYPES:
        return None

    prompt = str(item.get('prompt', '')).strip()
    answer = item.get('answer', '')
    if not prompt or answer is None:
        return None
    answer = str(answer).strip()
    if not answer:
        return None

    raw_options = item.get('options') if isinstance(item.get('options'), list) else []
    options = [str(o).strip() for o in raw_options if str(o).strip()]
    raw_alt = item.get('alt_answers') if isinstance(item.get('alt_answers'), list) else []
    alt = [str(o).strip() for o in raw_alt if str(o).strip()]
    explanation = str(item.get('explanation', '')).strip()

    try:
        difficulty = int(item.get('difficulty', 1))
    except (TypeError, ValueError):
        difficulty = 1
    difficulty = max(1, min(3, difficulty))

    if qtype == 'mcq':
        if len(options) < 2:
            return None
        if not any(answer.lower() == option.lower() for option in options):
            return None
    elif qtype == 'truefalse':
        low = answer.lower()
        if low in ('true', 't', 'verdadero', 'si', 'yes'):
            answer = 'true'
        elif low in ('false', 'f', 'falso', 'no'):
            answer = 'false'
        else:
            return None
        options = []
    elif qtype == 'order':
        if not options:
            options = answer.split()
        if len(options) < 2:
            return None
    else:
        options = []

    return {
        'type': qtype,
        'prompt': prompt,
        'options': options,
        'answer': answer,
        'alt': alt,
        'explanation': explanation,
        'difficulty': difficulty,
    }


def lesson_level(file_name):
    level = os.path.dirname(file_name or '').replace('\\', '/').strip('/')
    return level or None


def replace_questions(lesson_id, level, questions):
    conn = sqlite3.connect(DB_PATH)
    conn.execute(
        "DELETE FROM exam_questions WHERE lesson_id = ? AND source = 'ai'", (lesson_id,)
    )
    now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    for q in questions:
        options = json.dumps(q['options'], ensure_ascii=False) if q['options'] else None
        alt = json.dumps(q['alt'], ensure_ascii=False) if q['alt'] else None
        conn.execute(
            'INSERT INTO exam_questions '
            '(lesson_id, level, type, prompt, options, answer, alt_answers, explanation, difficulty, source, created_at) '
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            (
                lesson_id, level, q['type'], q['prompt'], options, q['answer'],
                alt, q['explanation'], q['difficulty'], 'ai', now,
            ),
        )
    conn.commit()
    conn.close()


def existing_count(lesson_id):
    conn = sqlite3.connect(DB_PATH)
    row = conn.execute(
        'SELECT COUNT(*) FROM exam_questions WHERE lesson_id = ?', (lesson_id,)
    ).fetchone()
    conn.close()
    return row[0] if row else 0


def build_prompt(title, transcription, count):
    return (
        'Eres un profesor de ingles experto que evalua a hispanohablantes. '
        f'A partir de la transcripcion de la clase, crea exactamente {count} preguntas '
        'variadas para evaluar la regla gramatical y el vocabulario del video.\n\n'
        'Distribucion sugerida de tipos:\n'
        '- "mcq": opcion multiple con 4 opciones en "options"; "answer" debe ser la opcion correcta.\n'
        '- "truefalse": verdadero/falso; "answer" debe ser "true" o "false".\n'
        '- "cloze": completar una oracion; "prompt" incluye un espacio en blanco identificado con "____".\n'
        '- "order": ordenar palabras; incluye las palabras en "options" y la oracion correcta en "answer".\n'
        '- "translation": traducir una frase; "prompt" en espanol y "answer" en ingles.\n'
        '- "open": pregunta abierta de produccion escrita; "answer" es una respuesta de referencia.\n\n'
        'Reglas: una sola respuesta correcta, sin pistas en las opciones, sin preguntas trampa. '
        'Agrega "explanation" breve en espanol que justifique la respuesta. '
        'Usa "alt_answers" para variantes validas cuando aplique. '
        'Devuelve un objeto JSON con la clave "questions".\n\n'
        f'TITULO: {title}\n\n'
        f'TRANSCRIPCION:\n{transcription}'
    )


def generate_for_lesson(lesson_id, count, force=False):
    conn = sqlite3.connect(DB_PATH)
    row = conn.execute(
        'SELECT transcription, title, file_name FROM lessons WHERE id = ?', (lesson_id,)
    ).fetchone()
    conn.close()

    if not row or not row[0]:
        print(f'La leccion {lesson_id} no tiene transcripcion todavia.')
        return False

    transcription, title, file_name = row
    title = title or f'Lesson {lesson_id}'
    level = lesson_level(file_name)

    if not force and existing_count(lesson_id) > 0:
        print(f'La leccion {lesson_id} ya tiene preguntas. Usa --force para regenerar.')
        return True

    print(f'Generando {count} preguntas para la leccion {lesson_id} ({title})...')
    prompt = build_prompt(title, transcription, count)

    questions = []
    for attempt in range(3):
        raw = ai_client.ask_ai(prompt, schema=QUESTIONS_SCHEMA)
        items = extract_questions(raw)
        questions = [q for q in (normalize_question(i) for i in items) if q]
        if len(questions) >= max(3, count // 2):
            break
        print(f'Intento {attempt + 1}: solo {len(questions)} preguntas validas, reintentando...', flush=True)

    if not questions:
        print(f'Error: la IA no devolvio preguntas validas para la leccion {lesson_id}.')
        return False

    replace_questions(lesson_id, level, questions)
    print(f'Leccion {lesson_id}: {len(questions)} preguntas guardadas (nivel: {level or "sin nivel"}).')
    return True


def main():
    parser = argparse.ArgumentParser(description='Genera preguntas de examen con IA.')
    parser.add_argument('--lesson', type=int, help='ID de la leccion a procesar.')
    parser.add_argument('--all', action='store_true', help='Procesa todas las lecciones con transcripcion.')
    parser.add_argument('--force', action='store_true', help='Regenera aunque ya existan preguntas.')
    parser.add_argument('--count', type=int, help='Cantidad de preguntas por leccion.')
    args = parser.parse_args()

    config = ai_client.load_config()
    count = args.count or int(config.get('exam', {}).get('questions', 12))
    count = max(3, min(50, count))

    if args.all:
        conn = sqlite3.connect(DB_PATH)
        rows = conn.execute(
            "SELECT id FROM lessons WHERE transcription IS NOT NULL AND transcription <> '' ORDER BY id"
        ).fetchall()
        conn.close()
        if not rows:
            print('No hay lecciones con transcripcion.')
            return 0
        ok = 0
        for (lesson_id,) in rows:
            if generate_for_lesson(lesson_id, count, force=args.force):
                ok += 1
        print(f'Lecciones procesadas: {ok}/{len(rows)}.')
        return 0

    if args.lesson is None:
        parser.error('Indica --lesson <id> o --all.')

    return 0 if generate_for_lesson(args.lesson, count, force=args.force) else 1


if __name__ == '__main__':
    sys.exit(main())
