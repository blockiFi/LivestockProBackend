<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FarmController;
use App\Http\Controllers\Api\FlockController;
use App\Http\Controllers\Api\PoultryTypeController;
use App\Http\Controllers\Api\FlockStageController;
use App\Http\Controllers\Api\PoultryHouseController;
use App\Http\Controllers\Api\PoultryController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\VaccineController;
use App\Http\Controllers\Api\VaccineProductController;
use App\Http\Controllers\Api\VaccineInventoryController;
use App\Http\Controllers\Api\MedicationProductController;
use App\Http\Controllers\Api\MedicationInventoryController;
use App\Http\Controllers\Api\PoultryMedicationController;
use App\Http\Controllers\Api\FeedTypeController;
use App\Http\Controllers\Api\FeedInventoryController;
use App\Http\Controllers\Api\FeedUsageController;
use App\Http\Controllers\Api\FlockWeightReportController;
use App\Http\Controllers\Api\FlockEggReportController;
use App\Http\Controllers\Api\FlockMortalityReportController;
use App\Http\Controllers\Api\FeedingScheduleController;
use App\Http\Controllers\Api\FeedingScheduleItemController;
use App\Http\Controllers\Api\FeedingBatchScheduleController;
use App\Http\Controllers\Api\FeedingBatchScheduleItemController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\ScheduleItemController;
use App\Http\Controllers\Api\BatchScheduleController;
use App\Http\Controllers\Api\BatchScheduleItemController;
use App\Http\Controllers\Api\FlockDailyRecordController;
use App\Http\Controllers\Api\PoultryMedicationRecordController;
use App\Http\Controllers\Api\PoultryVaccinationRecordController;
use App\Http\Controllers\Api\AdministrationMethodController;
use App\Http\Controllers\Api\InvoiceController;



Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);
Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('update-password', [AuthController::class, 'updatePassword']);
    Route::get('user', [AuthController::class, 'user']);
    
    // Get user's farms
    Route::get('/farms', [FarmController::class, 'getUserFarms']);
    
    // Farm CRUD operations
    Route::post('/farms', [FarmController::class, 'store']);
    
    // Farm-specific routes that require membership

    Route::prefix('flock-stages')->group(function () {
        Route::get('/', [FlockStageController::class, 'index']);
        Route::get('/paginated', [FlockStageController::class, 'index'])->defaults('pagination', true);
        Route::get('/{flockStage}', [FlockStageController::class, 'show']);
    });


    Route::middleware('farm.member')->group(function () {
        Route::get('/farms/{farm}', [FarmController::class, 'show']);
        Route::put('/farms/{farm}', [FarmController::class, 'update']);
        Route::delete('/farms/{farm}', [FarmController::class, 'destroy']);
        
        // Farm statistics
        Route::get('/farms/{farm}/statistics', [FarmController::class, 'getStatistics']);
        
        // Poultry statistics
        Route::get('/farms/{farm}/poultry-statistics', [PoultryController::class, 'getStatistics']);
        Route::get('/farms/{farm}/poultry-statistics/{dateParams?}', [PoultryController::class, 'getStatistics']);
        
        // Farm user management
        Route::get('/farms/{farm}/users', [FarmController::class, 'getFarmUsers']);
        Route::post('/farms/{farm}/users', [FarmController::class, 'addUser']);
        Route::delete('/farms/{farm}/users', [FarmController::class, 'removeUser']);

        // Flock routes
        Route::prefix('farms/{farm}/flocks')->group(function () {
            Route::get('/', [FlockController::class, 'index']);
            Route::get('/paginated', [FlockController::class, 'index'])->defaults('paginated', true);
            Route::post('/', [FlockController::class, 'store']);
            Route::get('/{flock}/get', [FlockController::class, 'show']);
            Route::put('/{flock}', [FlockController::class, 'update']);
            Route::delete('/{flock}', [FlockController::class, 'destroy']);
            Route::get('/{flock}/statistics', [FlockController::class, 'getStatistics']);
            Route::get('/{flock}/timeline', [FlockController::class, 'getTimeline']);
            Route::put('/{flock}/status', [FlockController::class, 'updateStatus']);
            Route::get('/{flock}/performance', [FlockController::class, 'getPerformanceMetrics']);
            Route::get('/{flock}/actual-quantity', [FlockController::class, 'getActualQuantity']);
        });

        // Poultry Type routes
        Route::prefix('farms/{farm}/poultry-types')->group(function () {
            Route::get('/', [PoultryTypeController::class, 'index']);
            Route::post('/', [PoultryTypeController::class, 'store']);
            Route::get('/{poultryType}', [PoultryTypeController::class, 'show']);
            Route::put('/{poultryType}', [PoultryTypeController::class, 'update']);
            Route::delete('/{poultryType}', [PoultryTypeController::class, 'destroy']);
            Route::get('/statistics', [PoultryTypeController::class, 'statistics']);
        });

        // Flock Daily Records routes
        Route::prefix('farms/{farm}/flock-daily-records')->group(function () {
            Route::get('/', [FlockDailyRecordController::class, 'index']);
            Route::post('/', [FlockDailyRecordController::class, 'store']);
            Route::get('/{flockDailyRecord}', [FlockDailyRecordController::class, 'show']);
            Route::put('/{flockDailyRecord}', [FlockDailyRecordController::class, 'update']);
            Route::delete('/{flockDailyRecord}', [FlockDailyRecordController::class, 'destroy']);
        });

        // Medication Records routes
        Route::prefix('farms/{farm}/medication-records')->group(function () {
            Route::get('/', [PoultryMedicationRecordController::class, 'index']);
            Route::post('/', [PoultryMedicationRecordController::class, 'store']);
            Route::get('/{medicationRecord}', [PoultryMedicationRecordController::class, 'show']);
            Route::put('/{medicationRecord}', [PoultryMedicationRecordController::class, 'update']);
            Route::delete('/{medicationRecord}', [PoultryMedicationRecordController::class, 'destroy']);
        });

        // Vaccination Records routes
        Route::prefix('farms/{farm}/vaccination-records')->group(function () {
            Route::get('/', [PoultryVaccinationRecordController::class, 'index']);
            Route::post('/', [PoultryVaccinationRecordController::class, 'store']);
            Route::get('/{vaccinationRecord}', [PoultryVaccinationRecordController::class, 'show']);
            Route::put('/{vaccinationRecord}', [PoultryVaccinationRecordController::class, 'update']);
            Route::delete('/{vaccinationRecord}', [PoultryVaccinationRecordController::class, 'destroy']);
        });
  

    

    // Flock Stage routes
  

    // Poultry House routes
    Route::prefix('poultry-houses')->group(function () {
        Route::get('/{farm}/{pagination?}', [PoultryHouseController::class, 'index']);
        Route::post('/{farm}', [PoultryHouseController::class, 'store']);
        Route::get('/{farm}/{poultryHouse}', [PoultryHouseController::class, 'show']);
        Route::put('/{farm}/{poultryHouse}', [PoultryHouseController::class, 'update']);
        Route::delete('/{farm}/{poultryHouse}', [PoultryHouseController::class, 'destroy']);
        Route::get('/{farm}/{poultryHouse}/statistics', [PoultryHouseController::class, 'getStatistics']);
    });

    // Permission Management Routes
    Route::prefix('permissions')->group(function () {
        Route::get('/{farm}', [PermissionController::class, 'index']);
        Route::get('/group/{farm}', [PermissionController::class, 'getGroupPermissions']);
        Route::get('/mypermissions/{farm}', [PermissionController::class, 'getMyFarmPermissions']);
        Route::get('/roles/{farm}', [PermissionController::class, 'getRoles']);
        Route::post('/roles', [PermissionController::class, 'createRole']);
        Route::put('/roles/{id}', [PermissionController::class, 'updateRole']);
        Route::delete('/roles/{id}', [PermissionController::class, 'deleteRole']);
        Route::post('/add-permissions-to-role', [PermissionController::class, 'addPermissionsToRole']);
        Route::post('/remove-permission-from-role', [PermissionController::class, 'removePermissionFromRole']);
        
        // User Role Management
        Route::post('/assign-role', [PermissionController::class, 'assignRole']);
        Route::post('/remove-role', [PermissionController::class, 'removeRole']);
        Route::post('/sync-roles', [PermissionController::class, 'syncUserRoles']);
        Route::get('/farm/{farm}/user/{userId}/permissions', [PermissionController::class, 'getUserPermissions']);
        Route::get('/user/{userId}/roles', [PermissionController::class, 'getUserRoles']);
    });

    // Vaccine Routes
    Route::prefix('farms/{farm}/vaccines')->group(function () {
        Route::get('/data', [VaccineController::class, 'data']);
        Route::get('/{paginated?}', [VaccineController::class, 'index']);
        Route::post('/', [VaccineController::class, 'store']);
        Route::get('/{vaccine}', [VaccineController::class, 'show']);
        Route::put('/{vaccine}', [VaccineController::class, 'update']);
        Route::delete('/{vaccine}', [VaccineController::class, 'destroy']);
        Route::get('/statistics', [VaccineController::class, 'statistics']);
    });

    // Vaccine Product Routes
    Route::prefix('farms/{farm}/vaccine-products')->group(function () {
        Route::get('/', [VaccineProductController::class, 'index']);
        Route::post('/', [VaccineProductController::class, 'store']);
        Route::get('/{product}', [VaccineProductController::class, 'show']);
        Route::put('/{product}', [VaccineProductController::class, 'update']);
        Route::delete('/{product}', [VaccineProductController::class, 'destroy']);
        Route::get('/statistics', [VaccineProductController::class, 'statistics']);
    });

    // Vaccine Inventory Routes
    Route::prefix('farms/{farm}/vaccine-inventory')->group(function () {
        Route::get('/{paginated?}', [VaccineInventoryController::class, 'index']);
        Route::post('/', [VaccineInventoryController::class, 'store']);
        Route::get('/statistics', [VaccineInventoryController::class, 'statistics']);
        Route::get('/alerts', [VaccineInventoryController::class, 'alerts']);
        Route::get('/available-products', [VaccineInventoryController::class, 'availableProducts']);
        Route::post('/bulk-update-status', [VaccineInventoryController::class, 'bulkUpdateStatus']);
        Route::get('/{inventory}', [VaccineInventoryController::class, 'show']);
        Route::put('/{inventory}', [VaccineInventoryController::class, 'update']);
        Route::delete('/{inventory}', [VaccineInventoryController::class, 'destroy']);
    });

    // Medication Product Routes
    Route::prefix('farms/{farm}/medication-products')->group(function () {
        Route::get('/', [MedicationProductController::class, 'index']);
        Route::post('/', [MedicationProductController::class, 'store']);
        Route::get('/statistics', [MedicationProductController::class, 'statistics']);
        Route::get('/{product}', [MedicationProductController::class, 'show']);
        Route::put('/{product}', [MedicationProductController::class, 'update']);
        Route::delete('/{product}', [MedicationProductController::class, 'destroy']);
    });

    // Medication Inventory Routes
    Route::prefix('farms/{farm}/medication-inventory')->group(function () {
        Route::get('/', [MedicationInventoryController::class, 'index']);
        Route::get('/paginated', [MedicationInventoryController::class, 'index'])->defaults('paginated', true);
        Route::post('/', [MedicationInventoryController::class, 'store']);
        Route::get('/statistics', [MedicationInventoryController::class, 'statistics']);
        Route::get('/alerts', [MedicationInventoryController::class, 'alerts']);
        Route::get('/available-products', [MedicationInventoryController::class, 'availableProducts']);
        Route::post('/bulk-update-status', [MedicationInventoryController::class, 'bulkUpdateStatus']);
        Route::get('/{inventory}', [MedicationInventoryController::class, 'show']);
        Route::put('/{inventory}', [MedicationInventoryController::class, 'update']);
        Route::delete('/{inventory}', [MedicationInventoryController::class, 'destroy']);
    });

    // Poultry Medication Routes
    Route::prefix('farms/{farm}/medications')->group(function () {
        Route::get('/', [PoultryMedicationController::class, 'index']);
        Route::get('/paginated', [PoultryMedicationController::class, 'index'])->defaults('paginated', true);
        Route::get('/data', [PoultryMedicationController::class, 'data']);
        Route::post('/', [PoultryMedicationController::class, 'store']);
        Route::get('/{medication}', [PoultryMedicationController::class, 'show']);
        Route::put('/{medication}', [PoultryMedicationController::class, 'update']);
        Route::delete('/{medication}', [PoultryMedicationController::class, 'destroy']);
        Route::get('/statistics', [PoultryMedicationController::class, 'statistics']);
    });

    // Countries route (global, not farm-specific)
    Route::get('/countries', [VaccineInventoryController::class, 'countries']);

    // Administration Methods (global, not farm-specific)
    Route::prefix('administration-methods')->group(function () {
        Route::get('/', [AdministrationMethodController::class, 'index']);
        Route::post('/', [AdministrationMethodController::class, 'store']);
        Route::get('/{administrationMethod}', [AdministrationMethodController::class, 'show']);
        Route::put('/{administrationMethod}', [AdministrationMethodController::class, 'update']);
        Route::delete('/{administrationMethod}', [AdministrationMethodController::class, 'destroy']);
    });

    // Feed Type Routes
    Route::prefix('farms/{farm}/feed-types')->group(function () {
        Route::get('/{poultryType?}/{pagination?}', [FeedTypeController::class, 'index']);
        Route::post('/', [FeedTypeController::class, 'store']);
        Route::get('/{feedType}', [FeedTypeController::class, 'show']);
        Route::put('/{feedType}', [FeedTypeController::class, 'update']);
        Route::delete('/{feedType}', [FeedTypeController::class, 'destroy']);
        Route::get('/statistics', [FeedTypeController::class, 'statistics']);
    });

    // Feed Inventory Routes
    Route::prefix('farms/{farm}/feed-inventories')->group(function () {
        Route::get('/', [FeedInventoryController::class, 'index']);
        Route::get('/paginated', [FeedInventoryController::class, 'index'])->defaults('pagination', true);
        Route::post('/', [FeedInventoryController::class, 'store']);
        Route::get('/statistics', [FeedInventoryController::class, 'statistics']);
        Route::get('/{inventory}', [FeedInventoryController::class, 'show']);
        Route::put('/{inventory}', [FeedInventoryController::class, 'update']);
        Route::delete('/{inventory}', [FeedInventoryController::class, 'destroy']);
        
    });

    // Feed Usage Routes
    Route::prefix('farms/{farm}/feed-usages')->group(function () {
        Route::get('/', [FeedUsageController::class, 'index']);
        Route::post('/', [FeedUsageController::class, 'store']);
        Route::get('/{usage}', [FeedUsageController::class, 'show']);
        Route::put('/{usage}', [FeedUsageController::class, 'update']);
        Route::delete('/{usage}', [FeedUsageController::class, 'destroy']);
        Route::get('/statistics', [FeedUsageController::class, 'statistics']);
    });

    // Flock Weight Report Routes
    Route::prefix('farms/{farm}/flock-weight-reports')->group(function () {
        Route::get('/', [FlockWeightReportController::class, 'index']);
        Route::post('/', [FlockWeightReportController::class, 'store']);
        Route::get('/{report}', [FlockWeightReportController::class, 'show']);
        Route::put('/{report}', [FlockWeightReportController::class, 'update']);
        Route::delete('/{report}', [FlockWeightReportController::class, 'destroy']);
        Route::get('/statistics', [FlockWeightReportController::class, 'statistics']);
    });

    // Flock Egg Report Routes
    Route::prefix('farms/{farm}/flock-egg-reports')->group(function () {
        Route::get('/', [FlockEggReportController::class, 'index']);
        Route::post('/', [FlockEggReportController::class, 'store']);
        Route::get('/{report}', [FlockEggReportController::class, 'show']);
        Route::put('/{report}', [FlockEggReportController::class, 'update']);
        Route::delete('/{report}', [FlockEggReportController::class, 'destroy']);
        Route::get('/statistics', [FlockEggReportController::class, 'statistics']);
    });

    // Flock Mortality Report Routes
    Route::prefix('farms/{farm}/flock-mortality-reports')->group(function () {
        Route::get('/', [FlockMortalityReportController::class, 'index']);
        Route::post('/', [FlockMortalityReportController::class, 'store']);
        Route::get('/by-flock-date', [FlockMortalityReportController::class, 'getMortalityByFlockAndDate']);
        Route::get('/statistics', [FlockMortalityReportController::class, 'statistics']);
        Route::get('/{report}', [FlockMortalityReportController::class, 'show']);
        Route::put('/{report}', [FlockMortalityReportController::class, 'update']);
        Route::delete('/{report}', [FlockMortalityReportController::class, 'destroy']);
    });

    // Feeding schedule management
    Route::prefix('farms/{farm}/feeding')->group(function () {
        Route::apiResource('schedules', FeedingScheduleController::class);
        Route::apiResource('schedule-items', FeedingScheduleItemController::class);
        Route::apiResource('batch-schedules', FeedingBatchScheduleController::class);
        Route::get('batch-schedules/flock/{flockId}/items-by-date', [FeedingBatchScheduleItemController::class, 'getByBatchAndDate']);
        Route::apiResource('batch-schedule-items', FeedingBatchScheduleItemController::class);
    });

    // Scheduling management
    Route::prefix('farms/{farm}/{type}')->group(function () {
        Route::get('schedules', [ScheduleController::class, 'index']);
        Route::get('schedules/paginated', [ScheduleController::class, 'index'])->defaults('paginated', true);
        Route::post('schedules', [ScheduleController::class, 'store']);
        Route::get('schedules/{schedule}', [ScheduleController::class, 'show']);
        Route::put('schedules/{schedule}', [ScheduleController::class, 'update']);
        Route::delete('schedules/{schedule}', [ScheduleController::class, 'destroy']);
        Route::apiResource('schedule-items', ScheduleItemController::class);
        Route::apiResource('batch-schedules', BatchScheduleController::class);
        Route::apiResource('batch-schedule-items', BatchScheduleItemController::class);
    });

    // Feed Product Routes
    Route::prefix('farms/{farm}/feed-products')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\PoultryFeedProductController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\PoultryFeedProductController::class, 'store']);
        Route::get('/statistics', [\App\Http\Controllers\Api\PoultryFeedProductController::class, 'statistics']);
        Route::get('/{product}', [\App\Http\Controllers\Api\PoultryFeedProductController::class, 'show']);
        Route::put('/{product}', [\App\Http\Controllers\Api\PoultryFeedProductController::class, 'update']);
        Route::delete('/{product}', [\App\Http\Controllers\Api\PoultryFeedProductController::class, 'destroy']);
    });

    // Invoice Routes
    Route::prefix('farms/{farm}/invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::get('/paginated', [InvoiceController::class, 'index'])->defaults('paginated', true);
        Route::post('/', [InvoiceController::class, 'store']);
        Route::get('/{invoice}', [InvoiceController::class, 'show']);
        Route::put('/{invoice}', [InvoiceController::class, 'update']);
        Route::delete('/{invoice}', [InvoiceController::class, 'destroy']);
    });

});
});
