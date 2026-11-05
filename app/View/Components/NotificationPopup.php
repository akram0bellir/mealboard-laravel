<?php

namespace App\View\Components;

use App\Http\Controllers\NotificationController;
use App\Support\Roles;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Employee pages: unread notifications pop up when the person opens a page. Closing the popup marks them
 * as read, so each notification pops up only once. The notifications page shows (and marks) them itself.
 */
class NotificationPopup extends Component
{
    public array $shown = [];

    public int $unread = 0;

    public int $more = 0;

    public function __construct()
    {
        $user = Auth::user();
        if ($user === null || Roles::opensAdminSide($user->User_Type) || request()->is('notifications')) {
            return;
        }
        $unread = array_values(array_filter(NotificationController::mine($user->id), fn ($n) => !$n['read']));
        $this->unread = count($unread);
        $this->shown = array_slice($unread, 0, 3);
        $this->more = $this->unread - count($this->shown);
    }

    public function shouldRender(): bool
    {
        return $this->unread > 0;
    }

    public function render(): View
    {
        return view('components.notification-popup');
    }
}
