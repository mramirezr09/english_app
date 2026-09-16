#!/usr/bin/env python3
"""Transcribe the sample audio (tmp/lesson_audio.wav) with a given Whisper model.

Prints a single JSON object so the settings page can compare models.

Usage: python3 whisper_test.py <model>
"""

import json
import os
import subprocess
import sys
import time

WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(os.path.dirname(WORKER_DIR))
TMP_DIR = os.path.join(PROJECT_ROOT, 'storage', 'tmp')
AUDIO = os.path.join(TMP_DIR, 'lesson_audio.wav')


def main():
    if len(sys.argv) < 2:
        print(json.dumps({'ok': False, 'error': 'Falta el modelo.'}))
        return 1

    model = sys.argv[1]

    if not os.path.exists(AUDIO):
        print(json.dumps({'ok': False, 'error': f'No existe el audio de prueba: {AUDIO}'}))
        return 1

    out_dir = os.path.join(TMP_DIR, f'whisper_test_{model}')
    os.makedirs(out_dir, exist_ok=True)

    cmd = [
        'whisper', AUDIO, '--model', model, '--language', 'English',
        '--output_dir', out_dir, '--output_format', 'txt', '--verbose', 'False',
    ]

    started = time.time()
    result = subprocess.run(cmd, capture_output=True, text=True)
    elapsed = round(time.time() - started, 1)

    txt_file = os.path.join(out_dir, os.path.splitext(os.path.basename(AUDIO))[0] + '.txt')
    if result.returncode != 0 or not os.path.exists(txt_file):
        print(json.dumps({
            'ok': False,
            'model': model,
            'seconds': elapsed,
            'error': (result.stderr or 'Whisper no genero salida.')[-500:],
        }))
        return 1

    with open(txt_file, encoding='utf-8') as fh:
        text = fh.read().strip()

    print(json.dumps({
        'ok': True,
        'model': model,
        'seconds': elapsed,
        'words': len(text.split()),
        'text': text[:600],
    }, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())
