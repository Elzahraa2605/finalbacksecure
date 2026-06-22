<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\SendOtpMail;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class ForgotPasswordController extends Controller
{
    // 1. استقبال الإيميل وتوليد الـ OTP وإرساله للـ Gmail الحقيقي دايماً
    public function sendOtp(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $email = trim($request->email);

        // البحث في جداول قاعدة البيانات بالبريد المكتوب (سواء وهمي أو حقيقي)
        $parentExists = DB::table('parents')->where('email', $email)->exists();
        $childExists = DB::table('childrens')->where('email', $email)->exists();

        if (!$parentExists && !$childExists) {
            return response()->json(['message' => 'هذا البريد الإلكتروني غير مسجل لدينا في أي حساب.'], 404);
        }

        // توليد كود عشوائي من 6 أرقام
        $otp = rand(100000, 999999);

        // حفظ أو تحديث الكود في جدول التوكنات بناءً على الإيميل المكتوب
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => $otp,
                'created_at' => Carbon::now()
            ]
        );

        // 🚀 هنا السحر: لارافيل بيبعت الـ OTP لإيميلك أنتِ الحقيقي الثابت دايماً مهما كان إيميل الحساب وهمي
        Mail::to('finalproject123654@gmail.com')->send(new SendOtpMail($otp));

        return response()->json(['message' => 'تم إرسال رمز الـ OTP بنجاح إلى بريدك الإلكتروني الحقيقي للتحقق.']);
    }

    // 2. التأكد من صحة الـ OTP
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|numeric'
        ]);

        $email = trim($request->email);

        $reset = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $request->otp)
            ->first();

        if (!$reset || Carbon::parse($reset->created_at)->addMinutes(15)->isPast()) {
            return response()->json(['message' => 'رمز الـ OTP غير صحيح أو منتهي الصلاحية.'], 400);
        }

        return response()->json(['message' => 'الرمز صحيح، يمكنك الآن تغيير كلمة المرور.']);
    }

    // 3. تعيين كلمة المرور الجديدة في الجدول الخاص بصاحب الحساب
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|numeric',
            'password' => 'required|string|min:6|confirmed'
        ]);

        $email = trim($request->email);

        $reset = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $request->otp)
            ->first();

        if (!$reset) {
            return response()->json(['message' => 'طلب غير صالح أو منتهي الصلاحية.'], 400);
        }

        $newPasswordHash = Hash::make($request->password);
        $updated = false;

        if (DB::table('parents')->where('email', $email)->exists()) {
            DB::table('parents')->where('email', $email)->update(['password' => $newPasswordHash]);
            $updated = true;
        } 
        
        if (DB::table('childrens')->where('email', $email)->exists()) {
            DB::table('childrens')->where('email', $email)->update(['password' => $newPasswordHash]);
            $updated = true;
        }

        if (!$updated) {
            return response()->json(['message' => 'فشل تحديث كلمة المرور، الحساب لم يعد موجوداً.'], 404);
        }

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json(['message' => 'تم إعادة تعيين كلمة المرور بنجاح.']);
    }
}