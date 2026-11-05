<?php

use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DinerController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\MealController;
use App\Http\Controllers\Admin\NotifyController;
use App\Http\Controllers\Admin\PenaltyController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\ServeController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StatisticsController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\LangController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\WeekController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/first-password', [AuthController::class, 'firstPassword']);
Route::post('/first-password/cancel', [AuthController::class, 'cancelFirstPassword']);
Route::post('/lang', [LangController::class, 'set']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');

// Photos are public, like the PHP API's files route.
Route::get('/files/{table}/{id}/{name}', [FileController::class, 'show']);

// Employee pages.
Route::middleware(['auth', 'side:employee'])->group(function () {
    Route::controller(WeekController::class)->group(function () {
        Route::get('/week', 'show');
        Route::post('/week/review', 'review');
        Route::post('/week/apply', 'apply');
        Route::get('/week/cancel/{date}', 'cancelDialog')->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}');
        Route::post('/week/cancel/{date}', 'cancel')->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}');
    });

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'readAll']);

    Route::get('/report', [ReportController::class, 'show']);
    Route::post('/report', [ReportController::class, 'store']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile/password', [ProfileController::class, 'password']);
    Route::post('/profile/sandwiches', [ProfileController::class, 'sandwiches']);

    Route::get('/team', [TeamController::class, 'show']);
    Route::post('/team/switch', [TeamController::class, 'switch']);
});

// Admin side. AdminPage limits managers to the dashboard, meals, reports and statistics.
Route::middleware(['auth', 'side:admin', 'admin.page'])->group(function () {
    Route::get('/admin', [DashboardController::class, 'show']);
    Route::post('/admin/orders-email', [DashboardController::class, 'emailOrders']);

    Route::get('/meals', [MealController::class, 'index']);
    Route::post('/meals/slot', [MealController::class, 'saveSlot']);
    Route::post('/meals/slot/remove', [MealController::class, 'removeSlot']);
    Route::post('/meals/place', [MealController::class, 'place']);
    Route::post('/meals/copy', [MealController::class, 'copy']);
    Route::post('/meals/templates', [MealController::class, 'storeTemplate']);
    Route::post('/meals/templates/{id}/delete', [MealController::class, 'destroyTemplate']);
    Route::post('/meals/special', [MealController::class, 'saveSpecial']);
    Route::post('/meals/special/{id}', [MealController::class, 'saveSpecial']);
    Route::post('/meals/special/{id}/delete', [MealController::class, 'destroySpecial']);

    Route::get('/diners', [DinerController::class, 'index']);
    Route::post('/diners', [DinerController::class, 'store']);
    Route::post('/diners/{id}/remove', [DinerController::class, 'remove']);

    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::post('/attendance/{id}', [AttendanceController::class, 'toggle']);

    Route::get('/penalties', [PenaltyController::class, 'index']);
    Route::get('/penalties/booked', [PenaltyController::class, 'bookedJson']);
    Route::post('/penalties', [PenaltyController::class, 'store']);
    Route::post('/penalties/{id}/delete', [PenaltyController::class, 'destroy'])->where('id', '[a-z0-9]+\|[1-4]');

    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings/prices', [SettingsController::class, 'prices']);
    Route::post('/settings/invoice', [SettingsController::class, 'invoice']);
    Route::post('/settings/rules', [SettingsController::class, 'rules']);
    Route::post('/settings/ramadan', [SettingsController::class, 'ramadan']);
    Route::post('/settings/groups', [SettingsController::class, 'addGroup']);
    Route::post('/settings/groups/{id}/delete', [SettingsController::class, 'deleteGroup']);
    Route::post('/settings/groups/{id}/ramadan', [SettingsController::class, 'ramadanTimes']);
    Route::post('/settings/departments', [SettingsController::class, 'departments']);
    Route::post('/settings/smtp', [SettingsController::class, 'smtp']);
    Route::post('/settings/daily', [SettingsController::class, 'daily']);
    Route::post('/settings/managers/weekly', [SettingsController::class, 'managerWeekly']);
    Route::post('/settings/managers/day', [SettingsController::class, 'managerDay']);
    Route::post('/settings/managers/day/{id}/delete', [SettingsController::class, 'managerDayDelete']);

    Route::get('/system', [SystemController::class, 'index']);
    Route::post('/system/cron', [SystemController::class, 'runCron']);
    Route::post('/system/emails', [SystemController::class, 'sendEmails']);
    Route::post('/system/backup', [SystemController::class, 'backup']);

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/csv', [InvoiceController::class, 'csv']);
    Route::post('/invoices/sent', [InvoiceController::class, 'toggleSent']);

    Route::get('/statistics', [StatisticsController::class, 'index']);
    Route::get('/statistics/csv', [StatisticsController::class, 'csv']);
    Route::get('/statistics/explore.csv', [StatisticsController::class, 'exploreCsv']);
    Route::post('/statistics/explore/save', [StatisticsController::class, 'exploreSave']);
    Route::post('/statistics/explore/delete', [StatisticsController::class, 'exploreDelete']);

    Route::get('/serve', [ServeController::class, 'show']);
    Route::post('/serve/badge', [ServeController::class, 'badge']);
    Route::post('/serve/person', [ServeController::class, 'person']);
    Route::post('/serve/link', [ServeController::class, 'link']);
    Route::post('/serve/relay/test', [ServeController::class, 'relayTest']);
    Route::post('/serve/relay/choose', [ServeController::class, 'relayChoose']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::post('/users/import', [UserController::class, 'import']);
    Route::post('/users/{id}', [UserController::class, 'update']);
    Route::post('/users/{id}/strike', [UserController::class, 'strike']);
    Route::post('/users/{id}/lift', [UserController::class, 'lift']);
    Route::post('/users/{id}/allow', [UserController::class, 'allow']);
    Route::post('/users/{id}/active', [UserController::class, 'active']);
    Route::post('/users/{id}/delete', [UserController::class, 'destroy']);

    Route::get('/reports', [AdminReportController::class, 'index']);
    Route::post('/reports/{id}/read', [AdminReportController::class, 'mark']);
    Route::post('/reports/{id}/reply', [AdminReportController::class, 'reply']);

    Route::get('/admain_notifications', [NotifyController::class, 'index']);
    Route::post('/admain_notifications', [NotifyController::class, 'send']);
});
