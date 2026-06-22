<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;

    // بنمرر كود الـ OTP هنا علشان نستخدمه جوه الإيميل
    public function __construct($otp)
    {
        $this->otp = $otp;
    }

    public function build()
    {
        return $this->subject('رمز إعادة تعيين كلمة المرور - حماية الأطفال')
                    ->html("<h3>مرحباً بك في نظام حماية الأطفال</h3>
                            <p>لقد طلبت إعادة تعيين كلمة المرور الخاصة بك.</p>
                            <p>رمز التحقق (OTP) الخاص بك هو: <b style='font-size: 20px; color: #4CAF50;'>{$this->otp}</b></p>
                            <p>هذا الرمز صالح لمدة 15 دقيقة فقط.</p>");
    }
}