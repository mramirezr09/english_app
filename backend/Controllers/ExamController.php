<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Exam;
use App\Models\Lesson;
use App\Services\AiConfig;
use App\Services\ExamBuilder;
use App\Services\ExamGrader;
use App\Services\ExamProcessor;
use App\Services\OpenCode;
use App\Services\ProcessRunner;

final class ExamController extends Controller
{
    public function index(array $params = []): void
    {
        $exam = new Exam();
        $counts = $exam->questionCountsByLesson();

        $lessons = [];
        foreach ((new Lesson())->all() as $row) {
            $row['question_count'] = $counts[(int) $row['id']] ?? 0;
            $row['gen'] = ProcessRunner::status($this->lessonLogName((int) $row['id']));
            $lessons[] = $row;
        }

        $this->view('exams.index', [
            'title'          => 'Examenes',
            'lessons'        => $lessons,
            'levels'         => $exam->questionCountsByLevel(),
            'totalQuestions' => $exam->totalQuestions(),
            'attempts'       => $exam->recentAttempts(15),
            'attemptStats'   => $exam->attemptStats(),
            'passingScore'   => $this->passingScore(),
            'logs'           => ProcessRunner::recentLogs(8),
            'status'         => $this->statusMessage((string) Request::query('status', '')),
        ]);
    }

    public function take(array $params = []): void
    {
        $scope = (string) Request::query('scope', 'mixed');
        $ref = Request::query('ref');

        if (!in_array($scope, ['lesson', 'level', 'mixed'], true)) {
            $scope = 'mixed';
        }

        if ($scope === 'mixed') {
            $ref = null;
        } elseif ($ref === null || $ref === '') {
            $this->redirect('/exams?status=missing_ref');
        } else {
            $ref = (string) $ref;
        }

        $limit = (int) (AiConfig::load()['exam']['questions'] ?? 12);
        $paper = (new ExamBuilder())->build($scope, $ref, $limit);

        if ($paper === null) {
            $this->redirect('/exams?status=no_questions');
        }

        $this->view('exams.take', [
            'title'   => 'Examen',
            'scope'   => $scope,
            'ref'     => $ref,
            'paper'   => $paper,
            'heading' => $this->heading($scope, $ref),
        ]);
    }

    public function grade(array $params = []): void
    {
        $input = Request::json();
        $attemptId = (int) ($input['attempt_id'] ?? 0);
        $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];

        $exam = new Exam();
        $attempt = $exam->findAttempt($attemptId);
        if ($attempt === null || $attempt['status'] !== 'in_progress') {
            $this->json(['error' => 'Intento no valido.'], 404);
        }

        $paper = json_decode((string) $attempt['paper'], true);
        if (!is_array($paper)) {
            $this->json(['error' => 'Examen corrupto.'], 500);
        }

        $grader = new ExamGrader();
        $details = [];
        $awardedTotal = 0.0;
        $maxTotal = 0.0;

        foreach ($paper as $item) {
            $qid = (int) ($item['qid'] ?? 0);
            $question = $exam->findQuestion($qid);
            if ($question === null) {
                continue;
            }

            $response = $answers[(string) $qid] ?? $answers[$qid] ?? null;
            $result = $grader->grade($question, $item, $response);
            $result['qid'] = $qid;
            $result['prompt'] = (string) $question['prompt'];
            $details[] = $result;

            if (!empty($result['graded'])) {
                $awardedTotal += (float) $result['awarded'];
                $maxTotal += (float) $result['max'];
            }
        }

        $score = $maxTotal > 0 ? round($awardedTotal / $maxTotal * 100, 1) : 0.0;
        $passed = $score >= $this->passingScore();

        $exam->finishAttempt($attemptId, $score, $passed, (string) json_encode($details, JSON_UNESCAPED_UNICODE));

        $this->json([
            'ok'         => true,
            'attempt_id' => $attemptId,
            'score'      => $score,
            'passed'     => $passed,
            'redirect'   => '/exams/results/' . $attemptId,
        ]);
    }

    public function results(array $params): void
    {
        $exam = new Exam();
        $attempt = $exam->findAttempt($params['id']);

        if ($attempt === null || $attempt['status'] !== 'finished') {
            $this->redirect('/exams');
        }

        $details = json_decode((string) $attempt['details'], true);
        if (!is_array($details)) {
            $details = [];
        }

        $this->view('exams.results', [
            'title'        => 'Resultado del examen',
            'attempt'      => $attempt,
            'details'      => $details,
            'passingScore' => $this->passingScore(),
        ]);
    }

    public function generate(array $params = []): void
    {
        if (!ProcessRunner::canRunShell()) {
            $this->redirect('/exams?status=shell_disabled');
        }
        if (!ProcessRunner::logDirWritable()) {
            $this->redirect('/exams?status=log_unwritable');
        }
        if (ProcessRunner::resolveBinary(AiConfig::phpBinary()) === null) {
            $this->redirect('/exams?status=worker_missing');
        }
        if (OpenCode::binary() === null) {
            $this->redirect('/exams?status=opencode_missing');
        }
        if (OpenCode::apiKey() === '' && !OpenCode::isFreeModel(OpenCode::model())) {
            $this->redirect('/exams?status=opencode_key_missing');
        }

        $scope = (string) Request::query('scope', 'all');
        $ref = Request::query('ref');
        $force = Request::query('force') === '1';
        $processor = new ExamProcessor();

        if ($scope === 'lesson' && $ref !== null && $ref !== '') {
            $processor->generateForLesson((string) $ref, $force);
        } else {
            $processor->generateAll($force);
        }

        $this->redirect('/exams?status=generating');
    }

    public function log(array $params = []): void
    {
        $name = (string) Request::query('name', '');
        if ($name === '') {
            $this->redirect('/exams');
        }
        $content = ProcessRunner::readLog($name, 300);
        Response::text($content !== '' ? $content : 'Sin registros para "' . $name . '".');
    }

    private function heading(string $scope, ?string $ref): string
    {
        if ($scope === 'lesson') {
            $lesson = (new Lesson())->find((string) $ref);
            return 'Examen: ' . (string) ($lesson['title'] ?? 'Leccion');
        }
        if ($scope === 'level') {
            return 'Examen del nivel ' . (string) $ref;
        }
        return 'Examen mixto';
    }

    private function passingScore(): int
    {
        return (int) (AiConfig::load()['exam']['passing_score'] ?? 80);
    }

    private function lessonLogName(int $id): string
    {
        return 'exam_generate_' . $id;
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            'generating'          => 'Generando preguntas con IA en segundo plano. Recarga la pagina en unos minutos.',
            'no_questions'        => 'No hay preguntas disponibles para ese examen. Genera preguntas primero.',
            'missing_ref'         => 'Datos de examen incompletos. Elige una leccion o nivel.',
            'shell_disabled'      => 'El servidor PHP tiene deshabilitado shell_exec; no se pueden lanzar los workers.',
            'log_unwritable'      => 'No se puede escribir en storage/tmp. Revisa los permisos del usuario del servicio.',
            'worker_missing'      => 'No se encontro el binario de PHP configurado (paths.php en ai_config.json).',
            'opencode_missing'    => 'No se encontro el CLI de OpenCode. Configura la ruta en /settings o en OPENCODE_BIN.',
            'opencode_key_missing' => 'Falta OPENCODE_API_KEY para el modelo seleccionado. Configurala en /settings.',
            default               => '',
        };
    }
}
