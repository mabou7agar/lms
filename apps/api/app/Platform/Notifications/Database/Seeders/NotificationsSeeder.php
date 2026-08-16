<?php

namespace App\Platform\Notifications\Database\Seeders;

use App\Platform\Notifications\Enums\NotificationsPermission;
use App\Platform\Notifications\Models\NotificationTemplate;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds notification permissions and in-app templates (en + ar) for the consumed events.
 * Idempotent.
 */
class NotificationsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (NotificationsPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        SpatieRole::findByName('admin', 'web')->givePermissionTo(NotificationsPermission::values());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $templates = [
            ['welcome', 'Welcome, {{ name }}', 'Hello {{ name }}, welcome to HElbaron.', 'مرحبًا {{ name }}', 'أهلًا {{ name }}، مرحبًا بك في HElbaron.'],
            ['enrollment_confirmed', 'You are enrolled', 'You have been enrolled in a course.', 'تم تسجيلك', 'تم تسجيلك في دورة.'],
            ['course_completed', 'Course completed', 'Congratulations on completing your course.', 'أكملت الدورة', 'تهانينا على إكمال دورتك.'],
            ['course_announcement', '{{ title }}', '{{ body }}', '{{ title }}', '{{ body }}'],
            ['order_receipt', 'Payment received', 'We received your payment. Thank you.', 'تم استلام الدفع', 'لقد استلمنا دفعتك. شكرًا لك.'],
            ['certificate_ready', 'Certificate ready', 'Your certificate {{ number }} is ready.', 'الشهادة جاهزة', 'شهادتك {{ number }} جاهزة.'],
            ['session_scheduled', 'Live session scheduled', 'A live session "{{ title }}" is scheduled.', 'تم جدولة جلسة مباشرة', 'تم جدولة جلسة مباشرة "{{ title }}".'],
            ['consulting_ack', 'Request received', 'We received your consulting request: {{ subject }}.', 'تم استلام الطلب', 'لقد استلمنا طلب الاستشارة: {{ subject }}.'],
            // Learning flow notifications (assignment / assessment / community) — wired via the Shared
            // LearningNotificationPort from the Assessment, Q&A and Forum domains.
            ['assignment_graded', 'Your assignment was graded', 'Your grade has been released. Open the assignment to see your result.', 'تم تقييم واجبك', 'تم نشر درجتك. افتح الواجب لرؤية نتيجتك.'],
            ['assignment_changes_requested', 'Changes requested', 'Your grader asked you to revise and resubmit your assignment.', 'مطلوب تعديلات', 'طلب منك المُقيّم مراجعة واجبك وإعادة تسليمه.'],
            ['assessment_passed', 'You passed', 'Congratulations — you passed the assessment.', 'لقد نجحت', 'تهانينا — لقد اجتزت التقييم.'],
            ['assessment_failed', 'Assessment not passed', 'You did not pass the assessment this time. Review your answers and try again.', 'لم تجتز التقييم', 'لم تجتز التقييم هذه المرة. راجع إجاباتك وحاول مرة أخرى.'],
            ['qna_answered', 'Your question was answered', 'Someone posted an answer to your question.', 'تمت الإجابة على سؤالك', 'قام أحدهم بنشر إجابة على سؤالك.'],
            ['forum_reply', 'New reply to your thread', 'Someone replied to your forum thread.', 'رد جديد على موضوعك', 'قام أحدهم بالرد على موضوعك في المنتدى.'],
            ['forum_mention', 'You were mentioned', 'You were mentioned in a forum post.', 'تمت الإشارة إليك', 'تمت الإشارة إليك في منشور بالمنتدى.'],
            ['course_update', 'Course update', 'There is an update in one of your courses.', 'تحديث للدورة', 'يوجد تحديث في إحدى دوراتك.'],
            // Expiry reminders — sent by commerce:send-expiry-reminders at the lead times the admin
            // configured on the product. {{ days }} is the notice period, not a countdown.
            ['company_purchase_expiring', 'Your company training expires in {{ days }} days', '{{ title }} ends on {{ expires_at }}. {{ seats }} employee(s) will lose access.', 'تدريب شركتك ينتهي خلال {{ days }} يومًا', 'ينتهي {{ title }} في {{ expires_at }}. سيفقد {{ seats }} موظف الوصول.'],
            ['seat_access_expiring', 'Your course access ends in {{ days }} days', 'Your access to {{ title }} ends on {{ expires_at }}. Finish it before then or ask your manager.', 'ينتهي وصولك للدورة خلال {{ days }} يومًا', 'ينتهي وصولك إلى {{ title }} في {{ expires_at }}. أكمله قبل ذلك أو تواصل مع مديرك.'],
            ['certificate_expiring', 'Your certificate expires in {{ days }} days', 'Certificate {{ number }} for {{ title }} is valid until {{ expires_at }}.', 'تنتهي صلاحية شهادتك خلال {{ days }} يومًا', 'الشهادة {{ number }} لـ {{ title }} صالحة حتى {{ expires_at }}.'],
        ];

        foreach ($templates as [$key, $enSubject, $enBody, $arSubject, $arBody]) {
            NotificationTemplate::firstOrCreate(['key' => $key, 'channel' => 'in_app', 'locale' => 'en'], ['subject' => $enSubject, 'body' => $enBody, 'is_active' => true]);
            NotificationTemplate::firstOrCreate(['key' => $key, 'channel' => 'in_app', 'locale' => 'ar'], ['subject' => $arSubject, 'body' => $arBody, 'is_active' => true]);
        }
    }
}
