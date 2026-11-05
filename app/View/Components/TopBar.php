<?php

namespace App\View\Components;

use App\Services\Standing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\Component;
use Illuminate\View\View;

/** The employee top bar: menu, language, no-show counter, notification badge. */
class TopBar extends Component
{
    public array $nav;

    public int $strikes = 0;

    public int $unread = 0;

    public function __construct()
    {
        $user = Auth::user();
        $this->nav = [
            ['href' => '/week', 'label' => 'Week'],
            ...($user?->role() === 'responsible' ? [['href' => '/team', 'label' => 'My team']] : []),
            ['href' => '/notifications', 'label' => 'Notifications'],
            ['href' => '/report', 'label' => 'Report'],
            ['href' => '/profile', 'label' => 'Profile'],
        ];

        if ($user !== null) {
            $this->strikes = Standing::strikes($user);
            $this->unread = (int) DB::table('notifications')
                ->whereRaw('JSON_CONTAINS(recipients, JSON_QUOTE(?))', [$user->id])
                ->whereRaw('NOT JSON_CONTAINS(read_by, JSON_QUOTE(?))', [$user->id])
                ->count();
        }
    }

    public function render(): View
    {
        return view('components.top-bar');
    }
}
