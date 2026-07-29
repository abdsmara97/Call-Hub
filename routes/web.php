<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\EmergencyLogExportController;
use App\Http\Controllers\EmployeeImportTemplateController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\StartDirectMessageController;
use App\Livewire\Admin\EmergencyBroadcast;
use App\Livewire\Admin\EmergencyLog;
use App\Livewire\Admin\HubSettings;
use App\Livewire\Admin\MisuseReport;
use App\Livewire\Admin\UserImport;
use App\Livewire\Admin\UserManager;
use App\Livewire\Auth\RotatePassword;
use App\Livewire\Directory;
use App\Livewire\Hub\Workspace;
use App\Livewire\Mentions;
use App\Livewire\ProfileSettings;
use App\Livewire\SavedMessages;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/hub');

Route::middleware('auth')->group(function () {
    // Reachable while `must_change_password` is set; everything else is not.
    Route::get('password/rotate', RotatePassword::class)->name('password.rotate');

    Route::get('hub', Workspace::class)->name('hub');
    Route::get('hub/rooms/{room}', Workspace::class)->name('rooms.show');
    Route::get('hub/dm/{user}', StartDirectMessageController::class)->name('dm.start');

    Route::get('directory', Directory::class)->name('directory');
    Route::get('mentions', Mentions::class)->name('mentions');
    Route::get('saved', SavedMessages::class)->name('saved');
    Route::get('profile', ProfileSettings::class)->name('profile');

    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])
        ->name('push-subscriptions.store');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])
        ->name('push-subscriptions.destroy');

    // Signed + membership-checked. Attachments never sit on a public disk.
    Route::get('attachments/{attachment}', AttachmentController::class)
        ->middleware('signed')
        ->name('attachments.show');

    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('users', UserManager::class)->name('users');
        Route::get('users/import', UserImport::class)->name('import');
        Route::get('users/import/template', EmployeeImportTemplateController::class)
            ->name('import.template');
        Route::get('broadcast', EmergencyBroadcast::class)->name('broadcast');
        Route::get('emergency-log', EmergencyLog::class)->name('emergency-log');
        Route::get('emergency-log/export', EmergencyLogExportController::class)->name('emergency-log.export');
        Route::get('misuse', MisuseReport::class)->name('misuse');
        Route::get('settings', HubSettings::class)->name('settings');
    });
});

require __DIR__.'/auth.php';
