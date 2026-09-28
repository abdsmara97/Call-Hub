<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\CallCredentialController;
use App\Http\Controllers\CallSignalController;
use App\Http\Controllers\EmergencyLogExportController;
use App\Http\Controllers\EmployeeImportTemplateController;
use App\Http\Controllers\FormAnswerFileController;
use App\Http\Controllers\HuddleTokenController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\StartDirectMessageController;
use App\Livewire\Admin\EmergencyBroadcast;
use App\Livewire\Admin\EmergencyLog;
use App\Livewire\Admin\FormBuilder;
use App\Livewire\Admin\FormManager;
use App\Livewire\Admin\FormResponses;
use App\Livewire\Admin\HubSettings;
use App\Livewire\Admin\Invitations;
use App\Livewire\Admin\MisuseReport;
use App\Livewire\Admin\UserImport;
use App\Livewire\Admin\UserManager;
use App\Livewire\Auth\AcceptInvitation;
use App\Livewire\Auth\RegisterWorkspace;
use App\Livewire\Auth\RotatePassword;
use App\Livewire\Directory;
use App\Livewire\Hub\FormFill;
use App\Livewire\Hub\Workspace;
use App\Livewire\Mentions;
use App\Livewire\ProfileSettings;
use App\Livewire\SavedMessages;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/hub');

/*
 * Self-serve entry points. Signup creates a whole workspace (tenant); an
 * invitation link is how staff join an existing one. Both are guest routes —
 * the token, not a session, is what authorises an acceptance.
 */
Route::middleware('guest')->group(function () {
    Route::get('signup', RegisterWorkspace::class)->name('signup');
    Route::get('invitations/{token}', AcceptInvitation::class)->name('invitations.accept');
});

Route::middleware('auth')->group(function () {
    // Reachable while `must_change_password` is set; everything else is not.
    Route::get('password/rotate', RotatePassword::class)->name('password.rotate');

    Route::get('hub', Workspace::class)->name('hub');
    Route::get('hub/rooms/{room}', Workspace::class)->name('rooms.show');
    Route::get('hub/dm/{user}', StartDirectMessageController::class)->name('dm.start');

    /*
     * Filling in a form. Authoring and reading live under /admin; this is the
     * one form screen an ordinary employee reaches, and it re-checks the policy
     * in mount() rather than trusting the link that got them here.
     */
    Route::get('forms/{form}', FormFill::class)->name('forms.show');

    // Signed + response-checked. Picture answers never sit on a public disk,
    // and are narrower than room attachments: membership alone is not enough.
    Route::get('forms/files/{file}', FormAnswerFileController::class)
        ->middleware('signed')
        ->name('forms.files.show');

    Route::get('directory', Directory::class)->name('directory');
    Route::get('mentions', Mentions::class)->name('mentions');
    Route::get('saved', SavedMessages::class)->name('saved');
    Route::get('profile', ProfileSettings::class)->name('profile');

    /*
     * Calling. POST throughout: nothing here is cacheable, and minting a relay
     * credential is an action rather than a resource read. Throttled because
     * the credential endpoint would otherwise be a free TURN faucet.
     */
    Route::middleware('throttle:calls')->group(function () {
        Route::post('calls/{room}/ring', [CallSignalController::class, 'ring'])->name('calls.ring');
        Route::post('calls/{room}/cancel', [CallSignalController::class, 'cancel'])->name('calls.cancel');
        Route::post('calls/{room}/ice-servers', CallCredentialController::class)->name('calls.ice-servers');
    });

    /*
     * Huddles. One endpoint, because there is only one moment that needs us:
     * minting a join token. Leaving is something LiveKit observes and reports
     * over the webhook, so there is nothing here for it.
     *
     * Its own limiter rather than 'calls' — see AppServiceProvider.
     */
    Route::middleware('throttle:huddles')->group(function () {
        Route::post('huddles/{room}/join', HuddleTokenController::class)->name('huddles.join');
    });

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
        Route::get('users/invitations', Invitations::class)->name('invitations');
        Route::get('users/import', UserImport::class)->name('import');
        Route::get('users/import/template', EmployeeImportTemplateController::class)
            ->name('import.template');
        // Forms are written and read here. Sending one to people happens from a
        // conversation, which is where the audience is decided.
        Route::get('forms', FormManager::class)->name('forms');
        Route::get('forms/create', FormBuilder::class)->name('forms.create');
        Route::get('forms/{form}/responses', FormResponses::class)->name('forms.responses');

        Route::get('broadcast', EmergencyBroadcast::class)->name('broadcast');
        Route::get('emergency-log', EmergencyLog::class)->name('emergency-log');
        Route::get('emergency-log/export', EmergencyLogExportController::class)->name('emergency-log.export');
        Route::get('misuse', MisuseReport::class)->name('misuse');
        Route::get('settings', HubSettings::class)->name('settings');
    });
});

require __DIR__.'/auth.php';
