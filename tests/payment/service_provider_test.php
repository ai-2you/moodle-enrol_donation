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
 * Unit tests for enrol_donation's payment subsystem callback implementation.
 *
 * @package    enrol_donation
 * @category   test
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_donation\payment;

/**
 * Unit tests for \enrol_donation\payment\service_provider.
 *
 * @coversDefaultClass \enrol_donation\payment\service_provider
 */
final class service_provider_test extends \advanced_testcase {

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
     * @covers ::get_payable
     */
    public function test_get_payable_returns_amount_currency_and_account(): void {
        $account = $this->getDataGenerator()->get_plugin_generator('core_payment')
            ->create_payment_account(['gateways' => 'paypal']);
        $instance = $this->donation_generator()->create_instance([
            'currency' => 'EUR',
            'customint1' => $account->get('id'),
        ]);
        $intent = $this->donation_generator()->create_intent([
            'instanceid' => $instance->id,
            'amount' => 42.00,
            'currency' => 'EUR',
        ]);

        $payable = service_provider::get_payable('donation', $intent->id);

        $this->assertEquals($account->get('id'), $payable->get_account_id());
        $this->assertEquals(42.00, $payable->get_amount());
        $this->assertEquals('EUR', $payable->get_currency());
    }

    /**
     * @covers ::get_payable
     */
    public function test_get_payable_rejects_nonexistent_intent(): void {
        $this->expectException(\moodle_exception::class);
        service_provider::get_payable('donation', 999999);
    }

    /**
     * @covers ::get_payable
     */
    public function test_get_payable_rejects_already_paid_intent(): void {
        $instance = $this->donation_generator()->create_instance();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->donation_generator()->create_payment_for_intent($intent, $user->id);

        $this->expectException(\moodle_exception::class);
        service_provider::get_payable('donation', $intent->id);
    }

    /**
     * @covers ::get_payable
     */
    public function test_get_payable_succeeds_for_disabled_instance(): void {
        $instance = $this->donation_generator()->create_instance(['status' => ENROL_INSTANCE_DISABLED]);
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);

        $payable = service_provider::get_payable('donation', $intent->id);

        $this->assertEquals($intent->amount, $payable->get_amount());
    }

    /**
     * @covers ::get_payable
     */
    public function test_get_payable_succeeds_for_expired_instance(): void {
        $instance = $this->donation_generator()->create_instance([
            'enrolenddate' => time() - DAYSECS,
        ]);
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);

        $payable = service_provider::get_payable('donation', $intent->id);

        $this->assertEquals($intent->amount, $payable->get_amount());
    }

    /**
     * @covers ::get_payable
     */
    public function test_get_payable_succeeds_when_minimum_raised_after_intent_created(): void {
        $instance = $this->donation_generator()->create_instance(['cost' => '5.00']);
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id, 'amount' => 5.00]);

        global $DB;
        $DB->set_field('enrol', 'cost', '50.00', ['id' => $instance->id]);

        $payable = service_provider::get_payable('donation', $intent->id);

        $this->assertEquals(5.00, $payable->get_amount());
    }

    /**
     * @covers ::deliver_order
     */
    public function test_deliver_order_returns_true_and_enrols_with_role_and_period(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance(['enrolperiod' => WEEKSECS]);
        $context = \context_course::instance($instance->courseid);
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $paymentid = $this->donation_generator()->create_payment_for_intent($intent, $user->id);

        $result = service_provider::deliver_order('donation', $intent->id, $paymentid, $user->id);

        $this->assertTrue($result);
        $this->assertTrue(is_enrolled($context, $user));
        $this->assertTrue(user_has_role_assignment($user->id, $instance->roleid, $context->id));
        $this->assertGreaterThan(0, $DB->get_field('enrol_donation_intent', 'timedelivered', ['id' => $intent->id]));
    }

    /**
     * @covers ::deliver_order
     */
    public function test_deliver_order_delivers_even_if_minimum_raised_after_intent_created(): void {
        $instance = $this->donation_generator()->create_instance(['cost' => '5.00']);
        $context = \context_course::instance($instance->courseid);
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id, 'amount' => 5.00]);
        $paymentid = $this->donation_generator()->create_payment_for_intent($intent, $user->id);

        global $DB;
        $DB->set_field('enrol', 'cost', '50.00', ['id' => $instance->id]);

        $result = service_provider::deliver_order('donation', $intent->id, $paymentid, $user->id);

        $this->assertTrue($result);
        $this->assertTrue(is_enrolled($context, $user));
    }

    /**
     * @covers ::get_success_url
     */
    public function test_get_success_url_points_to_course(): void {
        global $CFG;

        $instance = $this->donation_generator()->create_instance();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);

        $url = service_provider::get_success_url('donation', $intent->id);

        $this->assertEquals(
            $CFG->wwwroot . '/course/view.php?id=' . $instance->courseid,
            $url->out(false)
        );
    }

    /**
     * @covers ::get_success_url
     */
    public function test_get_success_url_falls_back_when_intent_missing(): void {
        $url = service_provider::get_success_url('donation', 999999);

        $this->assertNotNull($url);
    }

    /**
     * @covers ::get_payable
     * @covers ::deliver_order
     */
    public function test_invalid_paymentarea_throws_coding_exception(): void {
        $instance = $this->donation_generator()->create_instance();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);

        $this->expectException(\coding_exception::class);
        service_provider::deliver_order('notdonation', $intent->id, 1, 2);
    }
}
