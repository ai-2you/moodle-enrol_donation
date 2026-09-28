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
 * Payment subsystem callback implementation for enrol_donation.
 *
 * @package    enrol_donation
 * @category   payment
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_donation\payment;

use enrol_donation\local\delivery;
use enrol_donation\local\intent;

/**
 * Payment subsystem callback implementation for enrol_donation.
 *
 * The amount and currency always come from the {enrol_donation_intent} row identified by
 * $itemid, never from the client at payment time. get_payable() deliberately rejects an intent
 * that already has a {payments} row: this both blocks replay of an old/paid intent and, if a
 * gateway retries deliver_order() after a transient failure, stops a second {payments} row from
 * ever being created for the same charge.
 *
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_provider implements \core_payment\local\callback\service_provider {

    /**
     * Callback function that returns the donation amount and the accountid for the course that
     * the $itemid intent belongs to.
     *
     * @param string $paymentarea Payment area
     * @param int $itemid The enrol_donation_intent id
     * @return \core_payment\local\entities\payable
     */
    public static function get_payable(string $paymentarea, int $itemid): \core_payment\local\entities\payable {
        global $DB;

        if ($paymentarea !== intent::PAYMENTAREA) {
            throw new \coding_exception('Unknown payment area for enrol_donation: ' . $paymentarea);
        }

        $donationintent = intent::get($itemid, MUST_EXIST);

        if (intent::is_paid($itemid)) {
            throw new \moodle_exception('intentalreadypaid', 'enrol_donation');
        }

        $instance = $DB->get_record('enrol', ['id' => $donationintent->instanceid], '*', MUST_EXIST);

        return new \core_payment\local\entities\payable(
            (float) $donationintent->amount,
            $donationintent->currency,
            (int) $instance->customint1
        );
    }

    /**
     * Callback function that returns the URL of the page the user should be redirected to after
     * a successful payment.
     *
     * @param string $paymentarea Payment area
     * @param int $itemid The enrol_donation_intent id
     * @return \moodle_url
     */
    public static function get_success_url(string $paymentarea, int $itemid): \moodle_url {
        global $DB;

        if ($paymentarea !== intent::PAYMENTAREA) {
            throw new \coding_exception('Unknown payment area for enrol_donation: ' . $paymentarea);
        }

        $donationintent = intent::get($itemid, IGNORE_MISSING);
        if (!$donationintent) {
            return new \moodle_url('/');
        }

        $courseid = $DB->get_field('enrol', 'courseid', ['id' => $donationintent->instanceid]);
        if (!$courseid) {
            return new \moodle_url('/');
        }

        return new \moodle_url('/course/view.php', ['id' => $courseid]);
    }

    /**
     * Callback function that delivers what the user paid for: enrolment in the course.
     *
     * @param string $paymentarea
     * @param int $itemid The enrol_donation_intent id
     * @param int $paymentid payment id as inserted into the 'payments' table
     * @param int $userid The userid the order is going to deliver to
     * @return bool Always true: both supported gateways ignore this return value, and a failure
     *  here must not be mistaken for "nothing was charged" (redeliver_paid_donations retries it).
     */
    public static function deliver_order(string $paymentarea, int $itemid, int $paymentid, int $userid): bool {
        if ($paymentarea !== intent::PAYMENTAREA) {
            throw new \coding_exception('Unknown payment area for enrol_donation: ' . $paymentarea);
        }

        $donationintent = intent::get($itemid, MUST_EXIST);

        delivery::deliver($donationintent, $userid);

        return true;
    }
}
