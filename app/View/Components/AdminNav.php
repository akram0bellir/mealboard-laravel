<?php

namespace App\View\Components;

use App\Http\Middleware\AdminPage;
use App\Support\Roles;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\Component;
use Illuminate\View\View;

/** The admin navigation: a sidebar for the super admin on wide screens, a top bar for everyone else. */
class AdminNav extends Component
{
    /** [href, label, icon] */
    private const ITEMS = [
        ['/admin', 'Dashboard', 'layout-grid'],
        ['/meals', 'Meal planning', 'calendar-days'],
        ['/users', 'Users', 'users'],
        ['/diners', 'Book people', 'user-plus'],
        ['/attendance', 'Attendance', 'user-x'],
        ['/serve', 'Serve', 'nfc'],
        ['/reports', 'Reports', 'message-square-text'],
        ['/statistics', 'Statistics', 'chart-column'],
        ['/invoices', 'Invoices', 'receipt-text'],
        ['/penalties', 'Penalties', 'gavel'],
        ['/admain_notifications', 'Notify', 'bell'],
    ];

    public array $items;

    public bool $super;

    public int $unread;

    public string $name;

    public string $role;

    public function __construct()
    {
        $user = Auth::user();
        $this->super = Roles::isSuper($user->User_Type);
        $items = $this->super ? [...self::ITEMS, ['/settings', 'Settings', 'settings'], ['/system', 'System', 'activity']] : self::ITEMS;
        $this->items = array_values(array_filter($items, fn ($i) => AdminPage::canOpen($user->User_Type, $i[0])));
        $this->unread = (int) DB::table('reports')->where('read', 0)->count();
        $this->name = $user->fullName();
        $this->role = match ($user->role()) {
            'super_admin' => 'Super admin',
            'manager' => 'Manager',
            default => 'Admin',
        };
    }

    public function render(): View
    {
        return view('components.admin-nav');
    }
}
