<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Everything that happens before money changes hands: parsing and validating the amount the
 * student chose, and creating/reusing/cleaning up the {enrol_donation_intent} quote row.
 *
 * @package    enrol_donation
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_donation\local;

/**
 * Domain logic for donation intents (the pre-payment amount quote).
 *
 * An intent never stores who is donating: the payer is only ever {payments}.userid, set by
 * core_payment once the gateway confirms the charge. This keeps the table free of personal data.
 *
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class intent {
    /** @var float Technical ceiling for any donation amount, regardless of currency or instance configuration. */
    const HARD_MAX = 1000000000.0;

    /** @var int Soft cap on intents created per (instance, session) within a 24h window. */
    const SESSION_SOFT_LIMIT = 20;

    /** @var int core_payment component name used for this plugin's payments. */
    const COMPONENT = 'enrol_donation';

    /** @var string core_payment payment area used for this plugin's payments. */
    const PAYMENTAREA = 'donation';

    /**
     * Parses a raw, locale-formatted amount string into a float, or null if it cannot be
     * unambiguously interpreted.
     *
     * Never trusts a thousands separator: if the only separator present is not the current
     * language's decimal separator and is followed by exactly 3 digits, the input is rejected as
     * ambiguous rather than guessed at (see phase-02 plan, red team AD-4).
     *
     * @param string $raw
     * @return float|null
     */
    public static function parse_amount(string $raw): ?float {
        $raw = trim(str_replace("\xc2\xa0", '', $raw)); // Trim whitespace, including NBSP.

        if ($raw === '') {
            return null;
        }

        if (!preg_match('/^\d+([.,]\d+)?$/', $raw)) {
            // Anything else (letters, signs, scientific notation, more than one separator, ...).
            return null;
        }

        $decsep = get_string('decsep', 'langconfig');

        if (!preg_match('/^(\d+)(?:([.,])(\d+))?$/', $raw, $matches)) {
            return null;
        }

        if (!isset($matches[2])) {
            // No separator at all: a plain integer.
            return (float) $matches[1];
        }

        $separator = $matches[2];
        $fraction = $matches[3];

        if ($separator !== $decsep && strlen($fraction) === 3) {
            // Not the decimal separator, and grouped in 3s: looks like a thousands separator.
            return null;
        }

        return (float) ($matches[1] . '.' . $fraction);
    }

    /**
     * Returns the configured minimum donation for an instance.
     *
     * @param \stdClass $instance
     * @return float
     */
    public static function get_minimum(\stdClass $instance): float {
        return (float) $instance->cost;
    }

    /**
     * Returns the configured maximum donation for an instance.
     *
     * @param \stdClass $instance
     * @return float
     */
    public static function get_maximum(\stdClass $instance): float {
        return (float) $instance->customchar2;
    }

    /**
     * Returns the configured suggested donation for an instance, or null if not set.
     *
     * @param \stdClass $instance
     * @return float|null
     */
    public static function get_suggested(\stdClass $instance): ?float {
        if (!isset($instance->customchar1) || $instance->customchar1 === '' || $instance->customchar1 === null) {
            return null;
        }
        return (float) $instance->customchar1;
    }

    /**
     * Validates an already-parsed amount against an instance's minimum, maximum and the hard
     * technical ceiling.
     *
     * @param float $amount
     * @param \stdClass $instance
     * @return string|null a language string identifier describing the error, or null if valid.
     */
    public static function validate_amount(float $amount, \stdClass $instance): ?string {
        $rounded = \core_payment\helper::get_rounded_cost($amount, $instance->currency);

        if ($rounded < self::get_minimum($instance)) {
            return 'amounttoosmall';
        }

        if ($rounded > self::get_maximum($instance) || $rounded > self::HARD_MAX) {
            return 'amounttoolarge';
        }

        return null;
    }

    /**
     * Returns an existing record from {enrol_donation_intent}.
     *
     * @param int $id
     * @param int $strictness IGNORE_MISSING, IGNORE_MULTIPLE or MUST_EXIST.
     * @return \stdClass|false
     */
    public static function get(int $id, int $strictness = MUST_EXIST) {
        global $DB;
        return $DB->get_record('enrol_donation_intent', ['id' => $id], '*', $strictness);
    }

    /**
     * Whether a {payments} row already exists for this intent.
     *
     * @param int $id
     * @return bool
     */
    public static function is_paid(int $id): bool {
        global $DB;
        return $DB->record_exists('payments', [
            'component' => self::COMPONENT,
            'paymentarea' => self::PAYMENTAREA,
            'itemid' => $id,
        ]);
    }

    /**
     * Marks an intent as delivered, if it has not been already.
     *
     * @param int $id
     * @return void
     */
    public static function mark_delivered(int $id): void {
        global $DB;
        $DB->set_field_select('enrol_donation_intent', 'timedelivered', time(), 'id = ? AND timedelivered = 0', [$id]);
    }

    /**
     * Creates a new intent for the given instance and amount, or reuses an unpaid one created in
     * the same session within the last 24 hours for the same (instance, amount, currency).
     *
     * @param \stdClass $instance
     * @param float $amount already-parsed and validated amount (not yet rounded).
     * @return \stdClass the intent record.
     * @throws \moodle_exception if the per-session soft limit is exceeded.
     */
    public static function create_or_reuse(\stdClass $instance, float $amount): \stdClass {
        global $DB, $SESSION;

        $rounded = \core_payment\helper::get_rounded_cost($amount, $instance->currency);

        if (!isset($SESSION->enrol_donation_intents[$instance->id])) {
            $SESSION->enrol_donation_intents[$instance->id] = [];
        }
        $sessionintents = &$SESSION->enrol_donation_intents[$instance->id];

        $cutoff = time() - DAYSECS;
        $recentids = [];
        foreach ($sessionintents as $intentid => $createdat) {
            if ($createdat > $cutoff) {
                $recentids[] = $intentid;
            }
        }

        if ($recentids) {
            [$insql, $inparams] = $DB->get_in_or_equal($recentids, SQL_PARAMS_NAMED);

            $paidids = $DB->get_fieldset_select(
                'payments',
                'itemid',
                "component = :component AND paymentarea = :paymentarea AND itemid $insql",
                $inparams + ['component' => self::COMPONENT, 'paymentarea' => self::PAYMENTAREA]
            );

            $candidates = $DB->get_records_select(
                'enrol_donation_intent',
                "id $insql AND instanceid = :instanceid AND amount = :amount AND currency = :currency",
                $inparams + [
                    'instanceid' => $instance->id,
                    'amount' => $rounded,
                    'currency' => $instance->currency,
                ]
            );

            foreach ($candidates as $candidate) {
                if (!in_array($candidate->id, $paidids)) {
                    return $candidate;
                }
            }
        }

        $newintent = (object) [
            'instanceid' => $instance->id,
            'amount' => $rounded,
            'currency' => $instance->currency,
            'timecreated' => time(),
            'timedelivered' => 0,
        ];
        $newintent->id = $DB->insert_record('enrol_donation_intent', $newintent);

        $sessionintents[$newintent->id] = $newintent->timecreated;

        $recentcount = 0;
        foreach ($sessionintents as $createdat) {
            if ($createdat > $cutoff) {
                $recentcount++;
            }
        }

        if ($recentcount > self::SESSION_SOFT_LIMIT) {
            unset($sessionintents[$newintent->id]);
            $DB->delete_records('enrol_donation_intent', ['id' => $newintent->id]);
            throw new \moodle_exception('toomanydonationattempts', 'enrol_donation');
        }

        return $newintent;
    }

    /**
     * Deletes every intent belonging to an instance, regardless of payment status. Only ever
     * called when the instance itself is being deleted.
     *
     * @param int $instanceid
     * @return void
     */
    public static function delete_for_instance(int $instanceid): void {
        global $DB;
        $DB->delete_records('enrol_donation_intent', ['instanceid' => $instanceid]);
    }

    /**
     * Deletes stale, never-paid intents in a single atomic statement. An intent with a matching
     * {payments} row is never touched by this method.
     *
     * @param int $cutoff intents created before this timestamp are eligible.
     * @return void
     */
    public static function delete_stale(int $cutoff): void {
        global $DB;

        $sql = "timecreated < :cutoff AND NOT EXISTS (
                    SELECT 1
                      FROM {payments} p
                     WHERE p.component = :component
                       AND p.paymentarea = :paymentarea
                       AND p.itemid = {enrol_donation_intent}.id
                )";

        $DB->delete_records_select('enrol_donation_intent', $sql, [
            'cutoff' => $cutoff,
            'component' => self::COMPONENT,
            'paymentarea' => self::PAYMENTAREA,
        ]);
    }
}
