<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookingRules;
use App\Services\Files;
use App\Services\MealTimes;
use App\Services\Notifier;
use App\Support\Days;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Admin: the reports employees sent, with read marks and replies (a reply lands in the sender's notifications). */
class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $rules = BookingRules::current();
        $userId = (string) $request->query('user', 'all');
        $date = (string) $request->query('date', '');
        $read = (string) $request->query('read', 'all');
        $local = fn (string $utc) => CarbonImmutable::parse($utc . ' UTC')->setTimezone(config('app.timezone'));

        $users = DB::table('user')->get()->keyBy('id');
        $all = DB::table('reports')->orderByDesc('created')->get()->map(function ($row) use ($users, $rules, $local) {
            $user = $users->get($row->user);
            $meal = MealTimes::mealOf((string) $row->meal);
            $mealDate = substr((string) $row->meal_date, 0, 10);
            return [
                'id' => $row->id,
                'userId' => (string) $row->user,
                'name' => $user ? (trim($user->firstname . ' ' . $user->lastname) ?: (string) $user->email) : t('Unknown user'),
                'firstName' => $user->firstname ?? '',
                'department' => $user->Department ?? '',
                'subject' => (string) $row->title,
                'message' => (string) $row->message,
                'read' => (int) $row->read === 1,
                'reply' => trim((string) $row->reply),
                'created' => $local($row->created),
                'updated' => $local($row->updated),
                'picture' => $row->picter !== '' ? Files::url('reports', $row->id, $row->picter) : null,
                'thumb' => $row->picter !== '' ? Files::url('reports', $row->id, $row->picter, '600x600') : null,
                'meal' => $meal && strlen($mealDate) === 10
                    ? $rules->mealLabel($meal) . ' · ' . Days::fromKey($mealDate)->translatedFormat('j M')
                    : null,
            ];
        });

        $visible = $all
            ->when($userId !== 'all', fn ($c) => $c->where('userId', $userId))
            ->when($date !== '', fn ($c) => $c->filter(fn ($r) => $r['created']->format('Y-m-d') === $date))
            ->when($read === 'unread', fn ($c) => $c->where('read', false))
            ->when($read === 'read', fn ($c) => $c->where('read', true))
            ->values();

        return view('admin.reports', [
            'reports' => $visible,
            'total' => $all->count(),
            'unread' => $all->where('read', false)->count(),
            'reporters' => $all->unique('userId')->map(fn ($r) => ['id' => $r['userId'], 'name' => $r['name']])->values(),
            'userId' => $userId,
            'date' => $date,
            'read' => $read,
        ]);
    }

    public function mark(Request $request, string $id): RedirectResponse
    {
        DB::table('reports')->where('id', $id)->update(['read' => $request->boolean('read') ? 1 : 0, 'updated' => Ids::now()]);
        return redirect()->back();
    }

    public function reply(Request $request, string $id): RedirectResponse
    {
        $report = DB::table('reports')->where('id', $id)->first();
        abort_if($report === null, 404);
        $message = trim((string) $request->input('message'));
        if ($message === '') {
            return redirect()->back();
        }

        DB::table('reports')->where('id', $id)->update(['reply' => $message, 'read' => 1, 'updated' => Ids::now()]);

        $sender = DB::table('user')->where('id', $report->user)->first();
        $name = $sender ? trim($sender->firstname . ' ' . $sender->lastname) : '';
        Notifier::send([(string) $report->user], 'Re: ' . $report->title, $message, $name !== '' ? $name : 'Report reply', $request->user()->id);

        return redirect()->back()->with('report_flash', 'Reply sent to the notifications of the person.');
    }
}
