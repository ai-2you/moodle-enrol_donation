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
 * Unit tests for \enrol_donation\local\delivery and the redeliver_paid_donations task.
 *
 * @package    enrol_donation
 * @category   test
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_donation\local;

/**
 * Unit tests for \enrol_donation\local\delivery.
 *
 * @coversDefaultClass \enrol_donation\local\delivery
 */
final class delivery_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * @return \enrol_donation_generator
     */
    protected function donation_generator(): \enrol_donation_generator {
        return $this->getDataGenerator()->get_plugin_generator('enrol_donation');
    }

    /**
     * @covers ::deliver
     */
    public function test_deliver_called_twice_results_in_single_enrolment_and_role_assignment(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $context = \context_course::instance($instance->courseid);
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id);

        delivery::deliver($intent, $user->id);
        delivery::deliver($intent, $user->id);

        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]));
        $this->assertTrue(user_has_role_assignment($user->id, $instance->roleid, $context->id));
    }

    /**
     * @covers ::deliver
     */
    public function test_deliver_second_call_does_not_change_enrolment_dates(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance(['enrolperiod' => WEEKSECS]);
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id);

        delivery::deliver($intent, $user->id);

        // Simulate the enrolment having been created at a different, identifiable, point in time.
        $fixedstart = time() - (2 * DAYSECS);
        $fixedend = $fixedstart + WEEKSECS;
        $DB->set_field('user_enrolments', 'timestart', $fixedstart, ['enrolid' => $instance->id, 'userid' => $user->id]);
        $DB->set_field('user_enrolments', 'timeend', $fixedend, ['enrolid' => $instance->id, 'userid' => $user->id]);

        delivery::deliver($intent, $user->id);

        $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]);
        $this->assertEquals($fixedstart, $ue->timestart);
        $this->assertEquals($fixedend, $ue->timeend);
    }

    /**
     * @covers ::deliver
     */
    public function test_deliver_does_not_reactivate_suspended_enrolment(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id);

        delivery::deliver($intent, $user->id);

        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED,
            ['enrolid' => $instance->id, 'userid' => $user->id]);

        delivery::deliver($intent, $user->id);

        $status = $DB->get_field('user_enrolments', 'status', ['enrolid' => $instance->id, 'userid' => $user->id]);
        $this->assertEquals(ENROL_USER_SUSPENDED, $status);
    }

    /**
     * @covers ::deliver
     */
    public function test_deliver_leaves_no_open_transaction_after_exception(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);

        // Force the MUST_EXIST lookup of the enrol instance inside deliver() to fail.
        $DB->delete_records('enrol', ['id' => $instance->id]);

        try {
            delivery::deliver($intent, $user->id);
            $this->fail('Expected an exception because the enrol instance no longer exists.');
        } catch (\dml_exception $e) {
            // Expected.
        }

        $this->assertFalse($DB->is_transaction_started());
    }

    /**
     * @covers ::deliver
     */
    public function test_deliver_rejects_payer_zero_without_marking_delivered(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);

        $result = delivery::deliver($intent, 0);

        $this->assertFalse($result);
        $this->assertEquals(0, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));
        $this->assertEquals(0, $DB->get_field('enrol_donation_intent', 'timedelivered', ['id' => $intent->id]));
    }

    /**
     * @covers ::deliver
     */
    public function test_deliver_rejects_guest_payer_without_marking_delivered(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $guest = guest_user();

        $result = delivery::deliver($intent, $guest->id);

        $this->assertFalse($result);
        $this->assertEquals(0, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));
        $this->assertEquals(0, $DB->get_field('enrol_donation_intent', 'timedelivered', ['id' => $intent->id]));
    }

    /**
     * @covers ::deliver
     */
    public function test_deliver_rejects_deleted_payer_without_marking_delivered(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        delete_user($user);

        $result = delivery::deliver($intent, $user->id);

        $this->assertFalse($result);
        $this->assertEquals(0, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));
        $this->assertEquals(0, $DB->get_field('enrol_donation_intent', 'timedelivered', ['id' => $intent->id]));
    }

    /**
     * @covers ::deliver
     */
    public function test_deliver_succeeds_even_if_instance_is_disabled(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance(['status' => ENROL_INSTANCE_DISABLED]);
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);

        $result = delivery::deliver($intent, $user->id);

        $this->assertTrue($result);
        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]));
    }

    /**
     * @covers ::get_undelivered_for_user
     */
    public function test_get_undelivered_for_user_filters_by_user_and_instance(): void {
        $instancea = $this->donation_generator()->create_instance();
        $instanceb = $this->donation_generator()->create_instance();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $intenta = $this->donation_generator()->create_intent(['instanceid' => $instancea->id]);
        $this->donation_generator()->create_payment_for_intent($intenta, $usera->id);

        $intentb = $this->donation_generator()->create_intent(['instanceid' => $instancea->id]);
        $this->donation_generator()->create_payment_for_intent($intentb, $userb->id);

        $intentc = $this->donation_generator()->create_intent(['instanceid' => $instanceb->id]);
        $this->donation_generator()->create_payment_for_intent($intentc, $usera->id);

        $this->assertCount(1, delivery::get_undelivered_for_user($instancea->id, $usera->id));
        $this->assertCount(1, delivery::get_undelivered_for_user($instancea->id, $userb->id));
        $this->assertCount(1, delivery::get_undelivered_for_user($instanceb->id, $usera->id));
        $this->assertCount(0, delivery::get_undelivered_for_user($instanceb->id, $userb->id));
    }

    // ------------------------------------------------------------------
    // redeliver_paid_donations scheduled task.
    // ------------------------------------------------------------------

    /**
     * @covers \enrol_donation\task\redeliver_paid_donations
     */
    public function test_redeliver_task_delivers_payments_older_than_10_minutes(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id, [
            'timecreated' => time() - (15 * MINSECS),
        ]);

        (new \enrol_donation\task\redeliver_paid_donations())->execute();

        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]));
    }

    /**
     * @covers \enrol_donation\task\redeliver_paid_donations
     */
    public function test_redeliver_task_ignores_payments_younger_than_10_minutes(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id, [
            'timecreated' => time() - MINSECS,
        ]);

        (new \enrol_donation\task\redeliver_paid_donations())->execute();

        $this->assertEquals(0, $DB->count_records('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]));
    }

    /**
     * @covers \enrol_donation\task\redeliver_paid_donations
     */
    public function test_redeliver_task_does_not_reenrol_user_after_manual_unenrolment(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id, [
            'timecreated' => time() - (15 * MINSECS),
        ]);

        delivery::deliver($intent, $user->id);
        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]));

        // Manually unenrol, simulating an admin/teacher action after delivery.
        $DB->delete_records('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]);

        (new \enrol_donation\task\redeliver_paid_donations())->execute();

        // The intent is already marked delivered, so it is no longer picked up: no silent re-enrolment.
        $this->assertEquals(0, $DB->count_records('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]));
    }

    /**
     * @covers ::get_paid_undelivered
     * @covers \enrol_donation\task\redeliver_paid_donations
     */
    public function test_two_payment_rows_for_same_intent_result_in_single_enrolment(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id, [
            'timecreated' => time() - (20 * MINSECS),
        ]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id, [
            'timecreated' => time() - (15 * MINSECS),
        ]);

        (new \enrol_donation\task\redeliver_paid_donations())->execute();

        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]));
    }

    /**
     * @covers \enrol_donation\task\redeliver_paid_donations
     */
    public function test_redeliver_task_keeps_retrying_payment_from_30_days_ago(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id, [
            'timecreated' => time() - (30 * DAYSECS),
        ]);

        (new \enrol_donation\task\redeliver_paid_donations())->execute();

        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]));
    }

    /**
     * @covers \enrol_donation\task\redeliver_paid_donations
     */
    public function test_redeliver_task_failure_in_one_intent_does_not_revert_another(): void {
        global $DB;

        $instancea = $this->donation_generator()->create_instance();
        $userfail = $this->getDataGenerator()->create_user();
        $intenta = $this->donation_generator()->create_intent(['instanceid' => $instancea->id]);
        $this->donation_generator()->create_payment_for_intent($intenta, $userfail->id, [
            'timecreated' => time() - (15 * MINSECS),
        ]);
        // Break intent A: its underlying enrol instance disappears, deliver() must throw for it.
        $DB->delete_records('enrol', ['id' => $instancea->id]);

        $instanceb = $this->donation_generator()->create_instance();
        $userok = $this->getDataGenerator()->create_user();
        $intentb = $this->donation_generator()->create_intent(['instanceid' => $instanceb->id]);
        $this->donation_generator()->create_payment_for_intent($intentb, $userok->id, [
            'timecreated' => time() - (15 * MINSECS),
        ]);

        (new \enrol_donation\task\redeliver_paid_donations())->execute();

        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instanceb->id, 'userid' => $userok->id]));
    }

    /**
     * @covers \enrol_donation\local\delivery::send_alerts
     * @covers \enrol_donation\task\redeliver_paid_donations
     */
    public function test_redeliver_task_sends_admin_alert_once_per_24h_for_overdue_payment(): void {
        // The donation enrol plugin ships disabled by default (admin must opt in via Site
        // administration > Plugins > Enrolments); enable it here so
        // message_get_providers_for_user() does not filter out this component's message provider
        // via its enrol_is_enabled() check (lib/messagelib.php).
        set_config('enrol_plugins_enabled', 'manual,donation');

        $sink = $this->redirectMessages();

        $instance = $this->donation_generator()->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        // Overdue (> 24h) undelivered payment.
        $this->donation_generator()->create_payment_for_intent($intent, $user->id, [
            'timecreated' => time() - (25 * HOURSECS),
        ]);
        // Make delivery fail so the payment stays undelivered and overdue.
        global $DB;
        $DB->delete_records('enrol', ['id' => $instance->id]);

        (new \enrol_donation\task\redeliver_paid_donations())->execute();
        $firstrunmessages = count($sink->get_messages());
        $this->assertGreaterThanOrEqual(1, $firstrunmessages);

        $sink->clear();
        (new \enrol_donation\task\redeliver_paid_donations())->execute();
        $this->assertCount(0, $sink->get_messages(), 'Alert must be throttled to once per 24h.');

        set_config('lastalert', time() - (25 * HOURSECS), 'enrol_donation');
        $sink->clear();
        (new \enrol_donation\task\redeliver_paid_donations())->execute();
        $this->assertGreaterThanOrEqual(1, count($sink->get_messages()), 'Alert must fire again after 24h.');

        $sink->close();
    }
}
