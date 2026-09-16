const LESSON_ID = document.getElementById('lesson-page').dataset.lessonId;

function startTutorChat() {
    document.getElementById('tutor-chat').style.display = 'block';
    appendMessage('Tutor', 'Hello! I am your English Tutor. I know everything about this lesson. Ask me anything!');
}

async function sendMessage() {
    const input = document.getElementById('tutor-input');
    const msg = input.value.trim();
    if (!msg) return;
    appendMessage('You', msg);
    input.value = '';
    appendMessage('Tutor', '...');
    try {
        const res = await fetch('/api/tutor', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({message: msg, lesson_id: LESSON_ID})
        });
        const data = await res.json();
        if (data.response_html) {
            updateLastMessage('Tutor', data.response_html, true);
        } else {
            updateLastMessage('Tutor', data.response || data.error || 'Sin respuesta', false);
        }
    } catch (e) { updateLastMessage('Tutor', 'Error connecting to tutor.', false); }
}

function buildMessage(sender, content, isHtml) {
    const div = document.createElement('div');
    div.dataset.sender = sender;
    if (isHtml) {
        div.className = 'chat-msg markdown';
        div.innerHTML = '<strong>' + sender + ':</strong> ' + content;
    } else {
        div.className = 'chat-msg';
        div.innerHTML = '<strong>' + sender + ':</strong> ' + escapeHtml(content);
    }
    div.style.padding = '8px'; div.style.borderRadius = '5px';
    div.style.background = sender === 'You' ? '#e1f5fe' : '#f5f5f5';
    return div;
}

function appendMessage(sender, text) {
    const chat = document.getElementById('chat-messages');
    chat.appendChild(buildMessage(sender, text, false));
    chat.scrollTop = chat.scrollHeight;
}

function updateLastMessage(sender, text, isHtml) {
    const chat = document.getElementById('chat-messages');
    chat.replaceChild(buildMessage(sender, text, isHtml), chat.lastElementChild);
    chat.scrollTop = chat.scrollHeight;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function normalize(text) {
    return text.toLowerCase().trim().replace(/[.,!?;:'"]/g, '').replace(/\s+/g, ' ');
}

function checkAnswer(index) {
    const wrapper = document.querySelectorAll('.exercise')[index];
    const input = document.getElementById('ex_' + index);
    const result = document.getElementById('res_' + index);
    const expected = wrapper.dataset.answer;

    if (normalize(input.value) === normalize(expected)) {
        result.textContent = ' Correcto!';
        result.className = 'success';
    } else {
        result.textContent = ' Incorrecto. Respuesta: ' + expected;
        result.className = 'error';
    }
}

async function markComplete() {
    try {
        const res = await fetch('/api/lessons/' + LESSON_ID + '/complete', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({lesson_id: LESSON_ID})
        });
        const data = await res.json();
        if (data.ok) {
            document.getElementById('complete-btn').style.display = 'none';
            document.getElementById('complete-status').textContent = '\u2705 Completada';
            document.getElementById('complete-status').className = 'success';
        } else {
            alert(data.error || 'No se pudo marcar como completada.');
        }
    } catch (e) { alert('Error de conexion.'); }
}
