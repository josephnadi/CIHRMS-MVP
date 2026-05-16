<?php

namespace App\Services\Disbursement\Contracts;

use App\Models\Disbursement;
use App\Services\Disbursement\DisbursementResult;

/**
 * Pluggable disbursement provider. One implementation per rail:
 *
 *   - GhipssAchProvider     — generates ACH bank file for GhIPSS upload
 *   - MtnMomoProvider       — MTN MoMo Collections/Disbursements API
 *   - VodafoneCashProvider  — Vodafone Cash B2C API
 *   - AirtelTigoProvider    — AirtelTigo Money B2C API
 *
 * The active provider for each channel is selected via `config/disbursement.php`,
 * so production credentials never leak into the codebase and the same code
 * runs in sandbox/staging/prod with only env vars changing.
 */
interface DisbursementProvider
{
    /** The channel this provider services (matches DisbursementChannel enum). */
    public function channel(): string;

    /**
     * Push a single disbursement to the provider. Returns a result indicating
     * whether the provider ACCEPTED the instruction (not whether the money
     * has been settled — settlement comes via webhook).
     */
    public function send(Disbursement $disbursement): DisbursementResult;

    /**
     * Poll the provider for the latest status of a previously-sent
     * disbursement. Used by the reconciliation job when webhooks fail.
     */
    public function refreshStatus(Disbursement $disbursement): DisbursementResult;
}
