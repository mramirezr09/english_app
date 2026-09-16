<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Lesson;

final class HomeController extends Controller
{
    public function index(array $params = []): void
    {
        $lessons = new Lesson();
        $this->view('lessons.index', [
            'title'   => 'Repaso de Ingles',
            'lessons' => $lessons->all(),
            'stats'   => $lessons->stats(),
        ]);
    }

    public function random(array $params = []): void
    {
        $lesson = (new Lesson())->random();
        if ($lesson === null) {
            $this->redirect('/');
        }
        $this->redirect('/lessons/' . (int) $lesson['id']);
    }
}
