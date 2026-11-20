/* Speaking practice: microphone capture (MediaRecorder), upload to
 * /api/lessons/{id}/speech/{evaluate,question} and feedback rendering.
 * Used both for answering the written exercises out loud and for the
 * guided conversation section. */

const SpeechPractice = (function () {
    const MAX_SECONDS = 60;
    let current = null;          // conversation: {question, model_answer}
    const history = [];          // conversation: [{question, heard, score}]
    const active = new Map();    // button -> {recorder, startedAt, timer}

    function lessonId() {
        const page = document.getElementById('lesson-page');
        return page ? page.dataset.lessonId : null;
    }

    function supported() {
        return window.isSecureContext
            && navigator.mediaDevices
            && navigator.mediaDevices.getUserMedia
            && window.MediaRecorder;
    }

    function pickMime() {
        const candidates = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/mp4;codecs=mp4a.40.2',
            'audio/mp4',
            'audio/ogg;codecs=opus',
        ];
        for (const mime of candidates) {
            if (window.MediaRecorder && MediaRecorder.isTypeSupported(mime)) return mime;
        }
        return '';
    }

    function extFor(mime) {
        if (mime.indexOf('mp4') !== -1) return 'm4a';
        if (mime.indexOf('ogg') !== -1) return 'ogg';
        return 'webm';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    // ------------------------------------------------------------------ #
    // Recording
    // ------------------------------------------------------------------ #

    async function toggleMic(btn, onAudio) {
        if (active.has(btn)) {
            active.get(btn).recorder.stop();
            return;
        }
        if (!supported()) {
            onAudio(null, 'Tu navegador no permite acceder al microfono aqui. '
                + 'Se necesita HTTPS (o localhost) y un navegador con MediaRecorder.');
            return;
        }

        if (!btn.dataset.idleLabel) btn.dataset.idleLabel = btn.innerHTML;
        btn.disabled = true;
        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true }
            });
        } catch (e) {
            btn.disabled = false;
            onAudio(null, 'Permiso de microfono denegado (' + e.name + '). '
                + 'Habilitelo en el navegador y vuelva a intentar.');
            return;
        }

        const mime = pickMime();
        const recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : {});
        const chunks = [];
        recorder.ondataavailable = (e) => { if (e.data && e.data.size) chunks.push(e.data); };
        recorder.onstop = () => {
            stream.getTracks().forEach((t) => t.stop());
            clearInterval(entry.timer);
            active.delete(btn);
            btn.classList.remove('recording');
            btn.innerHTML = '&#127908; Responder';
            btn.disabled = false;
            const blob = new Blob(chunks, { type: mime || 'audio/webm' });
            onAudio(blob, null, extFor(mime));
        };

        const entry = { recorder: recorder, startedAt: Date.now(), timer: 0 };
        entry.timer = setInterval(() => {
            if (Date.now() - entry.startedAt > MAX_SECONDS * 1000) recorder.stop();
        }, 500);

        recorder.start();
        active.set(btn, entry);
        btn.disabled = false;
        btn.classList.add('recording');
        btn.innerHTML = '&#9209; Detener';
    }

    // ------------------------------------------------------------------ #
    // API
    // ------------------------------------------------------------------ #

    async function sendSpeech(blob, ext, fields) {
        const fd = new FormData();
        fd.append('audio', blob, 'respuesta.' + ext);
        Object.keys(fields).forEach((k) => fd.append(k, fields[k] || ''));
        const res = await fetch('/api/lessons/' + lessonId() + '/speech/evaluate', {
            method: 'POST',
            body: fd
        });
        return res.json();
    }

    async function askQuestion() {
        const res = await fetch('/api/lessons/' + lessonId() + '/speech/question', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lesson_id: lessonId(), history: history })
        });
        return res.json();
    }

    // ------------------------------------------------------------------ #
    // Rendering
    // ------------------------------------------------------------------ #

    function chipsHtml(chips) {
        if (!chips || !chips.length) return '';
        const spans = chips.map((c) => {
            if (c.status === 'ok') {
                return '<span class="chip chip-ok">' + escapeHtml(c.expected) + '</span>';
            }
            if (c.status === 'wrong') {
                return '<span class="chip chip-wrong" title="se escucho: '
                    + escapeHtml(c.heard) + '">' + escapeHtml(c.expected)
                    + ' <small>&rarr; ' + escapeHtml(c.heard) + '</small></span>';
            }
            if (c.status === 'missing') {
                return '<span class="chip chip-missing">' + escapeHtml(c.expected) + '</span>';
            }
            return '<span class="chip chip-extra">+ ' + escapeHtml(c.heard) + '</span>';
        });
        return '<div class="speech-chips">' + spans.join(' ') + '</div>';
    }

    function issuesHtml(issues) {
        if (!issues || !issues.length) return '';
        const items = issues.map((i) => {
            const label = {
                pronunciation: 'pronunciacion', grammar: 'gramatica',
                vocabulary: 'vocabulario', omission: 'palabra no entendida',
                fluency: 'fluidez', other: 'detalle'
            }[i.type] || i.type;
            return '<li><strong>' + escapeHtml(i.word) + '</strong> (' + escapeHtml(label) + '): '
                + escapeHtml(i.problem_es)
                + (i.tip_es ? '<br><em>' + escapeHtml(i.tip_es) + '</em>' : '')
                + '</li>';
        });
        return '<ul class="speech-issues">' + items.join('') + '</ul>';
    }

    function resultHtml(data) {
        if (!data.ok) {
            let msg = escapeHtml(data.error || 'No se pudo evaluar la respuesta.');
            if (data.heard) msg += '<br>Se entendio: <em>' + escapeHtml(data.heard) + '</em>';
            if (['whisper_missing', 'whisper_model_missing', 'ai_unavailable', 'ffmpeg_missing'].indexOf(data.code) !== -1) {
                msg += ' <a href="/settings">Ir a Ajustes</a>';
            }
            return '<p class="error">' + msg + '</p>';
        }

        const r = data.result || {};
        const head = r.ok
            ? '<p class="success">' + (data.score != null ? '&#9989; ' + data.score + '/100 ' : '') + '&#127881;</p>'
            : '<p class="error">' + (data.score != null ? '&#9888;&#65039; ' + data.score + '/100' : '&#128266;') + '</p>';

        let corrected = '';
        if (r.corrected && r.corrected.toLowerCase() !== String(data.heard || '').toLowerCase()) {
            corrected = '<p>Respuesta correcta: <strong>' + escapeHtml(r.corrected) + '</strong></p>';
        }

        const note = data.source === 'local'
            ? '<p class="speech-note">Correccion local: la IA no pudo dar feedback detallado.</p>'
            : '';

        return head
            + chipsHtml(data.chips)
            + '<p class="speech-heard">Se entendio: <em>' + escapeHtml(data.heard) + '</em></p>'
            + corrected
            + '<p class="speech-feedback">' + escapeHtml(r.feedback_es || '').replace(/\n/g, '<br>') + '</p>'
            + issuesHtml(r.issues)
            + note;
    }

    function speak(text) {
        const toggle = document.getElementById('speech-tts');
        if (!text || (toggle && !toggle.checked)) return;
        try {
            const utter = new SpeechSynthesisUtterance(text);
            utter.lang = 'en-US';
            utter.rate = 0.95;
            window.speechSynthesis.cancel();
            window.speechSynthesis.speak(utter);
        } catch (e) { /* TTS optional */ }
    }

    // ------------------------------------------------------------------ #
    // Exercises with voice
    // ------------------------------------------------------------------ #

    async function exerciseMic(btn, index) {
        const container = document.getElementById('speech-result-' + index);
        await toggleMic(btn, async (blob, error, ext) => {
            if (error) {
                container.hidden = false;
                container.innerHTML = '<p class="error">' + escapeHtml(error) + '</p>';
                return;
            }
            const exercise = document.querySelectorAll('.exercise')[index];
            container.hidden = false;
            container.innerHTML = '<p class="speech-note">Analizando tu respuesta... puede tardar unos segundos.</p>';
            try {
                const data = await sendSpeech(blob, ext, {
                    lesson_id: lessonId(),
                    question: exercise.dataset.question || '',
                    expected: exercise.dataset.answer || ''
                });
                container.innerHTML = resultHtml(data);
            } catch (e) {
                container.innerHTML = '<p class="error">Error de conexion: ' + escapeHtml(e.message) + '</p>';
            }
        });
    }

    // ------------------------------------------------------------------ #
    // Guided conversation
    // ------------------------------------------------------------------ #

    function log() { return document.getElementById('speaking-log'); }

    function addBubble(sender, html, isHtml) {
        const div = document.createElement('div');
        div.className = 'chat-msg ' + (sender === 'Tu' ? 'speech-you' : 'speech-teacher');
        div.innerHTML = '<strong>' + escapeHtml(sender) + ':</strong> ' + (isHtml ? html : escapeHtml(html));
        log().appendChild(div);
        log().scrollTop = log().scrollHeight;
        return div;
    }

    function setStatus(text) {
        const el = document.getElementById('speaking-status');
        if (el) el.textContent = text || '';
    }

    async function startConversation() {
        const warning = document.getElementById('speech-warning');
        warning.style.display = 'none';
        if (!supported()) {
            warning.textContent = 'El microfono necesita HTTPS o localhost (contexto seguro). '
                + 'Estas en ' + location.protocol + '//' + location.host + ' - abre la app por localhost o configura HTTPS.';
            warning.style.display = 'block';
            return;
        }

        setStatus('Generando pregunta...');
        log().hidden = false;
        document.getElementById('speaking-controls').style.display = 'flex';
        document.getElementById('speaking-skip').style.display = 'inline-block';

        try {
            const data = await askQuestion();
            if (!data.ok) {
                addBubble('Sistema', data.error || 'No se pudo generar la pregunta.');
                setStatus('');
                return;
            }
            current = { question: data.question, model_answer: data.model_answer };
            addBubble('Teacher', '<span class="markdown">' + escapeHtml(current.question) + '</span>', true);
            speak(current.question);
            setStatus('Responde hablando...');
        } catch (e) {
            addBubble('Sistema', 'Error de conexion: ' + e.message);
            setStatus('');
        }
    }

    async function skipQuestion() {
        if (!log().hidden) await startConversation();
    }

    async function talkMic(btn) {
        if (!current) { await startConversation(); return; }
        await toggleMic(btn, async (blob, error, ext) => {
            if (error) { addBubble('Sistema', error); setStatus(''); return; }
            setStatus('Analizando tu respuesta... puede tardar unos segundos.');
            btn.disabled = true;
            try {
                const data = await sendSpeech(blob, ext, {
                    lesson_id: lessonId(),
                    question: current.question,
                    expected: current.model_answer || ''
                });
                addBubble('Tu', '&#127908; ' + escapeHtml(data.heard || '(no se entendio nada)'), true);
                addBubble('Teacher', resultHtml(data), true);

                history.push({
                    question: current.question,
                    heard: data.heard || '',
                    score: data.score != null ? data.score : null
                });

                if (data.ok && data.next) {
                    current = data.next;
                    speak(current.question);
                    setStatus('Responde hablando...');
                } else {
                    setStatus(data.ok ? 'Usa "Otra pregunta" para continuar.' : 'Corrige y vuelve a intentar esta pregunta.');
                }
            } catch (e) {
                addBubble('Sistema', 'Error de conexion: ' + e.message);
                setStatus('');
            } finally {
                btn.disabled = false;
            }
        });
    }

    return {
        exerciseMic: exerciseMic,
        startConversation: startConversation,
        skipQuestion: skipQuestion,
        talkMic: talkMic
    };
})();
