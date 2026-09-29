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
 * Privacy Subsystem implementation for enrol_donation.
 *
 * @package    enrol_donation
 * @category   privacy
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_donation\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;

/**
 * Privacy Subsystem for enrol_donation.
 *
 * {enrol_donation_intent} stores no personal data of its own (instanceid, amount, currency,
 * timestamps only; the payer is never recorded there, only in {payments}.userid, which
 * core_payment owns) so this is a null_provider. It must still implement consumer_provider so
 * core_payment can resolve context and export/delete {payments} rows for this component.
 *
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_payment\privacy\consumer_provider, \core_privacy\local\metadata\null_provider {
    /**
     * Get the language string identifier explaining why this plugin stores no data.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }

    /**
     * Return contextid for the provided payment data.
     *
     * @param string $paymentarea Payment area
     * @param int $itemid The enrol_donation_intent id
     * @return int|null
     */
    public static function get_contextid_for_payment(string $paymentarea, int $itemid): ?int {
        global $DB;

        $sql = "SELECT ctx.id
                  FROM {enrol_donation_intent} i
                  JOIN {enrol} e ON e.id = i.instanceid
                  JOIN {context} ctx ON (ctx.instanceid = e.courseid AND ctx.contextlevel = :contextcourse)
                 WHERE i.id = :itemid";
        $params = [
            'contextcourse' => CONTEXT_COURSE,
            'itemid' => $itemid,
        ];
        $contextid = $DB->get_field_sql($sql, $params);

        return $contextid ?: null;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if ($context instanceof \context_course) {
            $sql = "SELECT p.userid
                      FROM {payments} p
                      JOIN {enrol_donation_intent} i ON (p.component = :component AND p.itemid = i.id)
                      JOIN {enrol} e ON e.id = i.instanceid
                     WHERE e.courseid = :courseid";
            $params = [
                'component' => 'enrol_donation',
                'courseid' => $context->instanceid,
            ];
            $userlist->add_from_sql('userid', $sql, $params);
        } else if ($context instanceof \context_system) {
            // The intent for this payment no longer exists (deleted instance/course).
            $sql = "SELECT p.userid
                      FROM {payments} p
                 LEFT JOIN {enrol_donation_intent} i ON p.itemid = i.id
                     WHERE p.component = :component AND i.id IS NULL";
            $params = [
                'component' => 'enrol_donation',
            ];
            $userlist->add_from_sql('userid', $sql, $params);
        }
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $subcontext = [get_string('pluginname', 'enrol_donation')];

        foreach ($contextlist as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }

            $sql = "SELECT p.*
                      FROM {payments} p
                      JOIN {enrol_donation_intent} i ON (p.component = :component AND p.itemid = i.id)
                      JOIN {enrol} e ON e.id = i.instanceid
                     WHERE e.courseid = :courseid AND p.userid = :userid";
            $params = [
                'component' => 'enrol_donation',
                'courseid' => $context->instanceid,
                'userid' => $contextlist->get_user()->id,
            ];

            $payments = $DB->get_recordset_sql($sql, $params);
            foreach ($payments as $payment) {
                \core_payment\privacy\provider::export_payment_data_for_user_in_context(
                    $context,
                    $subcontext,
                    $payment->userid,
                    $payment->component,
                    $payment->paymentarea,
                    $payment->itemid
                );
            }
            $payments->close();
        }

        if (in_array(SYSCONTEXTID, $contextlist->get_contextids())) {
            $sql = "SELECT p.*
                      FROM {payments} p
                 LEFT JOIN {enrol_donation_intent} i ON p.itemid = i.id
                     WHERE p.userid = :userid AND p.component = :component AND i.id IS NULL";
            $params = [
                'component' => 'enrol_donation',
                'userid' => $contextlist->get_user()->id,
            ];

            $orphanedpayments = $DB->get_recordset_sql($sql, $params);
            foreach ($orphanedpayments as $payment) {
                \core_payment\privacy\provider::export_payment_data_for_user_in_context(
                    \context_system::instance(),
                    $subcontext,
                    $payment->userid,
                    $payment->component,
                    $payment->paymentarea,
                    $payment->itemid
                );
            }
            $orphanedpayments->close();
        }
    }

    /**
     * Delete all data for all users in the specified context. Only {payments} rows are removed:
     * intents are not personal data and are reclaimed later by cleanup_stale_intents once they no
     * longer have a matching payment.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context instanceof \context_course) {
            $sql = "SELECT p.id
                      FROM {payments} p
                      JOIN {enrol_donation_intent} i ON (p.component = :component AND p.itemid = i.id)
                      JOIN {enrol} e ON e.id = i.instanceid
                     WHERE e.courseid = :courseid";
            $params = [
                'component' => 'enrol_donation',
                'courseid' => $context->instanceid,
            ];

            \core_payment\privacy\provider::delete_data_for_payment_sql($sql, $params);
        } else if ($context instanceof \context_system) {
            $sql = "SELECT p.id
                      FROM {payments} p
                 LEFT JOIN {enrol_donation_intent} i ON p.itemid = i.id
                     WHERE p.component = :component AND i.id IS NULL";
            $params = [
                'component' => 'enrol_donation',
            ];

            \core_payment\privacy\provider::delete_data_for_payment_sql($sql, $params);
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts. Always filtered by
     * p.userid, so one donor's deletion request never touches another donor's payment.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $courseids = [];
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_course) {
                $courseids[] = $context->instanceid;
            }
        }

        if ($courseids) {
            [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $sql = "SELECT p.id
                      FROM {payments} p
                      JOIN {enrol_donation_intent} i ON (p.component = :component AND p.itemid = i.id)
                      JOIN {enrol} e ON e.id = i.instanceid
                     WHERE p.userid = :userid AND e.courseid $insql";
            $params = $inparams + [
                'component' => 'enrol_donation',
                'userid' => $contextlist->get_user()->id,
            ];

            \core_payment\privacy\provider::delete_data_for_payment_sql($sql, $params);
        }

        if (in_array(SYSCONTEXTID, $contextlist->get_contextids())) {
            $sql = "SELECT p.id
                      FROM {payments} p
                 LEFT JOIN {enrol_donation_intent} i ON p.itemid = i.id
                     WHERE p.component = :component AND p.userid = :userid AND i.id IS NULL";
            $params = [
                'component' => 'enrol_donation',
                'userid' => $contextlist->get_user()->id,
            ];

            \core_payment\privacy\provider::delete_data_for_payment_sql($sql, $params);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if ($context instanceof \context_course) {
            [$usersql, $userparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
            $sql = "SELECT p.id
                      FROM {payments} p
                      JOIN {enrol_donation_intent} i ON (p.component = :component AND p.itemid = i.id)
                      JOIN {enrol} e ON e.id = i.instanceid
                     WHERE e.courseid = :courseid AND p.userid $usersql";
            $params = $userparams + [
                'component' => 'enrol_donation',
                'courseid' => $context->instanceid,
            ];

            \core_payment\privacy\provider::delete_data_for_payment_sql($sql, $params);
        } else if ($context instanceof \context_system) {
            [$usersql, $userparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
            $sql = "SELECT p.id
                      FROM {payments} p
                 LEFT JOIN {enrol_donation_intent} i ON p.itemid = i.id
                     WHERE p.component = :component AND p.userid $usersql AND i.id IS NULL";
            $params = $userparams + [
                'component' => 'enrol_donation',
            ];

            \core_payment\privacy\provider::delete_data_for_payment_sql($sql, $params);
        }
    }
}
