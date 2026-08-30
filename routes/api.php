<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wob\Identity\Presentation\Http\Controller\AuthController;
use Wob\Identity\Presentation\Http\Middleware\ResolveDomainUser;
use Wob\Library\Presentation\Http\Controller\BundleController;
use Wob\Library\Presentation\Http\Controller\ChapterController;
use Wob\Library\Presentation\Http\Controller\LevelController;
use Wob\Library\Presentation\Http\Controller\StoryController;
use Wob\Progress\Presentation\Http\Controller\ProgressController;

/*
 * Signing in is the only thing that happens without a session.
 *
 * Content addressed by hash was public in the first draft, on the grounds that a
 * hash names one exact set of bytes and so cannot return the wrong version. True,
 * but beside the point: the fingerprint is thirty-two bits, which is walkable in
 * an afternoon, and every unpublished draft in the database sits behind one. So
 * it needs a session and returns only what the caller owns.
 *
 * This gets to be genuinely public once releases exist — a released story is
 * meant to be played by strangers, and that is the thing worth caching forever.
 */

Route::post("auth/google", [AuthController::class, "google"]);

Route::middleware(ResolveDomainUser::class)->group(static function (): void {
    Route::get("auth/me", [AuthController::class, "me"]);
    Route::post("auth/logout", [AuthController::class, "signOut"]);

    Route::get("library", [StoryController::class, "shelf"]);

    // Files. Import is how a library that has lived in localStorage since the
    // beginning moves into an account, so it comes before releases or records.
    Route::get("library/export", [BundleController::class, "exportLibrary"]);
    Route::post("library/import", [BundleController::class, "import"]);

    Route::post("stories", [StoryController::class, "create"]);
    Route::get("stories/{storyId}", [StoryController::class, "show"]);
    Route::patch("stories/{storyId}", [StoryController::class, "update"]);
    Route::delete("stories/{storyId}", [StoryController::class, "destroy"]);

    Route::get("stories/{storyId}/export", [BundleController::class, "exportStory"]);

    Route::post("stories/{storyId}/chapters", [ChapterController::class, "create"]);
    Route::put("stories/{storyId}/chapters/{chapterId}/map", [ChapterController::class, "saveMap"]);
    Route::delete("stories/{storyId}/chapters/{chapterId}", [ChapterController::class, "destroy"]);

    Route::post("stories/{storyId}/levels", [LevelController::class, "create"]);
    Route::put("stories/{storyId}/levels/{levelId}", [LevelController::class, "save"]);
    Route::delete("stories/{storyId}/chapters/{chapterId}/levels/{levelId}", [LevelController::class, "destroy"]);

    Route::get("content/levels/{hash}", [LevelController::class, "byHash"])
        ->where("hash", "[0-9a-f]{8}");

    Route::get("progress", [ProgressController::class, "index"]);
    Route::post("progress/complete", [ProgressController::class, "complete"]);
});
