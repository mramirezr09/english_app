import os
import subprocess
import sqlite3
import sys
from datetime import datetime

# Configuración
VIDEO_DIR = '/home/mauricio/.openclaw/workspace/ingles/4A/'
DB_PATH = '/home/mauricio/.openclaw/workspace/english_app/data/lessons.db'
TMP_DIR = '/home/mauricio/.openclaw/workspace/english_app/tmp/'

if not os.path.exists(TMP_DIR):
    os.makedirs(TMP_DIR)

def extract_audio(video_path):
    print(f"Extrayendo audio de {os.path.basename(video_path)}...")
    audio_out = os.path.join(TMP_DIR, 'lesson_audio.wav')
    cmd = ['ffmpeg', '-y', '-i', video_path, '-vn', '-acodec', 'pcm_s16le', '-ar', '16000', '-ac', '1', audio_out]
    subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    return audio_out

def transcribe_audio(audio_path):
    print("Iniciando transcripción con Whisper (esto puede tardar)...")
    cmd = ['whisper', audio_path, '--model', 'tiny', '--language', 'English', '--output_dir', TMP_DIR, '--output_format', 'txt']
    subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    
    txt_file = audio_path.replace('.wav', '.txt')
    if os.path.exists(txt_file):
        with open(txt_file, 'r', encoding='utf-8') as f:
            text = f.read().strip()
        print("Transcripción completada con éxito.")
        return text
    
    print("Error: Whisper no generó el archivo de texto.")
    return None

def save_to_db(file_name, transcription):
    print("Guardando transcripción en la base de datos...")
    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()
    cursor.execute("UPDATE lessons SET transcription = ? WHERE file_name = ?", (transcription, file_name))
    conn.commit()
    conn.close()
    print("Transcripción guardada correctamente.")

def process_file(file):
    try:
        print(f"--- Iniciando proceso para: {file} ---")
        video_full_path = os.path.join(VIDEO_DIR, file)
        if not os.path.exists(video_full_path):
            print(f"Error: El archivo {video_full_path} no existe.")
            return False
            
        audio_path = extract_audio(video_full_path)
        text = transcribe_audio(audio_path)
        if text:
            save_to_db(file, text)
            
            # --- ENRIQUECIMIENTO IA ---
            print("Iniciando enriquecimiento de la lección (IA)...")
            conn = sqlite3.connect(DB_PATH)
            cursor = conn.cursor()
            cursor.execute("SELECT id FROM lessons WHERE file_name = ?", (file,))
            row = cursor.fetchone()
            conn.close()
            
            if row:
                l_id = row[0]
                # Ejecutamos el script de enriquecimiento
                subprocess.run(['python3', os.path.join(os.path.dirname(__file__), 'enrich_lesson.py'), str(l_id)])
            
            return True
        else:
            print("No se pudo obtener el texto de la transcripción.")
    except Exception as e:
        print(f"Error crítico en process_file: {str(e)}")
    return False

if __name__ == '__main__':
    if len(sys.argv) > 1:
        target_file = sys.argv[1]
        if process_file(target_file):
            print(f"RESULTADO: {target_file} procesado exitosamente.")
        else:
            print(f"RESULTADO: Falló el procesamiento de {target_file}.")
    else:
        files = [f for f in os.listdir(VIDEO_DIR) if f.endswith('.mp4')]
        for file in files:
            process_file(file)
        print("Todos los archivos han sido procesados.")
