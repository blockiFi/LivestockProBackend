<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FarmController;
use App\Http\Controllers\Api\FarmSubscriptionController;
use App\Http\Controllers\Api\FarmUsersController;
use App\Http\Controllers\Api\PaystackWebhookController;
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
use App\Http\Controllers\Api\FeedFormulaAnalysisController;
use App\Http\Controllers\Api\FeedUsageController;
use App\Http\Controllers\Api\FlockWeightReportController;
use App\Http\Controllers\Api\FlockEggReportController;
use App\Http\Controllers\Api\FlockMortalityReportController;
use App\Http\Controllers\Api\FeedingScheduleController;
use App\Http\Controllers\Api\FeedingScheduleItemController;
use App\Http\Controllers\Api\FeedingBatchScheduleController;
use App\Http\Controllers\Api\FeedingBatchScheduleItemController;
use App\Http\Controllers\Api\AiScheduleImportController;
use App\Http\Controllers\Api\FlockRecordImportController;
use App\Http\Controllers\Api\FarmTaskTemplateController;
use App\Http\Controllers\Api\FarmTaskScheduleController;
use App\Http\Controllers\Api\FarmTaskInstanceController;
use App\Http\Controllers\Api\FarmTaskNotificationController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\ScheduleItemController;
use App\Http\Controllers\Api\BatchScheduleController;
use App\Http\Controllers\Api\BatchScheduleItemController;
use App\Http\Controllers\Api\FlockDailyRecordController;
use App\Http\Controllers\Api\PoultryMedicationRecordController;
use App\Http\Controllers\Api\PoultryVaccinationRecordController;
use App\Http\Controllers\Api\FlockExpenditureController;
use App\Http\Controllers\Api\FlockSaleController;
use App\Http\Controllers\Api\SalesStatisticsController;
use App\Http\Controllers\Api\SalesRecordController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\AdministrationMethodController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\FlockMetricsAnalysisController;
use App\Http\Controllers\Api\FlockNotificationController;
use App\Http\Controllers\Api\FlockActivityReportController;
use App\Http\Controllers\Api\FlockTransferController;
use App\Http\Controllers\Api\LiterTypeController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\FarmSettingController;
use App\Http\Controllers\Api\FeedTypeAgeRangeController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FarmAlertController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\FarmNotificationSettingController;
use App\Http\Controllers\Api\FarmTaskReminderController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\EquipmentCategoryController;
use App\Http\Controllers\Api\EquipmentMaintenanceController;
use App\Http\Controllers\Api\EquipmentInspectionController;
use App\Http\Controllers\Api\EquipmentLifecycleController;



// Paystack posts here unauthenticated; the HMAC signature is the credential.
Route::post('webhooks/paystack', [PaystackWebhookController::class, 'handle']);

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);
Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('update-password', [AuthController::class, 'updatePassword']);
    Route::get('user', [AuthController::class, 'user']);
    Route::post('user/profile', [UserProfileController::class, 'update']);
    Route::get('user/preferences', [UserProfileController::class, 'showPreferences']);
    Route::put('user/preferences', [UserProfileController::class, 'updatePreferences']);
    Route::post('user/logout-other-devices', [UserProfileController::class, 'logoutOtherDevices']);

    /*
     * Notification centre. Not farm scoped: the bell shows everything addressed
     * to the signed-in user, optionally narrowed with ?farm_id=
     */
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/summary', [NotificationController::class, 'summary']);
    Route::get('notifications/preferences', [NotificationPreferenceController::class, 'index']);
    Route::put('notifications/preferences', [NotificationPreferenceController::class, 'update']);
    Route::delete('notifications/preferences', [NotificationPreferenceController::class, 'reset']);
    Route::put('notifications/settings', [NotificationPreferenceController::class, 'updateSettings']);
    Route::get('notifications/reminder-presets', [FarmTaskReminderController::class, 'presets']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('notifications/dismiss-all', [NotificationController::class, 'destroyAll']);
    Route::get('notifications/{id}', [NotificationController::class, 'show']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('notifications/{id}/unread', [NotificationController::class, 'markUnread']);
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);

    
    // Get user's farms
    Route::get('/farms', [FarmController::class, 'getUserFarms']);
    
    // Farm CRUD operations
    Route::post('/farms', [FarmController::class, 'store']);

    // Countries route (global, not farm-specific)
    Route::get('/countries', [VaccineInventoryController::class, 'countries']);

    // Global liter types (global table)
    Route::get('/liter-types', [LiterTypeController::class, 'index']);
    
    // Farm-specific routes that require membership

    Route::prefix('flock-stages')->group(function () {
        Route::get('/', [FlockStageController::class, 'index']);
        Route::get('/paginated', [FlockStageController::class, 'index'])->defaults('pagination', true);
        Route::get('/{flockStage}', [FlockStageController::class, 'show']);
    });


    // Billing lives outside the subscription gate so a lapsed farm can still pay.
    Route::get('/subscription/plans', [FarmSubscriptionController::class, 'plans']);

    Route::middleware('farm.member')->group(function () {
        Route::get('/farms/{farm}/subscription', [FarmSubscriptionController::class, 'show']);
        Route::get('/farms/{farm}/subscription/transactions', [FarmSubscriptionController::class, 'transactions']);
        Route::post('/farms/{farm}/subscription/checkout', [FarmSubscriptionController::class, 'checkout']);
        Route::post('/farms/{farm}/subscription/change-plan', [FarmSubscriptionController::class, 'changePlan']);
        Route::post('/farms/{farm}/subscription/cancel', [FarmSubscriptionController::class, 'cancel']);
    });

    Route::middleware(['farm.member', 'farm.subscribed'])->group(function () {
        Route::get('/farms/{farm}', [FarmController::class, 'show']);
        Route::put('/farms/{farm}', [FarmController::class, 'update']);
        Route::delete('/farms/{farm}', [FarmController::class, 'destroy']);
        Route::get('/farms/{farm}/settings', [FarmSettingController::class, 'show']);
        Route::put('/farms/{farm}/settings', [FarmSettingController::class, 'update']);
        Route::get('/farms/{farm}/feed-age-ranges', [FeedTypeAgeRangeController::class, 'index']);
        Route::put('/farms/{farm}/feed-age-ranges', [FeedTypeAgeRangeController::class, 'update']);
        
        // Farm statistics
        Route::get('/farms/{farm}/statistics', [FarmController::class, 'getStatistics']);

        // Farm dashboard + alerts
        Route::get('/farms/{farm}/dashboard', [DashboardController::class, 'index']);
        Route::get('/farms/{farm}/alerts', [FarmAlertController::class, 'index']);
        
        // Poultry statistics
        Route::get('/farms/{farm}/poultry-statistics', [PoultryController::class, 'getStatistics']);
        Route::get('/farms/{farm}/poultry-statistics/{dateParams?}', [PoultryController::class, 'getStatistics']);
        
        // Farm user management (delegated to FarmUsersController)
        Route::get('/farms/{farm}/users', [FarmUsersController::class, 'index']);
        Route::post('/farms/{farm}/users', [FarmUsersController::class, 'store']);
        Route::patch('/farms/{farm}/users/{user}', [FarmUsersController::class, 'update']);
        Route::delete('/farms/{farm}/users/{user}', [FarmUsersController::class, 'destroy']);
        Route::post('/farms/{farm}/users/invite', [FarmUsersController::class, 'invite']);
        Route::post('/farms/{farm}/users/invitations/{invitation}/resend', [FarmUsersController::class, 'resendInvite']);

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
            Route::get('/{flock}/metrics/ai-insights', [FlockMetricsAnalysisController::class, 'aiInsights'])->middleware('farm.ai');
            // Comparative metrics stay available on every plan; the AI narrative
            // inside the payload is stripped for farms without an AI plan.
            Route::get('/{flock}/metrics/comparative', [FlockMetricsAnalysisController::class, 'comparative']);
            Route::post('/{flock}/metrics/comparative', [FlockMetricsAnalysisController::class, 'refreshComparative'])->middleware('farm.ai');
            Route::get('/{flock}/actual-quantity', [FlockController::class, 'getActualQuantity']);
            Route::get('/{flock}/notifications', [FlockNotificationController::class, 'index']);
            Route::get('/{flock}/activities', [FlockActivityReportController::class, 'index']);

            // Multi-pen allocations & transfer history
            Route::get('/{flock}/allocations', [FlockTransferController::class, 'allocations']);
            Route::get('/{flock}/transfers', [FlockTransferController::class, 'history']);
            Route::post('/{flock}/transfers', [FlockTransferController::class, 'store']);
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
  
        // Flock Expenditure routes
        Route::prefix('farms/{farm}/flocks/{flock}/expenditures')->group(function () {
            Route::get('/summary', [FlockExpenditureController::class, 'summary']);
            Route::get('/', [FlockExpenditureController::class, 'index']);
            Route::post('/', [FlockExpenditureController::class, 'store']);
            Route::put('/{expenditure}', [FlockExpenditureController::class, 'update']);
            Route::delete('/{expenditure}', [FlockExpenditureController::class, 'destroy']);
        });

        Route::prefix('farms/{farm}/flocks/{flock}/sales')->group(function () {
            Route::get('/', [FlockSaleController::class, 'index']);
            Route::post('/', [FlockSaleController::class, 'store']);
            Route::put('/{sale}', [FlockSaleController::class, 'update']);
            Route::delete('/{sale}', [FlockSaleController::class, 'destroy']);
        });

        Route::get('farms/{farm}/flocks/{flock}/profit-loss', [SalesStatisticsController::class, 'flockProfitLoss']);
        Route::get('farms/{farm}/sales-statistics', [SalesStatisticsController::class, 'farmProfitLoss']);

        // Flock record bulk import (Excel/CSV + AI)
        Route::prefix('farms/{farm}/flocks/{flock}/record-imports')->group(function () {
            Route::get('/template', [FlockRecordImportController::class, 'template']);
            Route::post('/', [FlockRecordImportController::class, 'store']);
            Route::get('/{id}', [FlockRecordImportController::class, 'show']);
            Route::put('/{id}', [FlockRecordImportController::class, 'update']);
            Route::post('/{id}/confirm', [FlockRecordImportController::class, 'confirm']);
            Route::delete('/{id}', [FlockRecordImportController::class, 'destroy']);
        });
        Route::prefix('farms/{farm}/flocks/{flock}/record-imports')->middleware('farm.ai')->group(function () {
            Route::post('/{id}/extract', [FlockRecordImportController::class, 'extract']);
        });

        Route::prefix('farms/{farm}/sales-records')->group(function () {
            Route::get('/', [SalesRecordController::class, 'index']);
            Route::get('/egg-stock', [SalesRecordController::class, 'eggStock']);
            Route::post('/', [SalesRecordController::class, 'store']);
            Route::put('/{record}', [SalesRecordController::class, 'update']);
            Route::delete('/{record}', [SalesRecordController::class, 'destroy']);
        });

        Route::prefix('farms/{farm}/customers')->group(function () {
            Route::get('/', [CustomerController::class, 'index']);
            Route::post('/', [CustomerController::class, 'store']);
            Route::get('/{customer}', [CustomerController::class, 'show']);
            Route::put('/{customer}', [CustomerController::class, 'update']);
            Route::delete('/{customer}', [CustomerController::class, 'destroy']);
            Route::get('/{customer}/history', [CustomerController::class, 'history']);
            Route::post('/{customer}/payments', [CustomerController::class, 'recordPayment']);
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
        Route::get('/{farm}/{poultryHouse}/capacity-rules', [PoultryHouseController::class, 'capacityRules']);
        Route::put('/{farm}/{poultryHouse}/capacity-rules', [PoultryHouseController::class, 'updateCapacityRules']);
        Route::get('/{farm}/{poultryHouse}/allowed-capacity', [PoultryHouseController::class, 'allowedCapacity']);
    });

    // Permission Management Routes
    Route::prefix('permissions')->group(function () {
        Route::get('/group/{farm}', [PermissionController::class, 'getGroupPermissions']);
        Route::get('/mypermissions/{farm}', [PermissionController::class, 'getMyFarmPermissions']);
        Route::get('/roles/{farm}', [PermissionController::class, 'getRoles']);
        Route::get('/{farm}', [PermissionController::class, 'index']);
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

    // Equipment Management Routes
    Route::prefix('farms/{farm}/equipment-categories')->group(function () {
        Route::get('/', [EquipmentCategoryController::class, 'index']);
        Route::post('/', [EquipmentCategoryController::class, 'store']);
        Route::put('/{category}', [EquipmentCategoryController::class, 'update']);
    });

    Route::prefix('farms/{farm}/equipment')->group(function () {
        Route::get('/dashboard', [EquipmentController::class, 'dashboard']);
        Route::get('/settings', [EquipmentController::class, 'getSettings']);
        Route::put('/settings', [EquipmentController::class, 'updateSettings']);
        Route::post('/bulk-update', [EquipmentController::class, 'bulkUpdate']);
        Route::get('/', [EquipmentController::class, 'index']);
        Route::post('/', [EquipmentController::class, 'store']);
        Route::get('/{equipment}', [EquipmentController::class, 'show']);
        Route::put('/{equipment}', [EquipmentController::class, 'update']);
        Route::delete('/{equipment}', [EquipmentController::class, 'destroy']);
        Route::post('/{equipment}/assign', [EquipmentController::class, 'assign']);
        Route::post('/{equipment}/transfer', [EquipmentController::class, 'transfer']);
        Route::get('/{equipment}/maintenance', [EquipmentMaintenanceController::class, 'index']);
        Route::post('/{equipment}/maintenance', [EquipmentMaintenanceController::class, 'store']);
        Route::get('/{equipment}/inspections', [EquipmentInspectionController::class, 'index']);
        Route::post('/{equipment}/inspections', [EquipmentInspectionController::class, 'store']);
        Route::get('/{equipment}/documents', [EquipmentLifecycleController::class, 'documents']);
        Route::post('/{equipment}/documents', [EquipmentLifecycleController::class, 'storeDocument']);
        Route::delete('/{equipment}/documents/{document}', [EquipmentLifecycleController::class, 'destroyDocument']);
        Route::post('/{equipment}/usage', [EquipmentLifecycleController::class, 'recordUsage']);
        Route::post('/{equipment}/retire', [EquipmentLifecycleController::class, 'retire']);
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
        Route::post('/{inventory}/close', [FeedInventoryController::class, 'close']);
        Route::post('/{inventory}/transfer', [FeedInventoryController::class, 'transfer']);
        Route::delete('/{inventory}', [FeedInventoryController::class, 'destroy']);
        
    });

    // Feed Usage Routes
    Route::prefix('farms/{farm}/feed-usages')->group(function () {
        Route::get('/', [FeedUsageController::class, 'index']);
        Route::post('/', [FeedUsageController::class, 'store']);
        Route::get('/statistics', [FeedUsageController::class, 'statistics']);
        Route::post('/{usage}/force-expenditure', [FeedUsageController::class, 'forceExpenditure']);
        Route::get('/{usage}', [FeedUsageController::class, 'show']);
        Route::put('/{usage}', [FeedUsageController::class, 'update']);
        Route::delete('/{usage}', [FeedUsageController::class, 'destroy']);
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
        Route::get('schedules', [FeedingScheduleController::class, 'index']);
        Route::post('schedules', [FeedingScheduleController::class, 'store']);
        Route::get('schedules/{id}', [FeedingScheduleController::class, 'show']);
        Route::put('schedules/{id}', [FeedingScheduleController::class, 'update']);
        Route::delete('schedules/{id}', [FeedingScheduleController::class, 'destroy']);
        Route::post('schedule-items/{id}/split', [FeedingScheduleItemController::class, 'split']);
        Route::apiResource('schedule-items', FeedingScheduleItemController::class);
        // Custom flock route must be registered before apiResource so "flock" is not treated as an ID.
        Route::get('batch-schedules/flock/{flockId}/items-by-date', [FeedingBatchScheduleItemController::class, 'getByBatchAndDate']);
        Route::get('batch-schedules/{batch}/missed-days', [FeedingBatchScheduleItemController::class, 'missedDays']);
        Route::post('batch-schedules/{batch}/implement-missed', [FeedingBatchScheduleItemController::class, 'implementMissed']);
        Route::get('batch-schedules/{batch}/revertible-days', [FeedingBatchScheduleItemController::class, 'revertibleDays']);
        Route::post('batch-schedules/{batch}/revert-missed', [FeedingBatchScheduleItemController::class, 'revertMissed']);
        Route::apiResource('batch-schedules', FeedingBatchScheduleController::class);
        Route::apiResource('batch-schedule-items', FeedingBatchScheduleItemController::class);
    });

    // Scheduling management
    Route::prefix('farms/{farm}/{type}')->group(function () {
        Route::get('schedules', [ScheduleController::class, 'index']);
        Route::get('schedules/paginated', [ScheduleController::class, 'index'])->defaults('paginated', true);
        Route::post('schedules', [ScheduleController::class, 'store']);
        Route::get('schedules/{id}', [ScheduleController::class, 'show']);
        Route::put('schedules/{id}', [ScheduleController::class, 'update']);
        Route::delete('schedules/{id}', [ScheduleController::class, 'destroy']);
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

    // Feed Components
    Route::prefix('farms/{farm}/feed-components')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\FeedComponentController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\FeedComponentController::class, 'store']);
        Route::post('/generate-ai', [\App\Http\Controllers\Api\FeedComponentController::class, 'generateWithAI'])->middleware('farm.ai');
        Route::get('/{component}', [\App\Http\Controllers\Api\FeedComponentController::class, 'show']);
        Route::put('/{component}', [\App\Http\Controllers\Api\FeedComponentController::class, 'update']);
        Route::delete('/{component}', [\App\Http\Controllers\Api\FeedComponentController::class, 'destroy']);
    });

    // Feed Compositions (nested under feed products)
    Route::prefix('farms/{farm}/feed-products/{product}/compositions')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\FeedCompositionController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\FeedCompositionController::class, 'store']);
        Route::put('/{composition}', [\App\Http\Controllers\Api\FeedCompositionController::class, 'update']);
        Route::delete('/{composition}', [\App\Http\Controllers\Api\FeedCompositionController::class, 'destroy']);
        Route::post('/calculate-nutrition', [\App\Http\Controllers\Api\FeedCompositionController::class, 'calculateNutrition']);
    });

    // Feed formula AI analysis
    Route::prefix('farms/{farm}/feed-products/{product}')->middleware('farm.ai')->group(function () {
        Route::post('/analyze-formula', [FeedFormulaAnalysisController::class, 'analyze']);
        Route::post('/recommend-formula', [FeedFormulaAnalysisController::class, 'recommend']);
    });

    // AI-assisted feed formulation from scratch
    Route::post('farms/{farm}/formulate-feed', [FeedFormulaAnalysisController::class, 'formulate'])->middleware('farm.ai');
    Route::post('farms/{farm}/formulate-feed/revise', [FeedFormulaAnalysisController::class, 'revise'])->middleware('farm.ai');

    // Invoice Routes
    Route::prefix('farms/{farm}/invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::get('/paginated', [InvoiceController::class, 'index'])->defaults('paginated', true);
        Route::post('/', [InvoiceController::class, 'store']);
        Route::get('/{invoice}', [InvoiceController::class, 'show']);
        Route::put('/{invoice}', [InvoiceController::class, 'update']);
        Route::delete('/{invoice}', [InvoiceController::class, 'destroy']);
    });

    // AI schedule import (PDF/Image -> draft -> confirm)
    Route::prefix('farms/{farm}/ai')->middleware('farm.ai')->group(function () {
        Route::post('schedule-imports', [AiScheduleImportController::class, 'store']);
        Route::get('schedule-imports/{id}', [AiScheduleImportController::class, 'show']);
        Route::put('schedule-imports/{id}', [AiScheduleImportController::class, 'update']);
        Route::post('schedule-imports/{id}/extract', [AiScheduleImportController::class, 'extract']);
        Route::post('schedule-imports/{id}/confirm', [AiScheduleImportController::class, 'confirm']);
        Route::delete('schedule-imports/{id}', [AiScheduleImportController::class, 'destroy']);
    });

    // Farm Task Management
    Route::prefix('farms/{farm}')->group(function () {
        Route::get('task-templates', [FarmTaskTemplateController::class, 'index']);
        Route::post('task-templates', [FarmTaskTemplateController::class, 'store']);
        Route::get('task-templates/{id}', [FarmTaskTemplateController::class, 'show']);
        Route::put('task-templates/{id}', [FarmTaskTemplateController::class, 'update']);
        Route::delete('task-templates/{id}', [FarmTaskTemplateController::class, 'destroy']);

        Route::get('task-schedules', [FarmTaskScheduleController::class, 'index']);
        Route::post('task-schedules', [FarmTaskScheduleController::class, 'store']);
        Route::post('task-schedules/seed-roster-example', [FarmTaskScheduleController::class, 'seedRosterExample']);
        Route::get('task-schedules/{id}', [FarmTaskScheduleController::class, 'show']);
        Route::put('task-schedules/{id}', [FarmTaskScheduleController::class, 'update']);
        Route::delete('task-schedules/{id}', [FarmTaskScheduleController::class, 'destroy']);

        Route::get('task-instances/stats', [FarmTaskInstanceController::class, 'stats']);
        Route::get('task-instances', [FarmTaskInstanceController::class, 'index']);
        Route::get('task-instances/{id}', [FarmTaskInstanceController::class, 'show']);
        Route::post('task-instances/{id}/start', [FarmTaskInstanceController::class, 'start']);
        Route::post('task-instances/{id}/complete', [FarmTaskInstanceController::class, 'complete']);
        Route::post('task-instances/{id}/approve', [FarmTaskInstanceController::class, 'approve']);
        Route::post('task-instances/{id}/reject', [FarmTaskInstanceController::class, 'reject']);
        Route::post('task-instances/{id}/skip', [FarmTaskInstanceController::class, 'skip']);
        Route::post('task-instances/{id}/cancel', [FarmTaskInstanceController::class, 'cancel']);
        Route::post('task-instances/{id}/reassign', [FarmTaskInstanceController::class, 'reassign']);

        Route::get('task-notifications', [FarmTaskNotificationController::class, 'index']);
        Route::post('task-notifications/mark-all-read', [FarmTaskNotificationController::class, 'markAllRead']);
        Route::post('task-notifications/{id}/read', [FarmTaskNotificationController::class, 'markRead']);

        // Task reminder configuration
        Route::get('task-schedules/{id}/reminders', [FarmTaskReminderController::class, 'scheduleReminders']);
        Route::put('task-schedules/{id}/reminders', [FarmTaskReminderController::class, 'updateScheduleReminders']);
        Route::get('task-instances/{id}/reminders', [FarmTaskReminderController::class, 'instanceReminders']);
        Route::put('task-instances/{id}/reminders', [FarmTaskReminderController::class, 'updateInstanceReminders']);

        // Farm notification administration
        Route::get('notification-settings', [FarmNotificationSettingController::class, 'index']);
        Route::put('notification-settings', [FarmNotificationSettingController::class, 'update']);
        Route::get('notification-analytics', [FarmNotificationSettingController::class, 'analytics']);
    });

});
});
