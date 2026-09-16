<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\Lesson;
use App\Services\AiService;
use App\Services\Markdown;

final class TutorController extends Controller
{
    public function message(array $params = []): void
    {
        $input = Request::json();
        $message = trim((string) ($input['message'] ?? ''));
        $lessonId = $input['lesson_id'] ?? null;

        if ($message === '' || !$lessonId) {
            $this->json(['error' => 'Missing data']);
        }

        $lesson = (new Lesson())->find($lessonId);
        if ($lesson === null) {
            $this->json(['error' => 'Lesson not found']);
        }

        $title = $lesson['title'] ?: 'English lesson';
        $transcription = str_limit($lesson['transcription'] ?: 'No transcription available.', 6000);
        $explanation = str_limit($lesson['explanation'] ?: 'No explanation available.', 4000);

        $prompt = "You are a friendly and professional English Tutor. Your goal is to help the student understand the current lesson.\n\n";
        $prompt .= "LESSON TITLE: $title\n\n";
        $prompt .= "TRANSCRIPTION:\n$transcription\n\n";
        $prompt .= "TEACHER'S EXPLANATION:\n$explanation\n\n";
        $prompt .= "STUDENT MESSAGE: $message\n\n";
        $prompt .= 'Respond in English. If the student asks in Spanish or requests clarification, you may answer in Spanish. ';
        $prompt .= 'Be encouraging, correct mistakes gently and focus on the grammar and vocabulary of the context above.';

        $response = AiService::ask($prompt);

        if ($response === null || $response === '') {
            $this->json(['error' => 'El tutor de IA no esta disponible. Revisa la configuracion en /settings.']);
        }

        $this->json([
            'response'      => $response,
            'response_html' => Markdown::render($response),
        ]);
    }
}
