<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ChildApp; 
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChildAppController extends Controller
{
    /**
     * 1. دالة المزامنة الشاملة (Mirror Sync) لـ تفعيل جلب كل تطبيقات الموبايل دفعة واحدة:
     * - بتمسح أي تطبيق من الداتابيز لو مبعتش من الموبايل (عشان لو مسحه يختفي عند الأب).
     * - بتعمل updateOrCreate بناءً على الـ package_name عشان ميتكررش.
     */
    public function syncAllApps(Request $request)
    {
        $childId = $request->child_id;
        $apps = $request->apps; 

        if (!$apps || !is_array($apps)) {
            return response()->json(['message' => 'No apps provided'], 400);
        }

        try {
            DB::transaction(function () use ($childId, $apps) {
                // تجميع كل الـ package names اللي مبعوتة من الموبايل حالياً
                $incomingPackageNames = collect($apps)->pluck('package_name')->toArray();

                // 🗑️ حذف التطبيقات التي لم تعد موجودة على جهاز الطفل لإنعاش القائمة
                ChildApp::where('child_id', $childId)
                        ->whereNotIn('package_name', $incomingPackageNames)
                        ->delete();

                // 🔄 إضافة أو تحديث التطبيقات الموجودة حالياً على الجهاز
                foreach ($apps as $app) {
                    ChildApp::updateOrCreate(
                        [
                            'child_id' => $childId,
                            'package_name' => $app['package_name']
                        ],
                        [
                            'app_name' => $app['app_name']
                            // إذا كانت قاعدة بياناتك القديمة لا تولد معرف تلقائي، يمكنك فك السطر التالي:
                            // 'id' => (string) Str::uuid(), 
                        ]
                    );
                }
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Apps synced successfully and database refreshed!'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to sync apps: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 2. جلب تطبيقات الطفل للأب (التي تستدعيها شاشة manageApps)
     */
    public function index($child_id)
    {
        // جلب كل التطبيقات المسجلة لهذا الطفل لعرضها للأب
        $apps = ChildApp::where('child_id', $child_id)->get();
        return response()->json($apps, 200);
    }

    /**
     * 3. تحديث حالة الحظر أو التايمر من جهة الأب (الداشبورد المكتوبة بـ PATCH)
     */
    /**
     * 3. تحديث حالة الحظر أو التايمر من جهة الأب (الداشبورد المكتوبة بـ PATCH)
     */
    public function update(Request $request, $id)
    {
        // استخدام sometimes لجعل الحقول اختيارية، وبكدة يقبل حظر لوحده أو تايمر لوحده
        $request->validate([
            'is_blocked' => 'sometimes|required',
            'time_limit' => 'sometimes|required|integer',
        ]);

        $app = ChildApp::find($id);

        if (!$app) {
            return response()->json(['message' => 'App not found'], 404);
        }

        // تحديث الحقول المرسلة فقط والمحافظة على القيم التانية زي ما هي
        $app->update([
            'is_blocked' => $request->has('is_blocked') ? $request->is_blocked : $app->is_blocked,
            'time_limit' => $request->has('time_limit') ? $request->time_limit : $app->time_limit,
        ]);

        return response()->json([
            'status' => 'success', // إرجاع status نجاح ليفهمها الفرونت إيند
            'message' => 'App updated successfully',
            'data' => $app
        ], 200);
    }
    /**
     * 4. جلب الحزم المحظورة فقط (للسيرفس الخاص بالأندرويد لمنع التشغيل)
     */
    public function getBlockedPackagesOnly($childId)
    {
        $blockedPackages = ChildApp::where('child_id', $childId)
                                    ->where('is_blocked', true)
                                    ->pluck('package_name');

        return response()->json($blockedPackages, 200);
    }

    /**
     * 5. حفظ طلب السماح بتطبيق محظور (Native Request من الطفل للأب)
     */
    public function storeNativeRequest(Request $request)
    {
        $request->validate([
            'child_id' => 'required',
            'package_name' => 'required',
            'app_name' => 'required',
            'reason' => 'nullable|string'
        ]);

        $exists = DB::table('child_app_requests')
            ->where('child_id', $request->child_id)
            ->where('package_name', $request->package_name)
            ->where('status', 'pending')
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'هناك طلب معلق بالفعل لهذا التطبيق'], 400);
        }

        DB::table('child_app_requests')->insert([
            'id' => (string) Str::uuid(),
            'child_id' => $request->child_id,
            'package_name' => $request->package_name,
            'app_name' => $request->app_name,
            'reason' => $request->reason ?? 'يريد الطفل فتح هذا التطبيق',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'تم إرسال طلبك للأب بنجاح'], 201);
    }

    /**
     * 6. جلب التطبيقات المسموحة فقط (White-list)
     */
    public function getAllowedApps($childId)
    {
        $allowedPackages = ChildApp::where('child_id', $childId)
                                    ->where('is_blocked', false)
                                    ->pluck('package_name');

        return response()->json($allowedPackages, 200);
    }

    /**
     * 7. جلب الإعدادات والـ Config (لحل الـ 500 Error وتأمين السيرفس)
     */
    public function getAppConfig(Request $request)
    {
        $childId = $request->query('child_id');
        
        if (!$childId) {
            return response()->json(['message' => 'Child ID is required'], 400);
        }

        $apps = ChildApp::where('child_id', $childId)->get();

        return response()->json([
            'status' => 'success',
            'data' => $apps
        ], 200);
    }

    /**
     * 8. جلب مؤقتات التطبيقات المسموحة (للسيرفس في الأندرويد)
     */
    public function getAppsWithTimers($childId)
    {
        try {
            $appsWithTimers = ChildApp::where('child_id', $childId)
                                      ->pluck('time_limit', 'package_name');

            return response()->json($appsWithTimers, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error retrieving timers: ' . $e->getMessage()], 500);
        }
    }

    /**
     * 9. تحديث حالة الحظر أو التايمر من جهة الطفل (لو مفعّلة في الموديل القديم)
     */
    public function syncApps(Request $request)
    {
        $request->validate([
            'child_id' => 'required',
            'package_name' => 'required',
            'is_blocked' => 'required',
            'time_limit' => 'nullable|integer'
        ]);

        $app = ChildApp::where('child_id', $request->child_id)
                        ->where('package_name', $request->package_name)
                        ->first();

        if (!$app) {
            return response()->json(['message' => 'App not found in child list'], 404);
        }

        $app->update([
            'is_blocked' => $request->is_blocked,
            'time_limit' => $request->time_limit ?? $app->time_limit
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'App rule updated successfully',
            'data' => $app
        ], 200);  }
}