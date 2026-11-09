<?php

declare(strict_types=1);

namespace App\Services\GardnerCommerce;

use App\Models\TestSession;
use Illuminate\Support\Str;

/** Optional link to an already independently approved R27 artifact.
 * This service NEVER publishes, changes ownership or sends email.
 * It is called only after BuyerAccess::authorize in GardnerCommerceController::state.
 */
final class R27AccountLink
{
    public function forPaidSession(TestSession $session): ?string
    {
        if (config('gardner_commerce.r27_account_link_enabled') !== true
            || !app(Orders::class)->paid($session)
            || $session->test_type !== 'children' || $session->status !== 'completed'
            || !app('router')->getRoutes()->getByName('gardner.r27.customer.show')) {
            return null;
        }
        $id = (int) $session->id;
        if ($id < 1 || !Str::isUuid((string) $session->public_id)) return null;
        $customer = storage_path('app/gardner-r27-customer/'.$id);
        $owner = storage_path('app/gardner-r27-owner-review/'.$id);
        $manifestPath = $customer.'/manifest.json';
        $reviewPath = $owner.'/review.json';
        $tokenPath = $customer.'/token.secret';
        try {
            foreach ([$manifestPath, $reviewPath, $tokenPath] as $p) {
                if (!is_file($p) || is_link($p)) return null;
            }
            $m = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
            $review = json_decode((string) file_get_contents($reviewPath), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($m) || !is_array($review) || ($m['session_id'] ?? null) !== $id
                || ($review['session_id'] ?? null) !== $id
                || !is_int($m['expires'] ?? null) || $m['expires'] < time()
                || ($review['state'] ?? null) !== 'APPROVED'
                || ($review['decision']['value'] ?? null) !== 'APPROVED'
                || (int) ($review['blocked'] ?? 999) !== 0) return null;
            $token = trim((string) file_get_contents($tokenPath));
            if (!preg_match('/\A[A-Za-z0-9_-]{50,100}\z/D', $token)
                || !hash_equals((string)($m['token_sha256'] ?? ''), hash('sha256', $token))) return null;
            foreach (['html','pdf'] as $ext) {
                $path = $customer.'/report.'.$ext;
                if (!is_file($path) || is_link($path)
                    || !is_string($m[$ext.'_sha256'] ?? null)
                    || !is_string($review[$ext.'_sha256'] ?? null)
                    || !hash_equals($review[$ext.'_sha256'], (string) ($m['approved_'.$ext.'_sha256'] ?? ''))
                    || !hash_equals($m[$ext.'_sha256'], (string) hash_file('sha256', $path))) return null;
            }
            return '/api/gardner-r27-customer-report/'.$id.'/'.rawurlencode($token).'/page';
        } catch (\Throwable) {
            return null;
        }
    }
}
