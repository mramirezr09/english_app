function whisperModel() {
    return document.getElementById('whisper').value;
}

function go(action) {
    window.location.href = '/sync/' + action + '?whisper=' + encodeURIComponent(whisperModel());
}

function live(file) {
    window.open('/sync/live?file=' + encodeURIComponent(file) + '&whisper=' + encodeURIComponent(whisperModel()), '_blank');
}
