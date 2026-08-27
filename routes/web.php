<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

// Custom Controllers
use App\Http\Controllers\FoodLogController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\SimpleMealController;
use App\Http\Controllers\MobileEntryController;

use App\Http\Controllers\ExerciseController;
use App\Http\Controllers\LiftLogController;
use App\Http\Controllers\BodyLogController;
use App\Http\Controllers\MeasurementTypeController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\WorkoutController;
use App\Http\Controllers\FeedController;

// Breeze Routes
Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('mobile-entry.lifts');
    }
    return redirect()->route('login');
})->name('home');



Route::middleware(['auth', 'verified'])->group(function () {
    // Breeze Profile Routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('/profile/preferences', [ProfileController::class, 'editPreferences'])->name('profile.edit-preferences');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.update-preferences');
    Route::post('/profile/photo', [ProfileController::class, 'updatePhoto'])->name('profile.update-photo');
    Route::delete('/profile/photo', [ProfileController::class, 'deletePhoto'])->name('profile.delete-photo');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Connection routes
    Route::get('/connections', [ProfileController::class, 'connections'])->name('connections.index');
    Route::post('/connections/generate-token', [ProfileController::class, 'generateConnectionToken'])->name('connections.generate-token');
    Route::get('/connections/connect/{token}', [ProfileController::class, 'connectViaToken'])->name('connections.connect.get');
    Route::post('/connections/connect/{token}', [ProfileController::class, 'connectViaToken'])->name('connections.connect');

    // Custom Application Routes (Protected by 'auth' middleware)

    // Feed
    Route::get('feed', [FeedController::class, 'index'])->name('feed.index');
    Route::post('feed/pr/{personalRecord}/high-five', [FeedController::class, 'toggleHighFive'])->name('feed.toggle-high-five');
    Route::post('feed/pr/{personalRecord}/comment', [FeedController::class, 'storeComment'])->name('feed.store-comment');
    Route::delete('feed/comment/{comment}', [FeedController::class, 'deleteComment'])->name('feed.delete-comment');
    Route::get('feed/users', [FeedController::class, 'users'])->name('feed.users');
    Route::get('feed/users/{user}', [FeedController::class, 'showUser'])->name('feed.users.show');
    Route::post('feed/users/{user}/follow', [FeedController::class, 'followUser'])->name('feed.users.follow');
    Route::delete('feed/users/{user}/unfollow', [FeedController::class, 'unfollowUser'])->name('feed.users.unfollow');
    
    // Notifications
    Route::get('notifications', [FeedController::class, 'notifications'])->name('notifications.index');

    Route::post('food-logs/add-meal', [FoodLogController::class, 'addMealToLog'])->name('food-logs.add-meal');

    Route::get('food-logs/{food_log}/edit', [FoodLogController::class, 'edit'])->name('food-logs.edit');
    Route::post('food-logs', [FoodLogController::class, 'store'])->name('food-logs.store');
    Route::put('food-logs/{food_log}', [FoodLogController::class, 'update'])->name('food-logs.update');
    Route::delete('food-logs/{food_log}', [FoodLogController::class, 'destroy'])->name('food-logs.destroy');


    

    Route::resource('ingredients', IngredientController::class)->except([
        'show'
    ]);

    // Core meal routes (replacing existing MealController routes)
    Route::get('meals', [SimpleMealController::class, 'index'])
        ->name('meals.index');
    Route::get('meals/create', [SimpleMealController::class, 'create'])
        ->name('meals.create');
    Route::post('meals', [SimpleMealController::class, 'store'])
        ->name('meals.store');
    Route::get('meals/{meal}/edit', [SimpleMealController::class, 'edit'])
        ->name('meals.edit');
    Route::delete('meals/{meal}', [SimpleMealController::class, 'destroy'])
        ->name('meals.destroy');

    // Ingredient management routes
    Route::get('meals/{meal}/add-ingredient', [SimpleMealController::class, 'addIngredient'])
        ->name('meals.add-ingredient');
    Route::post('meals/{meal}/store-ingredient', [SimpleMealController::class, 'storeIngredient'])
        ->name('meals.store-ingredient');
    Route::delete('meals/{meal}/ingredients/{ingredient}', [SimpleMealController::class, 'removeIngredient'])
        ->name('meals.remove-ingredient');



    Route::resource('body-logs', BodyLogController::class)->except(['show', 'index']);
    Route::get('body-logs/type/{measurementType}', [BodyLogController::class, 'showByType'])->name('body-logs.show-by-type');
    Route::resource('measurement-types', MeasurementTypeController::class)->except(['show']);

    Route::resource('exercises', ExerciseController::class)->except(['show']);
    Route::post('exercises/{exercise}/promote', [ExerciseController::class, 'promote'])->name('exercises.promote');
    Route::post('exercises/{exercise}/unpromote', [ExerciseController::class, 'unpromote'])->name('exercises.unpromote');
    Route::get('exercises/{exercise}/logs', [ExerciseController::class, 'showLogs'])->name('exercises.show-logs');
    Route::get('exercises/{exercise}/merge', [ExerciseController::class, 'showMerge'])->name('exercises.show-merge');
    Route::post('exercises/{exercise}/merge', [ExerciseController::class, 'merge'])->name('exercises.merge');

    Route::resource('lift-logs', LiftLogController::class)->except(['show']);

    // Exercise Matching Aliases
    Route::get('exercise-aliases/create', [App\Http\Controllers\ExerciseMatchingAliasController::class, 'create'])->name('exercise-aliases.create');
    Route::get('exercise-aliases/store', [App\Http\Controllers\ExerciseMatchingAliasController::class, 'store'])->name('exercise-aliases.store');
    Route::post('exercise-aliases/create-and-link', [App\Http\Controllers\ExerciseMatchingAliasController::class, 'createAndLink'])->name('exercise-aliases.create-and-link');

    // Exercise Recommendations
    Route::get('recommendations', [RecommendationController::class, 'index'])->name('recommendations.index');

    // Workouts - Simple Mode
    Route::get('workouts/create-simple', [App\Http\Controllers\SimpleWorkoutController::class, 'create'])
        ->name('workouts.create-simple');
    Route::post('workouts/store-simple', [App\Http\Controllers\SimpleWorkoutController::class, 'store'])
        ->name('workouts.store-simple');
    Route::get('workouts/{workout}/edit-simple', [App\Http\Controllers\SimpleWorkoutController::class, 'edit'])
        ->name('workouts.edit-simple');
    Route::put('workouts/{workout}/update-simple', [App\Http\Controllers\SimpleWorkoutController::class, 'update'])
        ->name('workouts.update-simple');
    
    // Simple workout exercise management
    Route::get('workouts/new/add-exercise', [App\Http\Controllers\SimpleWorkoutController::class, 'addExercise'])
        ->name('simple-workouts.add-exercise-new');
    Route::post('workouts/new/create-exercise', [App\Http\Controllers\SimpleWorkoutController::class, 'createExercise'])
        ->name('simple-workouts.create-exercise-new');
    Route::get('workouts/{workout}/add-exercise', [App\Http\Controllers\SimpleWorkoutController::class, 'addExercise'])
        ->name('simple-workouts.add-exercise');
    Route::post('workouts/{workout}/create-exercise', [App\Http\Controllers\SimpleWorkoutController::class, 'createExercise'])
        ->name('simple-workouts.create-exercise');
    Route::get('workouts/{workout}/exercises/{exercise}/move', [App\Http\Controllers\SimpleWorkoutController::class, 'moveExercise'])
        ->name('simple-workouts.move-exercise');
    Route::delete('workouts/{workout}/exercises/{exercise}', [App\Http\Controllers\SimpleWorkoutController::class, 'removeExercise'])
        ->name('simple-workouts.remove-exercise');
    
    // Workouts - Advanced Mode (WOD Syntax)
    Route::resource('workouts', WorkoutController::class)->except(['show']);

    // Mobile Entry - Supports date parameter
    Route::get('mobile-entry/lifts', [MobileEntryController::class, 'lifts'])->name('mobile-entry.lifts');
    Route::get('mobile-entry/foods', [MobileEntryController::class, 'foods'])->name('mobile-entry.foods');
    Route::get('mobile-entry/measurements', [MobileEntryController::class, 'measurements'])->name('mobile-entry.measurements');
    
    // Mobile Entry Helper Routes
    Route::post('mobile-entry/create-exercise', [MobileEntryController::class, 'createExercise'])->name('mobile-entry.create-exercise');
    
    // Food Entry Helper Routes
    Route::post('mobile-entry/create-ingredient', [MobileEntryController::class, 'createIngredient'])->name('mobile-entry.create-ingredient');
    Route::get('food-logs/create/ingredient/{ingredient}', [FoodLogController::class, 'createIngredientForm'])->name('food-logs.create-ingredient');
    Route::get('food-logs/create/meal/{meal}', [FoodLogController::class, 'createMealForm'])->name('food-logs.create-meal');

});

// Google OAuth routes
Route::get('auth/google', [App\Http\Controllers\Auth\SocialiteController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('auth/google/callback', [App\Http\Controllers\Auth\SocialiteController::class, 'handleGoogleCallback'])->name('auth.google.callback');

use App\Http\Controllers\DebugController;

Route::get('/debug/email', [DebugController::class, 'previewFirstLiftEmail']);

require __DIR__.'/auth.php';

Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::resource('users', UserController::class);
    Route::get('users/{user}/impersonate', [UserController::class, 'impersonate'])->name('users.impersonate');
});

Route::get('users/impersonate/leave', [UserController::class, 'leaveImpersonate'])->name('users.leave-impersonate');
Route::get('/magic-login/{token}', [App\Http\Controllers\MagicLoginController::class, 'login']);
