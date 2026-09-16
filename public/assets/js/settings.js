const result = document.getElementById('result');

function show(text) { result.textContent = text; }

async function call(url) {
    show('Procesando... esto puede tardar.');
    try {
        const res = await fetch(url);
        const data = await res.json();
        show(JSON.stringify(data, null, 2));
    } catch (e) { show('Error: ' + e); }
}

function refreshModels() { call('/settings/models'); }
function testAi() { call('/settings/test-ai'); }
function testWhisper() {
    const model = document.querySelector('select[name=whisper_model]').value;
    call('/settings/test-whisper?model=' + encodeURIComponent(model));
}
