import os
import sqlite3
import sys
import json
import subprocess
import requests

# Configuración
DB_PATH = '/home/mauricio/.openclaw/workspace/english_app/data/lessons.db'
CONFIG_PATH = '/home/mauricio/.openclaw/workspace/english_app/ai_config.json'

def load_config():
    with open(CONFIG_PATH, 'r') as f:
        return json.load(f)

def call_ai(prompt):
    config = load_config()
    try:
        # Usamos /api/chat que es el endpoint correcto y moderno de Ollama
        endpoint = config['endpoint'].replace('/api/generate', '/api/chat')
        payload = {
            "model": config['model'],
            "messages": [{"role": "user", "content": prompt}],
            "stream": False
        }
        response = requests.post(endpoint, json=payload, timeout=config['timeout'])
        response.raise_for_status()
        return response.json().get('message', {}).get('content', '').strip()
    except Exception as e:
        print(f"Error llamando a la IA API: {e}")
        return None

def enrich_lesson(lesson_id, transcription):
    print(f"Enriqueciendo lección {lesson_id}...")
    
    explanation_prompt = (
        f"You are an expert English teacher. Based on the following transcription, "
        f"provide a clear, pedagogical explanation of the grammar rules or vocabulary in Spanish. "
        f"Transcription:\n{transcription}"
    )
    
    explanation = call_ai(explanation_prompt)
    if not explanation:
        print("No se pudo generar la explicación.")
        return False

    exercises_prompt = (
        f"You are an expert English teacher. Based on the following transcription, "
        f"generate 5 exercises. Return ONLY a JSON array of objects with 'question' and 'answer' keys. "
        f"Example: [{{'question': '...', 'answer': '...'}}]. "
        f"Transcription:\n{transcription}"
    )
    
    exercises_raw = call_ai(exercises_prompt)
    if exercises_raw:
        # Limpiar posibles etiquetas de markdown
        exercises_raw = exercises_raw.replace('```json', '').replace('```', '').strip()
    
    try:
        if exercises_raw:
            json.loads(exercises_raw)
    except:
        print("Error: JSON de ejercicios inválido.")
        exercises_raw = None

    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()
    cursor.execute("UPDATE lessons SET explanation = ?, exercises = ? WHERE id = ?", (explanation, exercises_raw, lesson_id))
    conn.commit()
    conn.close()
    
    print("Lección enriquecida exitosamente.")
    return True

if __name__ == '__main__':
    if len(sys.argv) > 1:
        try:
            l_id = int(sys.argv[1])
            conn = sqlite3.connect(DB_PATH)
            cursor = conn.cursor()
            cursor.execute("SELECT transcription FROM lessons WHERE id = ?", (l_id,))
            row = cursor.fetchone()
            conn.close()
            if row and row[0]:
                enrich_lesson(l_id, row[0])
        except Exception as e:
            print(f"Error: {e}")
