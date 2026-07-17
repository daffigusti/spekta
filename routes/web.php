<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RateCardController;
use App\Http\Controllers\WizardController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    // Wizard (FR-01..FR-07)
    Route::get('projects/{project}/wizard', [WizardController::class, 'show'])->name('projects.wizard');
    Route::get('projects/{project}/structure', [WizardController::class, 'structure'])->name('projects.structure');
    Route::get('projects/{project}/stack', [WizardController::class, 'stack'])->name('projects.stack');
    Route::get('projects/{project}/wireframes', [ProjectController::class, 'wireframes'])->name('projects.wireframes');
    Route::post('projects/{project}/wizard/input', [WizardController::class, 'saveInput'])->name('wizard.input');
    Route::post('projects/{project}/wizard/understanding', [WizardController::class, 'confirmUnderstanding'])->name('wizard.understanding');
    Route::post('projects/{project}/wizard/interview/answer', [WizardController::class, 'answerInterview'])->name('wizard.interview.answer');
    Route::post('projects/{project}/wizard/interview/finish', [WizardController::class, 'finishInterview'])->name('wizard.interview.finish');
    Route::post('projects/{project}/wizard/nodes', [WizardController::class, 'nodeStore'])->name('wizard.nodes.store');
    Route::patch('projects/{project}/wizard/nodes/{nodeId}', [WizardController::class, 'nodeUpdate'])->name('wizard.nodes.update');
    Route::delete('projects/{project}/wizard/nodes/{nodeId}', [WizardController::class, 'nodeDestroy'])->name('wizard.nodes.destroy');
    Route::post('projects/{project}/wizard/structure/confirm', [WizardController::class, 'confirmStructure'])->name('wizard.structure.confirm');
    Route::post('projects/{project}/generate-missing', [WizardController::class, 'generateMissing'])->name('projects.generate.missing');
    Route::patch('projects/{project}/wizard/stack/{layer}', [WizardController::class, 'stackUpdate'])->name('wizard.stack.update');
    Route::post('projects/{project}/wizard/stack/confirm', [WizardController::class, 'confirmStack'])->name('wizard.stack.confirm');
    Route::post('projects/{project}/generate', [WizardController::class, 'generate'])->name('projects.generate');
    Route::post('projects/{project}/generate/resume', [WizardController::class, 'resumeRun'])->name('projects.generate.resume');
    Route::get('projects/{project}/run-status', [WizardController::class, 'runStatus'])->name('projects.run-status');

    // Dokumen (FR-08)
    Route::post('documents/{document}/versions', [DocumentController::class, 'storeVersion'])->name('documents.versions.store');
    Route::get('documents/{document}/versions/{versionNo}', [DocumentController::class, 'showVersion'])->name('documents.versions.show');
    Route::post('documents/{document}/versions/{versionNo}/restore', [DocumentController::class, 'restoreVersion'])->name('documents.versions.restore');

    // Estimasi (FR-13/FR-14)
    Route::get('projects/{project}/estimate', [EstimateController::class, 'show'])->name('projects.estimate');
    Route::post('projects/{project}/estimate/recompute', [EstimateController::class, 'recompute'])->name('projects.estimate.recompute');
    Route::patch('projects/{project}/estimates/{estimateId}/lines/{lineId}', [EstimateController::class, 'overrideLine'])->name('projects.estimate.override');

    // Rate card
    Route::get('ratecards', [RateCardController::class, 'index'])->name('ratecards.index');
    Route::patch('ratecards/{rateCardId}', [RateCardController::class, 'update'])->name('ratecards.update');

    // Export (FR-21)
    Route::get('projects/{project}/export/{kind}', [ExportController::class, 'download'])->name('projects.export');

    // Share ke portal klien (FR-17)
    Route::post('projects/{project}/share', [\App\Http\Controllers\ShareController::class, 'store'])->name('projects.share');
    Route::delete('projects/{project}/share/{link}', [\App\Http\Controllers\ShareController::class, 'revoke'])->name('projects.share.revoke');

    // Asisten AI (FR-09 subset chat)
    Route::post('projects/{project}/assistant', [\App\Http\Controllers\AssistantController::class, 'chat'])->name('projects.assistant');

    // Billing (FR-23)
    Route::get('billing', [\App\Http\Controllers\BillingController::class, 'index'])->name('billing.index');
    Route::post('billing/checkout', [\App\Http\Controllers\BillingController::class, 'checkout'])->name('billing.checkout');

    // Change request (FR-20)
    Route::post('projects/{project}/change-requests', [\App\Http\Controllers\ChangeRequestController::class, 'store'])->name('projects.cr.store');
    Route::patch('projects/{project}/change-requests/{crId}', [\App\Http\Controllers\ChangeRequestController::class, 'update'])->name('projects.cr.update');
    Route::post('projects/{project}/change-requests/{crId}/reject', [\App\Http\Controllers\ChangeRequestController::class, 'reject'])->name('projects.cr.reject');
});

// Webhook Midtrans — tanpa auth/CSRF, verifikasi signature sha512 di service (FR-23)
Route::post('midtrans/notify', [\App\Http\Controllers\BillingController::class, 'notify'])->name('midtrans.notify');

// Portal klien — tanpa auth, akses token + OTP (FR-17/18/19, BR-40)
Route::prefix('portal/{token}')->group(function () {
    Route::get('/', [\App\Http\Controllers\PortalController::class, 'show'])->name('portal.show');
    Route::post('request-otp', [\App\Http\Controllers\PortalController::class, 'requestOtp'])->name('portal.otp.request');
    Route::post('verify', [\App\Http\Controllers\PortalController::class, 'verifyOtp'])->name('portal.otp.verify');
    Route::post('comments', [\App\Http\Controllers\PortalController::class, 'comment'])->name('portal.comments');
    Route::post('approve', [\App\Http\Controllers\PortalController::class, 'approveDocument'])->name('portal.approve');
    Route::post('approve-all', [\App\Http\Controllers\PortalController::class, 'approveAll'])->name('portal.approve-all');
    Route::post('change-requests', [\App\Http\Controllers\PortalController::class, 'proposeChangeRequest'])->name('portal.cr.propose');
    Route::post('change-requests/{crId}/decide', [\App\Http\Controllers\PortalController::class, 'decideChangeRequest'])->name('portal.cr.decide');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
