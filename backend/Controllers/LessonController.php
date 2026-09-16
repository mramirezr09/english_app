<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Lesson;
use App\Services\Markdown;

final class LessonController extends Controller
{
    public function show(array $params): void
    {
        $model = new Lesson();
        $lesson = $model->find($params['id']);

        if ($lesson === null) {
            Response::notFound('Leccion no encontrada');
        }

        $this->view('lessons.show', [
            'title'          => (string) $lesson['title'],
            'lesson'         => $lesson,
            'exercises'      => $model->exercises($lesson),
            'explanation_html' => $lesson['explanation'] ? Markdown::render((string) $lesson['explanation']) : '',
            'video_url'      => $this->videoUrl((string) $lesson['file_name']),
        ]);
    }

    /**
     * Encode a relative video path segment by segment so the slashes that
     * make up the level folders (e.g. "4A/Regla_1.mp4") stay intact.
     */
    private function videoUrl(string $fileName): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $fileName)));
    }

    public function complete(array $params): void
    {
        $id = Request::input('lesson_id', $params['id']);
        $ok = (new Lesson())->markComplete($id);

        $this->json($ok ? ['ok' => true] : ['ok' => false, 'error' => 'Lesson not found']);
    }

    public function reset(array $params): void
    {
        (new Lesson())->reset($params['id']);
        $this->redirect('/sync?status=reset');
    }
}
