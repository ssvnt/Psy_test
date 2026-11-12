<?php

declare(strict_types=1);

namespace App\Services\Payments;

/** Pure, fail-closed validation of an acquiring operation against the frozen session invoice. */
final class TochkaOperationGuard
{
    /** @return array{ok: bool, reason: string} */
    public function verify(
        array $row,
        string $operationId,
        string $paymentLinkId,
        int $amountKopecks,
        string $customerCode,
        ?string $merchantId,
        bool $rubContractConfirmed = false,
    ): array {
        if ($operationId === '' || ($row['operationId'] ?? null) !== $operationId) {
            return ['ok' => false, 'reason' => 'operation_id_mismatch'];
        }
        if ($paymentLinkId === '' || ($row['paymentLinkId'] ?? null) !== $paymentLinkId) {
            return ['ok' => false, 'reason' => 'payment_link_id_mismatch'];
        }
        if ($customerCode === '' || ($row['customerCode'] ?? null) !== $customerCode) {
            return ['ok' => false, 'reason' => 'customer_code_mismatch'];
        }
        if ($merchantId !== null && ($row['merchantId'] ?? null) !== $merchantId) {
            return ['ok' => false, 'reason' => 'merchant_id_mismatch'];
        }
        if ($amountKopecks <= 0 || self::toKopecks($row['amount'] ?? null) !== $amountKopecks) {
            return ['ok' => false, 'reason' => 'amount_mismatch'];
        }
        $currency = $row['currency'] ?? $row['currencyCode'] ?? null;
        if ($currency !== null) {
            if (!in_array((string) $currency, ['RUB', '643'], true)) {
                return ['ok' => false, 'reason' => 'currency_mismatch'];
            }
        } elseif (!$rubContractConfirmed) {
            // The official Get Payment Operation Info schema does not guarantee a currency field.
            // Approval without one requires a separately verified RUB-only acquiring contract.
            return ['ok' => false, 'reason' => 'rub_contract_not_confirmed'];
        }
        return ['ok' => true, 'reason' => 'verified'];
    }

    /** @param mixed $data Bank Data payload; supports current object and older Operation list. */
    public static function extractOperation(mixed $data, string $operationId): ?array
    {
        if (!is_array($data) || $operationId === '') return null;
        $rows = isset($data['operationId']) ? [$data] : ($data['Operation'] ?? null);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== 1 || !is_array($rows[0])) return null;
        return ($rows[0]['operationId'] ?? null) === $operationId ? $rows[0] : null;
    }

    public static function toKopecks(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
        $text = (string) $value;
        if (preg_match('/^(0|[1-9]\d{0,8})(?:\.(\d{1,2}))?$/D', $text, $m) !== 1) return null;
        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }
}
