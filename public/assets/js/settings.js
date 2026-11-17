const result = document.getElementById('result');

function show(text) {
    if (result) {
        result.textContent = typeof text === 'string' ? text : JSON.stringify(text, null, 2);
    }
}

async function call(url) {
    show('Procesando... esto puede tardar.');
    try {
        const res = await fetch(url);
        const data = await res.json();
        show(data);
        return data;
    } catch (e) {
        show('Error: ' + e);
        return null;
    }
}

async function refreshModels() {
    const data = await call('/settings/models');
    if (!data || !Array.isArray(data.models)) {
        return;
    }

    const select = document.querySelector('select[name=opencode_model]');
    if (!select) {
        return;
    }

    const current = select.value;
    select.innerHTML = '';
    data.models.forEach((model) => {
        const option = document.createElement('option');
        option.value = model;
        option.textContent = model;
        select.appendChild(option);
    });

    if (data.models.includes(current)) {
        select.value = current;
    }
}

function testAi() {
    call('/settings/test-ai');
}

function testWhisper() {
    const model = document.querySelector('select[name=whisper_model]');
    const value = model ? model.value : 'base';
    call('/settings/test-whisper?model=' + encodeURIComponent(value));
}

function runDoctor() {
    call('/settings/doctor');
}
