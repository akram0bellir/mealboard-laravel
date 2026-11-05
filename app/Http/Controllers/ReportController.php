<?php

namespace App\Http\Controllers;

use App\Services\BookingRules;
use App\Services\Files;
use App\Services\MealTimes;
use App\Support\Days;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Employee: send a report (title, text, optional picture and meal) to the kitchen admins, and see past ones. */
class ReportController extends Controller
{
    /** The old data spells breakfast "brakfast". */
    private const MEAL_TO_DB = ['breakfast' => 'brakfast', 'lunch' => 'lunch', 'dinner' => 'dinner'];

    public function show(Request $request): View
    {
        $rules = BookingRules::current();
        $reports = DB::table('reports')->where('user', $request->user()->id)->orderByDesc('created')->get()->map(function ($row) use ($rules) {
            $meal = MealTimes::mealOf((string) $row->meal);
            $mealDate = Days::keyOf($row->meal_date);
            return [
                'subject' => (string) $row->title,
                'message' => (string) $row->message,
                'createdAt' => (string) $row->created,
                'read' => (int) $row->read === 1,
                'reply' => trim((string) $row->reply),
                'picture' => $row->picter !== '' ? Files::url('reports', $row->id, $row->picter, '600x600') : null,
                'meal' => $meal !== null && $mealDate !== '' ? $rules->mealLabel($meal) . ' ' . Days::format($mealDate) : null,
            ];
        })->all();

        return view('pages.report', ['reports' => $reports, 'rules' => $rules]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $title = trim((string) $request->input('title'));
        $message = trim((string) $request->input('message'));
        $mealDate = trim((string) $request->input('meal_date'));
        $meal = (string) $request->input('meal');
        $keep = $request->only('title', 'message', 'meal_date', 'meal');

        if ($title === '' || $message === '') {
            return $this->failed('Please fill in the title and what happened.', $keep);
        }
        if ($mealDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $mealDate)) {
            return $this->failed("Couldn't send your report. Please try again.", $keep);
        }
        if ($meal !== '' && !isset(self::MEAL_TO_DB[$meal])) {
            return $this->failed("Couldn't send your report. Please try again.", $keep);
        }

        $id = Ids::new();
        $picture = '';
        $file = $request->file('picter');
        if ($file !== null) {
            try {
                $picture = Files::store('reports', $id, $file);
            } catch (\RuntimeException $e) {
                return $this->failed($e->getMessage(), $keep);
            }
        }

        $now = Ids::now();
        DB::table('reports')->insert([
            'id' => $id,
            'title' => mb_substr($title, 0, 80),
            'message' => mb_substr($message, 0, 1000),
            'picter' => $picture,
            'user' => $user->id,
            'meal_date' => $mealDate !== '' ? Days::toDb($mealDate) : null,
            'meal' => $meal !== '' ? self::MEAL_TO_DB[$meal] : '',
            'read' => 0,
            'reply' => '',
            'created' => $now,
            'updated' => $now,
        ]);

        return redirect('/report')->with('report_status', ['tone' => 'ok', 'text' => 'Report sent. The kitchen team will get back to you in your notifications.']);
    }

    private function failed(string $text, array $input): RedirectResponse
    {
        return redirect('/report')->withInput($input)->with('report_status', ['tone' => 'error', 'text' => $text]);
    }
}
