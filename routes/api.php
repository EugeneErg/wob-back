<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wob\Identity\Presentation\Http\Controller\AuthController;
use Wob\Identity\Presentation\Http\Middleware\ResolveDomainUser;
use Wob\Library\Presentation\Http\Controller\BundleController;
use Wob\Library\Presentation\Http\Controller\ChapterController;
use Wob\Library\Presentation\Http\Controller\LevelController;
use Wob\Library\Presentation\Http\Controller\AssetController;
use Wob\Library\Presentation\Http\Controller\StoryController;
use Wob\Media\Presentation\Http\Controller\MediaController;
use Wob\Progress\Presentation\Http\Controller\ProgressController;
use Wob\Achievements\Presentation\Http\Controller\AwardController;
use Wob\Publishing\Presentation\Http\Controller\CatalogController;
use Wob\Publishing\Presentation\Http\Controller\ForkController;
use Wob\Publishing\Presentation\Http\Controller\RecordController;
use Wob\Publishing\Presentation\Http\Controller\ReleaseController;
use Wob\Publishing\Presentation\Http\Controller\SlotController;
use Wob\Publishing\Presentation\Http\Controller\VoteController;

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

// The catalogue is open, and trimmed for whoever is looking. A signed-out
// visitor gets the first canonical story with one playable level in it; the
// rest of the content is not sent, so there is nothing in the browser to
// unlock. This is the only content route that does not require a session,
// because it is the one that has to work before there is one.
Route::get("catalog", [CatalogController::class, "index"]);
Route::get("catalog/{storyId}", [CatalogController::class, "play"]);

Route::middleware(ResolveDomainUser::class)->group(static function (): void {
    Route::get("auth/me", [AuthController::class, "me"]);
    Route::post("auth/logout", [AuthController::class, "signOut"]);

    // Covers and intros. Uploaded once, then referred to by id from a story,
    // a chapter or a level — the bytes never travel inside a bundle, which is
    // the entire reason this exists: a library export is a document, and a
    // sixty-megabyte video base64ed into it is not.
    Route::post("media", [MediaController::class, "upload"]);
    Route::get("media", [MediaController::class, "index"]);
    Route::get("media/{id}", [MediaController::class, "show"]);

    // The author's shelf of reusable pieces. Owned by them, not by any one
    // story, and shared across everything they make.
    Route::get("assets", [AssetController::class, "index"]);
    Route::post("assets", [AssetController::class, "store"]);
    Route::patch("assets/{assetId}", [AssetController::class, "update"]);
    Route::delete("assets/{assetId}", [AssetController::class, "destroy"]);

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
    Route::post("stories/{storyId}/points", [LevelController::class, "pin"]);
    Route::put("stories/{storyId}/levels/{levelId}", [LevelController::class, "save"]);
    Route::delete("stories/{storyId}/chapters/{chapterId}/levels/{levelId}", [LevelController::class, "destroy"]);

    Route::get("content/levels/{hash}", [LevelController::class, "byHash"])
        ->where("hash", "[0-9a-f]{8}");

    // Publishing. The handler behind this existed for a long time with no
    // route to it, so an author had no way to release anything at all.
    Route::post("stories/{storyId}/publish", [ReleaseController::class, "store"]);
    Route::get("stories/{storyId}/releases", [ReleaseController::class, "index"]);

    // Leaderboards. Scoped to a release, because times are only comparable
    // within one frozen version of the content.
    Route::get("releases/{releaseId}/records", [RecordController::class, "index"]);
    Route::post("releases/{releaseId}/records", [RecordController::class, "store"]);

    // Rating a level, and how the release is doing against the canon bar.
    Route::post("releases/{releaseId}/levels/{levelId}/vote", [VoteController::class, "store"]);
    Route::get("releases/{releaseId}/standing", [VoteController::class, "standing"]);

    // Editing someone else's story, and offering the result back. The fork is
    // born on the first change, never on merely opening the editor.
    Route::post("releases/{releaseId}/edit", [ForkController::class, "edit"]);
    Route::post("forks/{forkStoryId}/propose", [ForkController::class, "propose"]);
    Route::get("stories/{storyId}/pull-requests", [ForkController::class, "index"]);
    Route::post("pull-requests/{pullRequestId}/decide", [ForkController::class, "decide"]);

    // Save slots: one story's worth of parallel runs.
    Route::get("stories/{storyId}/slots", [SlotController::class, "index"]);
    Route::post("stories/{storyId}/slots", [SlotController::class, "create"]);
    Route::patch("slots/{slotId}", [SlotController::class, "update"]);
    Route::post("slots/{slotId}/erase", [SlotController::class, "erase"]);
    Route::delete("slots/{slotId}", [SlotController::class, "destroy"]);

    // Achievements: what you have earned, and the standing across everyone.
    Route::get("me/awards", [AwardController::class, "mine"]);
    Route::get("ranking", [AwardController::class, "ranking"]);

    Route::get("progress", [ProgressController::class, "index"]);
    Route::post("progress/complete", [ProgressController::class, "complete"]);
});
