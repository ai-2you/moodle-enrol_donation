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
 * Privacy provider tests for enrol_donation.
 *
 * @package    enrol_donation
 * @category   test
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_donation\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider tests for enrol_donation.
 *
 * @coversDefaultClass \enrol_donation\privacy\provider
 */
final class provider_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Returns the enrol_donation test data generator.
     *
     * @return \enrol_donation_generator
     */
    protected function donation_generator(): \enrol_donation_generator {
        return $this->getDataGenerator()->get_plugin_generator('enrol_donation');
    }

    /**
     * enrol_donation stores no personal data of its own (null_provider) but must also implement
     * core_payment's consumer_provider so that {payments} rows can be located/exported/deleted.
     *
     * @covers \core_privacy\manager::component_is_compliant
     */
    public function test_component_is_compliant(): void {
        $manager = new \core_privacy\manager();
        $this->assertTrue($manager->component_is_compliant('enrol_donation'));
    }

    /**
     * get_contextid_for_payment() resolves a donation payment to its course context.
     *
     * @covers ::get_contextid_for_payment
     */
    public function test_get_contextid_for_payment_returns_course_context(): void {
        $instance = $this->donation_generator()->create_instance();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);

        $contextid = provider::get_contextid_for_payment('donation', $intent->id);

        $this->assertEquals(\context_course::instance($instance->courseid)->id, $contextid);
    }

    /**
     * get_contextid_for_payment() returns null once the underlying intent has been deleted.
     *
     * @covers ::get_contextid_for_payment
     */
    public function test_get_contextid_for_payment_returns_null_when_intent_gone(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $DB->delete_records('enrol_donation_intent', ['id' => $intent->id]);

        $this->assertNull(provider::get_contextid_for_payment('donation', $intent->id));
    }

    /**
     * get_users_in_context() lists all payers for the donation payments made in a course context.
     *
     * @covers ::get_users_in_context
     */
    public function test_get_users_in_context_course_returns_payers(): void {
        $instance = $this->donation_generator()->create_instance();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $intenta = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intenta, $usera->id);
        $intentb = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intentb, $userb->id);

        $context = \context_course::instance($instance->courseid);
        $userlist = new userlist($context, 'enrol_donation');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing([$usera->id, $userb->id], $userlist->get_userids());
    }

    /**
     * get_users_in_context() lists payers of orphaned payments under the system context.
     *
     * @covers ::get_users_in_context
     */
    public function test_get_users_in_context_system_returns_orphaned_payments(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id);
        $DB->delete_records('enrol_donation_intent', ['id' => $intent->id]);

        $context = \context_system::instance();
        $userlist = new userlist($context, 'enrol_donation');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing([$user->id], $userlist->get_userids());
    }

    /**
     * delete_data_for_user() deletes only the requested user's payments, leaving others intact.
     *
     * @covers ::delete_data_for_user
     */
    public function test_delete_data_for_user_deletes_only_that_users_payments(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $context = \context_course::instance($instance->courseid);
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $intenta = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $paymenta = $this->donation_generator()->create_payment_for_intent($intenta, $usera->id);
        $intentb = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $paymentb = $this->donation_generator()->create_payment_for_intent($intentb, $userb->id);

        $contextlist = new approved_contextlist($usera, 'enrol_donation', [$context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('payments', ['id' => $paymenta]));
        $this->assertTrue($DB->record_exists('payments', ['id' => $paymentb]));
        // Intents are not personal data: the borrado-privacidad invariant leaves them intact,
        // to be reclaimed later (without payments) by cleanup_stale_intents.
        $this->assertTrue($DB->record_exists('enrol_donation_intent', ['id' => $intenta->id]));
    }

    /**
     * delete_data_for_all_users_in_context() deletes every payment made in that course.
     *
     * @covers ::delete_data_for_all_users_in_context
     */
    public function test_delete_data_for_all_users_in_context_deletes_all_course_payments(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $context = \context_course::instance($instance->courseid);
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $intenta = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $paymenta = $this->donation_generator()->create_payment_for_intent($intenta, $usera->id);
        $intentb = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $paymentb = $this->donation_generator()->create_payment_for_intent($intentb, $userb->id);

        provider::delete_data_for_all_users_in_context($context);

        $this->assertFalse($DB->record_exists('payments', ['id' => $paymenta]));
        $this->assertFalse($DB->record_exists('payments', ['id' => $paymentb]));
    }

    /**
     * delete_data_for_user() deletes a user's orphaned payment even under the system context.
     *
     * @covers ::delete_data_for_user
     */
    public function test_delete_data_for_user_deletes_orphaned_payments_in_system_context(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $payment = $this->donation_generator()->create_payment_for_intent($intent, $user->id);
        $DB->delete_records('enrol_donation_intent', ['id' => $intent->id]);

        $contextlist = new approved_contextlist($user, 'enrol_donation', [SYSCONTEXTID]);
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('payments', ['id' => $payment]));
    }
}
