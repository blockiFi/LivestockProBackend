<?php

use App\Http\Controllers\Admin\AdminAccessController;
use App\Http\Controllers\Admin\AdminAnalyticsController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminRevenueController;
use App\Http\Controllers\Admin\AdminSearchController;
use App\Http\Controllers\Admin\AdminTeamController;
use App\Http\Controllers\Admin\AdminAuditLogController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminFarmController;
use App\Http\Controllers\Admin\AdminFarmSubscriptionController;
use App\Http\Controllers\Admin\AdminImpersonationController;
use App\Http\Controllers\Admin\AdminInventoryAlertController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminPlatformSettingsController;
use App\Http\Controllers\Admin\AdminReferenceDataController;
use App\Http\Controllers\Admin\AdminResourceController;
use App\Http\Controllers\Admin\AdminSystemController;
use App\Http\Controllers\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['success' => true, 'message' => 'Admin API healthy']));

// Admin session (sign-in itself lives in routes/admin_auth.php)
Route::get('/auth/me', [AdminAuthController::class, 'me']);
Route::post('/auth/logout', [AdminAuthController::class, 'logout']);

// Global search and header alerts
Route::get('/search', AdminSearchController::class);
Route::get('/alerts', [AdminDashboardController::class, 'alerts']);

// Dashboard & Analytics
Route::get('/dashboard', [AdminDashboardController::class, 'index']);
Route::get('/dashboard/insights', [AdminDashboardController::class, 'insights']);
Route::get('/analytics/compare', [AdminAnalyticsController::class, 'compare']);
Route::get('/analytics/growth', [AdminAnalyticsController::class, 'growth']);
Route::get('/analytics/usage', [AdminAnalyticsController::class, 'usage']);
Route::get('/analytics/health', [AdminAnalyticsController::class, 'health']);
Route::get('/analytics/operations', [AdminAnalyticsController::class, 'operationsOverview']);
Route::get('/analytics/schedules', [AdminAnalyticsController::class, 'operationsSchedules']);
Route::get('/analytics/feeds', [AdminAnalyticsController::class, 'operationsFeeds']);
Route::get('/analytics/financial', [AdminAnalyticsController::class, 'operationsFinancial']);

// Farm management
Route::get('/farms', [AdminFarmController::class, 'index']);
Route::get('/farms/export', [AdminFarmController::class, 'export'])->middleware('platform.role:analyst');
Route::post('/farms/bulk', [AdminFarmController::class, 'bulk'])->middleware('platform.role:support');
Route::get('/farms/{farm}', [AdminFarmController::class, 'show']);
Route::put('/farms/{farm}', [AdminFarmController::class, 'update'])->middleware('platform.role:support');
Route::delete('/farms/{farm}', [AdminFarmController::class, 'destroy'])->middleware('platform.role:super_admin');
Route::post('/farms/{farm}/restore', [AdminFarmController::class, 'restore'])->middleware('platform.role:super_admin');
Route::get('/farms/{farm}/statistics', [AdminFarmController::class, 'statistics']);
Route::get('/farms/{farm}/audit-log', [AdminFarmController::class, 'auditLog']);
Route::get('/farms/{farm}/invitations', [AdminNotificationController::class, 'invitations']);
Route::get('/farms/{farm}/roles', [AdminAccessController::class, 'farmRoles']);
Route::put('/farms/{farm}/roles/{role}/permissions', [AdminAccessController::class, 'updateRolePermissions'])->middleware('platform.role:super_admin');
Route::put('/farms/{farm}/members/{user}/role', [AdminAccessController::class, 'setMemberRole'])->middleware('platform.role:support');

// Subscriptions: waivers, tier overrides, trial extensions
Route::get('/subscriptions/kpis', [AdminFarmSubscriptionController::class, 'kpis']);
Route::get('/revenue/overview', [AdminRevenueController::class, 'overview']);
Route::get('/revenue/subscriptions', [AdminRevenueController::class, 'subscriptions']);
Route::get('/revenue/subscriptions/export', [AdminRevenueController::class, 'export'])->middleware('platform.role:analyst');
Route::get('/farms/{farm}/subscription', [AdminFarmSubscriptionController::class, 'show']);
Route::get('/farms/{farm}/subscription/waivers', [AdminFarmSubscriptionController::class, 'waivers']);
Route::post('/farms/{farm}/subscription/waiver', [AdminFarmSubscriptionController::class, 'grantWaiver'])->middleware('platform.role:support');
Route::delete('/farms/{farm}/subscription/waiver', [AdminFarmSubscriptionController::class, 'revokeWaiver'])->middleware('platform.role:support');
Route::post('/farms/{farm}/subscription/plan', [AdminFarmSubscriptionController::class, 'assignPlan'])->middleware('platform.role:support');
Route::post('/farms/{farm}/subscription/extend-trial', [AdminFarmSubscriptionController::class, 'extendTrial'])->middleware('platform.role:support');

// User management
Route::get('/users', [AdminUserController::class, 'index']);
Route::get('/users/export', [AdminUserController::class, 'export'])->middleware('platform.role:analyst');
Route::post('/users/bulk', [AdminUserController::class, 'bulk'])->middleware('platform.role:support');
Route::get('/users/{user}', [AdminUserController::class, 'show']);
Route::put('/users/{user}', [AdminUserController::class, 'update'])->middleware('platform.role:support');
Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->middleware('platform.role:support');
Route::delete('/users/{user}/tokens', [AdminUserController::class, 'revokeTokens'])->middleware('platform.role:support');
Route::get('/users/{user}/activity', [AdminUserController::class, 'activity']);
Route::post('/users/{user}/deactivate', [AdminUserController::class, 'deactivate'])->middleware('platform.role:support');
Route::post('/users/{user}/reactivate', [AdminUserController::class, 'reactivate'])->middleware('platform.role:support');

// Admin team (who can use this console)
Route::get('/team', [AdminTeamController::class, 'index']);
Route::post('/team', [AdminTeamController::class, 'store'])->middleware('platform.role:super_admin');
Route::put('/team/{user}', [AdminTeamController::class, 'update'])->middleware('platform.role:super_admin');
Route::delete('/team/{user}', [AdminTeamController::class, 'destroy'])->middleware('platform.role:super_admin');

// Access: platform role matrix and farm roles
Route::get('/access/platform-roles', [AdminAccessController::class, 'platformRoles']);
Route::get('/access/farm-roles', [AdminAccessController::class, 'farmRolesOverview']);

// Audit log
Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);
Route::get('/audit-logs/facets', [AdminAuditLogController::class, 'facets']);
Route::get('/audit-logs/export', [AdminAuditLogController::class, 'export'])->middleware('platform.role:analyst');

// Inventory alerts
Route::get('/inventory-alerts', [AdminInventoryAlertController::class, 'index']);
Route::get('/inventory-alerts/summary', [AdminInventoryAlertController::class, 'summary']);

// Reference data
Route::prefix('reference')->group(function () {
    Route::get('/administration-methods', [AdminReferenceDataController::class, 'administrationMethods']);
    Route::post('/administration-methods', [AdminReferenceDataController::class, 'storeAdministrationMethod'])->middleware('platform.role:super_admin');
    Route::put('/administration-methods/{administrationMethod}', [AdminReferenceDataController::class, 'updateAdministrationMethod'])->middleware('platform.role:super_admin');
    Route::delete('/administration-methods/{administrationMethod}', [AdminReferenceDataController::class, 'destroyAdministrationMethod'])->middleware('platform.role:super_admin');

    Route::get('/liter-types', [AdminReferenceDataController::class, 'literTypes']);
    Route::post('/liter-types', [AdminReferenceDataController::class, 'storeLiterType'])->middleware('platform.role:super_admin');
    Route::put('/liter-types/{literType}', [AdminReferenceDataController::class, 'updateLiterType'])->middleware('platform.role:super_admin');
    Route::delete('/liter-types/{literType}', [AdminReferenceDataController::class, 'destroyLiterType'])->middleware('platform.role:super_admin');

    Route::get('/flock-stages', [AdminReferenceDataController::class, 'flockStages']);
    Route::get('/countries', [AdminReferenceDataController::class, 'countries']);
    Route::get('/permission-groups', [AdminReferenceDataController::class, 'permissionGroups']);
    Route::get('/permissions', [AdminReferenceDataController::class, 'permissions']);
});

// System administration
Route::prefix('system')->middleware('platform.role:super_admin')->group(function () {
    Route::get('/health', [AdminSystemController::class, 'health']);
    Route::get('/logs', [AdminSystemController::class, 'logs']);
    Route::get('/config', [AdminSystemController::class, 'config']);
    Route::post('/cache/clear', [AdminSystemController::class, 'clearCache']);
    Route::get('/failed-jobs', [AdminSystemController::class, 'failedJobs']);
    Route::post('/failed-jobs/{uuid}/retry', [AdminSystemController::class, 'retryFailedJob']);
    Route::delete('/failed-jobs/{uuid}', [AdminSystemController::class, 'forgetFailedJob']);
    Route::get('/queue', [AdminSystemController::class, 'queue']);
});

// Support tools
Route::get('/platform-settings', [AdminPlatformSettingsController::class, 'show'])->middleware('platform.role:support');
Route::put('/platform-settings', [AdminPlatformSettingsController::class, 'update'])->middleware('platform.role:support');
Route::post('/impersonate/{user}', [AdminImpersonationController::class, 'store'])->middleware('platform.role:support');
Route::delete('/impersonate', [AdminImpersonationController::class, 'destroy'])->middleware('platform.role:support');
Route::post('/notifications/broadcast', [AdminNotificationController::class, 'broadcast'])->middleware('platform.role:super_admin');
Route::get('/notifications/broadcasts', [AdminNotificationController::class, 'history'])->middleware('platform.role:support');
Route::post('/farms/{farm}/invitations/{invitation}/resend', [AdminNotificationController::class, 'resendInvitation'])->middleware('platform.role:support');

// Cross-farm data views (must be last — catch-all)
Route::get('/{resource}/{id}', [AdminResourceController::class, 'show'])
    ->where('resource', 'flocks|houses|feed-inventories|vaccine-inventories|medication-inventories|sales|equipment|tasks|notifications|ai-imports|feeding-schedules|task-schedules|feed-products|feed-components|feed-usages|flock-sales|expenditures')
    ->whereNumber('id');
Route::get('/{resource}', [AdminResourceController::class, 'index'])
    ->where('resource', 'flocks|houses|feed-inventories|vaccine-inventories|medication-inventories|sales|equipment|tasks|notifications|ai-imports|feeding-schedules|task-schedules|feed-products|feed-components|feed-usages|flock-sales|expenditures');
