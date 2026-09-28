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
 * Everything that happens after money changes hands: enrolling the payer, locating payments that
 * were never delivered, and alerting admins about the ones that need manual attention.
 *
 * @package    enrol_donation
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_donation\local;

/**
 * Delivery of a paid donation: enrolling the payer and tracking what is still owed.
 *
 * Called from three places (the payment service_provider, the redelivery task, and the
 * self-healing check in enrol_page_hook), so it lives in its own class rather than being inlined
 * in any single caller.
 *
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delivery {

    /**
     * Enrols the payer of a paid intent, if they are a valid identifiable user and are not
     * already enrolled. Always marks the intent as delivered on success. Never opens a
     * transaction and never catches exceptions: the {payments} row already exists by the time
     * this runs, so failures here must propagate to the caller for retry.
     *
     * @param \stdClass $intent the intent (only ->id and ->instanceid are used).
     * @param int $payerid {payments}.userid for the charge that paid this intent.
     * @return bool true if delivered (or already delivered), false if the payer is invalid.
     */
    public static function deliver(\stdClass $intent, int $payerid): bool {
        global $DB;

        if ($payerid <= 0 || isguestuser($payerid) || !$DB->record_exists('user', ['id' => $payerid, 'deleted' => 0])) {
            return false;
        }

        $instance = $DB->get_record('enrol', ['id' => $intent->instanceid], '*', MUST_EXIST);
        $plugin = enrol_get_plugin('donation');

        // Always re-read the current state from the DB: the caller's $intent may be stale if this
        // is a retry, and enrol_user() is not idempotent with respect to dates or suspension.
        $currentlydelivered = (int) $DB->get_field('enrol_donation_intent', 'timedelivered', ['id' => $intent->id], MUST_EXIST);
        $alreadyenrolled = $DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $payerid]);

        if ($currentlydelivered === 0 || !$alreadyenrolled) {
            if ($instance->enrolperiod) {
                $timestart = time();
                $timeend = $timestart + $instance->enrolperiod;
            } else {
                $timestart = 0;
                $timeend = 0;
            }
            $plugin->enrol_user($instance, $payerid, $instance->roleid, $timestart, $timeend);
        }

        intent::mark_delivered($intent->id);

        return true;
    }

    /**
     * Finds paid-but-undelivered intents for a specific user in a specific instance. Used by
     * enrol_page_hook() to self-heal on page visit.
     *
     * @param int $instanceid
     * @param int $userid
     * @return \stdClass[] intent records.
     */
    public static function get_undelivered_for_user(int $instanceid, int $userid): array {
        global $DB;

        $sql = "SELECT DISTINCT i.*
                  FROM {enrol_donation_intent} i
                  JOIN {payments} p ON (p.component = :component AND p.paymentarea = :paymentarea AND p.itemid = i.id)
                 WHERE p.userid = :userid AND i.instanceid = :instanceid AND i.timedelivered = 0";

        return $DB->get_records_sql($sql, [
            'component' => intent::COMPONENT,
            'paymentarea' => intent::PAYMENTAREA,
            'userid' => $userid,
            'instanceid' => $instanceid,
        ]);
    }

    /**
     * Finds paid-but-undelivered intents across the whole site, one row per (intent, payer),
     * whose earliest payment is older than $paidbefore. Used by the redeliver_paid_donations task.
     *
     * @param int $paidbefore
     * @return \stdClass[] rows with ->intentid, ->payerid, ->instanceid, ->paidtime.
     */
    public static function get_paid_undelivered(int $paidbefore): array {
        global $DB;

        $sql = "SELECT i.id AS intentid, p.userid AS payerid, i.instanceid AS instanceid, MIN(p.timecreated) AS paidtime
                  FROM {enrol_donation_intent} i
                  JOIN {payments} p ON (p.component = :component AND p.paymentarea = :paymentarea AND p.itemid = i.id)
                 WHERE i.timedelivered = 0
              GROUP BY i.id, p.userid, i.instanceid
                HAVING MIN(p.timecreated) < :paidbefore";

        return $DB->get_records_sql($sql, [
            'component' => intent::COMPONENT,
            'paymentarea' => intent::PAYMENTAREA,
            'paidbefore' => $paidbefore,
        ]);
    }

    /**
     * Sends a throttled admin/manager alert about donations stuck undelivered. Recipients are
     * site admins plus anyone with enrol/donation:config in the course context of an affected
     * instance.
     *
     * @param array $overdue rows (as returned by get_paid_undelivered()) older than 24h.
     * @param array $unidentified rows whose payer could not be identified/enrolled at all.
     * @return void
     */
    public static function send_alerts(array $overdue, array $unidentified): void {
        global $DB;

        if (!$overdue && !$unidentified) {
            return;
        }

        $courseids = [];
        foreach (array_merge($overdue, $unidentified) as $row) {
            $courseid = $DB->get_field('enrol', 'courseid', ['id' => $row->instanceid]);
            if ($courseid) {
                $courseids[(int) $courseid] = true;
            }
        }

        $recipients = [];
        foreach (get_admins() as $admin) {
            $recipients[$admin->id] = $admin;
        }
        foreach (array_keys($courseids) as $courseid) {
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            foreach (get_users_by_capability($context, 'enrol/donation:config') as $user) {
                $recipients[$user->id] = $user;
            }
        }

        if (!$recipients) {
            return;
        }

        $lines = [];
        if ($overdue) {
            $lines[] = get_string('alertoverdue', 'enrol_donation', count($overdue));
        }
        if ($unidentified) {
            $lines[] = get_string('alertunidentified', 'enrol_donation', count($unidentified));
        }
        $body = implode("\n", $lines);

        foreach ($recipients as $recipient) {
            $message = new \core\message\message();
            $message->component = 'enrol_donation';
            $message->name = 'deliveryalert';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $recipient;
            $message->subject = get_string('alertsubject', 'enrol_donation');
            $message->fullmessage = $body;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = nl2br(s($body));
            $message->smallmessage = get_string('alertsubject', 'enrol_donation');
            $message->notification = 1;
            message_send($message);
        }
    }
}
