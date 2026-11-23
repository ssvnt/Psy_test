<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TestSession;
use App\Services\GardnerCommerce\{Consultations, ReadyMailAccess};
use App\Services\TeenApprovedReports;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Database\UniqueConstraintViolationException;

final class ReadyMailController
{
    public function open(Request $request, TestSession $session, string $action, ReadyMailAccess $access, Consultations $calendar)
    {
        // Independently verify the exact issued route, including action, expiry and report version.
        abort_unless($request->hasValidSignature() && in_array($action, ['report','pdf','booking'], true), 403);
        try {
            $release = $access->release($session, (string) $request->query('source'));
        } catch (\Throwable) { abort(404); }
        abort_unless(hash_equals($release['binding'], (string) $request->query('version')), 403);
        $headers = ['Cache-Control'=>'private, no-store', 'X-Robots-Tag'=>'noindex, nofollow, noarchive',
            'Referrer-Policy'=>'no-referrer'];
        if ($request->isMethod('post')) {
            abort_unless($action === 'booking', 405);
            $input = $request->validate(['request_id'=>'required|uuid', 'slot_id'=>'required|integer|min:1',
                'questions'=>'nullable|string|max:2000', 'share_report'=>'nullable|in:1']);
            try {
                $calendar->change($session, ['request_id'=>$input['request_id'], 'version'=>0, 'action'=>'book',
                    'slot_id'=>(int)$input['slot_id'], 'timezone'=>'Asia/Irkutsk', 'questions'=>$input['questions'] ?? null,
                    'share_report'=>isset($input['share_report']), 'consent_version'=>isset($input['share_report']) ? Consultations::CONSENT_VERSION : null]);
            } catch (UniqueConstraintViolationException) { abort(409, 'Это время уже занято.'); }
            return redirect()->to($request->fullUrl(), 303, $headers);
        }
        if ($action === 'report' || $action === 'pdf') {
            $pdf = $action === 'pdf';
            return response($release[$pdf ? 'pdf' : 'html'], 200, $headers + [
                'Content-Type'=>$pdf ? 'application/pdf' : 'text/html; charset=utf-8',
                'Content-Disposition'=>$pdf ? 'attachment; filename="report.pdf"' : 'inline',
            ]);
        }
        $current = $calendar->current($session);
        // Both products use the same specialist/slots tables and the same 20-minute schedule.
        $slots = app(TeenApprovedReports::class)->availableSlots();
        return response()->view('gardner-mail-booking', ['current'=>$current,'slots'=>$slots,
            'nonce'=>(string) Str::uuid()], 200, $headers);
    }
}
