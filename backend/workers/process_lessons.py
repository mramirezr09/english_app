#!/usr/bin/env python3
"""Extract audio, transcribe with Whisper and enrich lessons with AI.

Usage:
    python3 process_lessons.py                 # process every video missing a transcript
    python3 process_lessons.py Regla_3.mp4     # process one file
    python3 process_lessons.py Regla_3.mp4 --whisper-model small
    python3 process_lessons.py Regla_3.mp4 --skip-enrich
"""

import argparse
import os
import sqlite3
import subprocess
import sys

WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(os.path.dirname(WORKER_DIR))
DB_PATH = os.path.join(PROJECT_ROOT, 'data', 'lessons.db')
TMP_DIR = os.path.join(PROJECT_ROOT, 'storage', 'tmp')

sys.path.insert(0, WORKER_DIR)
import ai_client  # noqa: E402


def ensure_tmp_dir():
    os.makedirs(TMP_DIR, exist_ok=True)


def list_videos(video_dir):
    """Return every .mp4 under video_dir as a forward-slash relative path."""
    files = []
    for root, _dirs, names in os.walk(video_dir):
        for name in names:
            if name.lower().endswith('.mp4'):
                rel = os.path.relpath(os.path.join(root, name), video_dir)
                files.append(rel.replace(os.sep, '/'))
    return sorted(files)


def extract_audio(video_path, audio_out):
    print(f'Extrayendo audio de {os.path.basename(video_path)}...')
    cmd = [
        'ffmpeg', '-y', '-i', video_path, '-vn',
        '-acodec', 'pcm_s16le', '-ar', '16000', '-ac', '1', audio_out,
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        print('Error de ffmpeg:')
        print((result.stderr or '').strip()[-800:])
        return None
    return audio_out


def transcribe_audio(audio_path, model):
    print(f'Iniciando transcripcion con Whisper (modelo: {model})...')
    output_dir = os.path.dirname(audio_path)
    cmd = [
        'whisper', audio_path, '--model', model, '--language', 'English',
        '--output_dir', output_dir, '--output_format', 'txt', '--verbose', 'False',
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        print('Error de Whisper:')
        print((result.stderr or '').strip()[-800:])

    txt_file = os.path.splitext(audio_path)[0] + '.txt'
    if os.path.exists(txt_file):
        with open(txt_file, encoding='utf-8') as fh:
            text = fh.read().strip()
        if text:
            print(f'Transcripcion completada ({len(text.split())} palabras).')
            return text

    print('Error: Whisper no genero texto.')
    return None


def save_transcription(file_name, transcription):
    conn = sqlite3.connect(DB_PATH)
    conn.execute(
        'UPDATE lessons SET transcription = ? WHERE file_name = ?',
        (transcription, file_name),
    )
    conn.commit()
    conn.close()


def lesson_id_for(file_name):
    conn = sqlite3.connect(DB_PATH)
    row = conn.execute('SELECT id FROM lessons WHERE file_name = ?', (file_name,)).fetchone()
    conn.close()
    return row[0] if row else None


def enrich(lesson_id):
    print('Iniciando enriquecimiento de la leccion (IA)...')
    result = subprocess.run(
        ['python3', os.path.join(WORKER_DIR, 'enrich_lesson.py'), str(lesson_id), '--force']
    )
    if result.returncode != 0:
        print(f'Aviso: el enriquecimiento de la leccion {lesson_id} fallo.')


def lesson_state(file_name):
    conn = sqlite3.connect(DB_PATH)
    row = conn.execute(
        'SELECT id, transcription, explanation FROM lessons WHERE file_name = ?',
        (file_name,),
    ).fetchone()
    conn.close()
    return row


def process_file(file_name, video_dir, model, do_enrich=True, force=False):
    print(f'--- Iniciando proceso para: {file_name} ---')

    state = lesson_state(file_name)
    lesson_id = state[0] if state else None
    has_transcription = bool(state and state[1])
    has_explanation = bool(state and state[2])

    if not force and has_transcription and has_explanation:
        print('Ya procesado (transcripcion + IA). Omitiendo. Usa --force para regenerar.')
        return True

    video_path = os.path.join(video_dir, file_name)
    if not os.path.exists(video_path):
        print(f'Error: el archivo {video_path} no existe.')
        return False

    if not force and has_transcription:
        print('La transcripcion ya existe; solo falta la IA.')
    else:
        ensure_tmp_dir()
        audio_out = os.path.join(TMP_DIR, 'lesson_audio.wav')

        audio_path = extract_audio(video_path, audio_out)
        if not audio_path:
            return False

        text = transcribe_audio(audio_path, model)
        if not text:
            return False

        save_transcription(file_name, text)
        print('Transcripcion guardada en la base de datos.')

        if lesson_id is None:
            lesson_id = lesson_id_for(file_name)

    if do_enrich and lesson_id:
        enrich(lesson_id)

    return True


def main():
    parser = argparse.ArgumentParser(description='Procesa videos de ingles.')
    parser.add_argument('file', nargs='?', help='Archivo mp4 especifico a procesar.')
    parser.add_argument('--whisper-model', help='Sobrescribe el modelo Whisper del config.')
    parser.add_argument('--skip-enrich', action='store_true', help='Solo transcribir.')
    parser.add_argument('--force', action='store_true', help='Reprocesar aunque ya este completo.')
    args = parser.parse_args()

    config = ai_client.load_config()
    video_dir = config.get('paths', {}).get('video_dir')
    if not video_dir or not os.path.isdir(video_dir):
        print(f'Error: video_dir invalido: {video_dir}')
        return 1

    model = args.whisper_model or config.get('whisper', {}).get('model', 'base')

    if args.file:
        ok = process_file(args.file, video_dir, model, do_enrich=not args.skip_enrich, force=args.force)
        print(f"RESULTADO: {args.file} {'procesado' if ok else 'fallo'}.")
        return 0 if ok else 1

    files = list_videos(video_dir)
    if not files:
        print(f'No se encontraron videos en {video_dir}.')
        return 0

    pending = 0
    for file_name in files:
        before = lesson_state(file_name)
        if not args.force and before and before[1] and before[2]:
            print(f'Omitiendo {file_name} (ya procesado).')
            continue
        pending += 1
        process_file(file_name, video_dir, model, do_enrich=not args.skip_enrich, force=args.force)

    if pending == 0:
        print('No hay videos pendientes por procesar.')
    else:
        print(f'Todos los archivos pendientes han sido procesados ({pending}).')
    return 0


if __name__ == '__main__':
    sys.exit(main())
