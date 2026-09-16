#!/usr/bin/env python3
"""Shared AI client for the English Lessons app.

The backend is selected in ai_config.json:

    "backend": "ollama"    -> local Ollama HTTP API (/api/chat)
    "backend": "openclaw"  -> OpenClaw CLI (agent exec --json)

Used both as a library (enrich_lesson.py) and as a CLI:

    echo "prompt" | python3 ai_client.py
    python3 ai_client.py "prompt"
"""

import json
import os
import sys
import subprocess
import urllib.request
import urllib.error

WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(os.path.dirname(WORKER_DIR))
CONFIG_PATH = os.path.join(PROJECT_ROOT, 'config', 'ai_config.json')
LOG_PATH = os.path.join(PROJECT_ROOT, 'storage', 'tmp', 'ai_client.log')

DEFAULT_CONFIG = {
    'backend': 'ollama',
    'paths': {
        'video_dir': '/home/mauricio/.openclaw/workspace/ingles',
    },
    'ollama': {
        'endpoint': 'http://localhost:11434',
        'model': 'gemma4:31b-cloud',
        'timeout': 300,
    },
    'openclaw': {
        'binary': '/home/mauricio/.openclaw/tmp/agent-cli/openclaw',
        'timeout': 300,
    },
    'whisper': {'model': 'base'},
}

# Keep generations bounded so CPU-only inference never runs past the timeout.
DEFAULT_OPTIONS = {'temperature': 0.3, 'num_predict': 900, 'num_ctx': 4096}


def log(message):
    try:
        os.makedirs(os.path.dirname(LOG_PATH), exist_ok=True)
        with open(LOG_PATH, 'a', encoding='utf-8') as fh:
            fh.write(message.rstrip() + '\n')
    except OSError:
        pass


def load_config():
    cfg = json.loads(json.dumps(DEFAULT_CONFIG))
    if os.path.exists(CONFIG_PATH):
        try:
            with open(CONFIG_PATH, encoding='utf-8') as fh:
                user = json.load(fh)
            for section, values in user.items():
                if isinstance(values, dict) and isinstance(cfg.get(section), dict):
                    cfg[section].update(values)
                else:
                    cfg[section] = values
        except (OSError, ValueError) as exc:
            log(f'config load error: {exc}')
    return cfg


# --------------------------------------------------------------------------- #
# Backends
# --------------------------------------------------------------------------- #
def _ollama_chat(prompt, cfg, json_mode=False, schema=None, options=None):
    options_cfg = cfg['ollama']
    url = options_cfg['endpoint'].rstrip('/') + '/api/chat'
    payload = {
        'model': options_cfg['model'],
        'messages': [{'role': 'user', 'content': prompt}],
        'stream': False,
        'options': {**DEFAULT_OPTIONS, **(options or {})},
    }
    if schema is not None:
        payload['format'] = schema
    elif json_mode:
        payload['format'] = 'json'

    request = urllib.request.Request(
        url, data=json.dumps(payload).encode('utf-8'),
        headers={'Content-Type': 'application/json'}
    )
    with urllib.request.urlopen(request, timeout=options_cfg['timeout']) as response:
        data = json.loads(response.read().decode('utf-8'))
    return (data.get('message') or {}).get('content', '').strip()


def _openclaw_chat(prompt, cfg, json_mode=False, schema=None):
    options = cfg['openclaw']
    if json_mode or schema is not None:
        prompt = prompt + '\n\nReturn ONLY valid JSON, with no markdown or extra text.'
    command = [options['binary'], 'agent', 'exec', '--json', prompt]
    proc = subprocess.run(
        command, capture_output=True, text=True, timeout=options['timeout']
    )
    combined = (proc.stdout or '') + '\n' + (proc.stderr or '')
    return _extract_openclaw_text(combined) or combined.strip()


def _extract_openclaw_text(output):
    for candidate in _json_candidates(output):
        try:
            data = json.loads(candidate)
        except ValueError:
            continue
        if not isinstance(data, dict):
            continue
        for key in ('final', 'text', 'message', 'response', 'output'):
            value = data.get(key)
            if isinstance(value, str) and value.strip():
                return value.strip()
        payloads = data.get('payloads')
        if isinstance(payloads, list):
            texts = [
                str(p.get('text', '')).strip()
                for p in payloads
                if isinstance(p, dict) and str(p.get('text', '')).strip()
            ]
            if texts:
                return '\n'.join(texts)
    return None


def _json_candidates(output):
    start = output.find('{')
    if start == -1:
        return
    end = output.rfind('}')
    if end > start:
        yield output[start:end + 1]
    yield output[start:]


# --------------------------------------------------------------------------- #
# Public API
# --------------------------------------------------------------------------- #
def ask_ai(prompt, config=None, json_mode=False, schema=None, options=None):
    """Send a prompt to the configured backend. Returns text or None on error."""
    cfg = config or load_config()
    backend = cfg.get('backend', 'ollama')
    try:
        if backend == 'openclaw':
            reply = _openclaw_chat(prompt, cfg, json_mode=json_mode, schema=schema)
        else:
            reply = _ollama_chat(prompt, cfg, json_mode=json_mode, schema=schema, options=options)
        log(f'[{backend}] prompt={len(prompt)} chars -> reply={len(reply or "")} chars')
        return reply
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode('utf-8', 'replace') if hasattr(exc, 'read') else ''
        log(f'[{backend}] HTTP error {exc.code}: {detail[:500]}')
        return None
    except Exception as exc:  # noqa: BLE001 - report any backend failure
        log(f'[{backend}] error: {exc}')
        return None


def main():
    if len(sys.argv) > 1:
        prompt = ' '.join(sys.argv[1:])
    else:
        prompt = sys.stdin.read()

    prompt = prompt.strip()
    if not prompt:
        print('', end='')
        return 1

    reply = ask_ai(prompt)
    if reply is None:
        print('', end='')
        return 1
    print(reply, end='')
    return 0


if __name__ == '__main__':
    sys.exit(main())
