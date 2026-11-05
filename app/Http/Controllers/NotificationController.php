<?php

namespace App\Http\Controllers;

use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** The employee's notifications. Opening the page marks them as read. */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $id = $request->user()->id;
        $items = self::mine($id);

        // What was unread when the page opened keeps its "New" label during this visit.
        $new = array_column(array_filter($items, fn ($n) => !$n['read']), 'id');
        self::markRead($id, $new);

        return view('pages.notifications', ['items' => $items, 'new' => $new]);
    }

    /** "Got it" / close on the popup. */
    public function readAll(Request $request): RedirectResponse
    {
        $id = $request->user()->id;
        self::markRead($id, array_column(array_filter(self::mine($id), fn ($n) => !$n['read']), 'id'));
        return redirect()->back();
    }

    /**
     * The person's notifications, newest first.
     *
     * @return array<int, array{id: string, title: string, message: string, createdAt: string, read: bool}>
     */
    public static function mine(string $userId, int $limit = 0): array
    {
        $query = DB::table('notifications')
            ->whereRaw('JSON_CONTAINS(recipients, JSON_QUOTE(?))', [$userId])
            ->orderByDesc('created');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()->map(fn ($row) => [
            'id' => $row->id,
            'title' => (string) $row->title,
            'message' => (string) $row->message,
            'createdAt' => (string) $row->created,
            'read' => in_array($userId, array_map('strval', (array) json_decode((string) $row->read_by, true)), true),
        ])->all();
    }

    /** Adds the person to "read_by" of each notification (the others stay untouched). */
    private static function markRead(string $userId, array $ids): void
    {
        foreach ($ids as $id) {
            $row = DB::table('notifications')->where('id', $id)->first(['read_by']);
            $readBy = array_map('strval', (array) json_decode((string) ($row->read_by ?? '[]'), true));
            if ($row !== null && !in_array($userId, $readBy, true)) {
                $readBy[] = $userId;
                DB::table('notifications')->where('id', $id)->update(['read_by' => json_encode($readBy), 'updated' => Ids::now()]);
            }
        }
    }
}
