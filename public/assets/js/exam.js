(function () {
    var form = document.getElementById('exam-form');
    if (!form) {
        return;
    }

    var attemptId = parseInt(form.dataset.attemptId, 10);
    var status = document.getElementById('exam-status');
    var submit = document.getElementById('exam-submit');

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        var answers = {};
        form.querySelectorAll('.exam-question').forEach(function (block) {
            var qid = block.dataset.qid;
            var checked = block.querySelector('input[type="radio"]:checked');
            if (checked) {
                answers[qid] = checked.value;
                return;
            }
            var field = block.querySelector('input[type="text"], textarea');
            if (field) {
                answers[qid] = field.value;
            }
        });

        if (submit) {
            submit.disabled = true;
        }
        if (status) {
            status.textContent = 'Corrigiendo...';
            status.className = 'success';
        }

        try {
            var res = await fetch('/api/exams/grade', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({attempt_id: attemptId, answers: answers})
            });
            var data = await res.json();
            if (data && data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            if (status) {
                status.textContent = (data && data.error) ? data.error : 'No se pudo corregir el examen.';
                status.className = 'error';
            }
        } catch (e) {
            if (status) {
                status.textContent = 'Error de conexion.';
                status.className = 'error';
            }
        }

        if (submit) {
            submit.disabled = false;
        }
    });
})();
