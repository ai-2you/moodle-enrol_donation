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
 * Unit tests for \enrol_donation\local\intent.
 *
 * @package    enrol_donation
 * @category   test
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_donation\local;

/**
 * Unit tests for \enrol_donation\local\intent.
 *
 * @coversDefaultClass \enrol_donation\local\intent
 */
final class intent_test extends \advanced_testcase {

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

    // ------------------------------------------------------------------
    // parse_amount() - locale-aware.
    // ------------------------------------------------------------------

    /**
     * @covers ::parse_amount
     */
    public function test_parse_amount_accepts_plain_integer(): void {
        force_current_language('en');
        $this->assertEquals(150000.0, intent::parse_amount('150000'));
    }

    /**
     * @covers ::parse_amount
     */
    public function test_parse_amount_rejects_thousands_separator_read_as_decimal_in_es(): void {
        force_current_language('es');
        // "1.000" in es (decsep=',') looks like 3 decimals after a dot -> ambiguous, reject.
        $this->assertNull(intent::parse_amount('1.000'));
    }

    /**
     * @covers ::parse_amount
     */
    public function test_parse_amount_rejects_thousands_separator_read_as_decimal_in_id(): void {
        force_current_language('id');
        $this->assertNull(intent::parse_amount('1.000'));
    }

    /**
     * @covers ::parse_amount
     */
    public function test_parse_amount_rejects_thousands_separator_in_en(): void {
        force_current_language('en');
        // "1,000" in en (thousandssep=',') is ambiguous with 3 trailing digits -> reject.
        $this->assertNull(intent::parse_amount('1,000'));
    }

    /**
     * @covers ::parse_amount
     */
    public function test_parse_amount_rejects_both_separators_present_in_any_locale(): void {
        foreach (['en', 'es', 'id'] as $lang) {
            force_current_language($lang);
            $this->assertNull(intent::parse_amount('1.000,50'), "locale $lang should reject '1.000,50'");
        }
    }

    /**
     * @covers ::parse_amount
     */
    public function test_parse_amount_rejects_150000_with_dot_thousands_in_id(): void {
        force_current_language('id');
        $this->assertNull(intent::parse_amount('150.000'));
    }

    /**
     * @covers ::parse_amount
     */
    public function test_parse_amount_comma_decimal_in_es(): void {
        force_current_language('es');
        $this->assertEquals(1.5, intent::parse_amount('1,5'));
    }

    /**
     * @covers ::parse_amount
     */
    public function test_parse_amount_dot_decimal_in_en(): void {
        force_current_language('en');
        $this->assertEquals(1.5, intent::parse_amount('1.5'));
    }

    /**
     * @covers ::parse_amount
     * @dataProvider invalid_raw_amount_provider
     */
    public function test_parse_amount_rejects_invalid_raw_input(string $raw): void {
        force_current_language('en');
        $this->assertNull(intent::parse_amount($raw));
    }

    /**
     * Data provider for invalid raw amount strings.
     *
     * @return array
     */
    public static function invalid_raw_amount_provider(): array {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
            'NaN' => ['NaN'],
            'INF' => ['INF'],
            'scientific notation' => ['1e3'],
            'negative number' => ['-10'],
            'free text' => ['ten euros'],
        ];
    }

    // ------------------------------------------------------------------
    // validate_amount().
    // ------------------------------------------------------------------

    /**
     * @covers ::validate_amount
     */
    public function test_validate_amount_rejects_below_minimum(): void {
        $instance = $this->donation_generator()->create_instance([
            'currency' => 'EUR',
            'cost' => '10.00',
            'customchar2' => '100.00',
        ]);

        $this->assertNotNull(intent::validate_amount(9.99, $instance));
    }

    /**
     * @covers ::validate_amount
     */
    public function test_validate_amount_accepts_minimum_after_rounding(): void {
        $instance = $this->donation_generator()->create_instance([
            'currency' => 'EUR',
            'cost' => '10.00',
            'customchar2' => '100.00',
        ]);

        $this->assertNull(intent::validate_amount(10.00, $instance));
    }

    /**
     * @covers ::validate_amount
     */
    public function test_validate_amount_rejects_above_maximum(): void {
        $instance = $this->donation_generator()->create_instance([
            'currency' => 'EUR',
            'cost' => '10.00',
            'customchar2' => '100.00',
        ]);

        $this->assertNotNull(intent::validate_amount(100.01, $instance));
    }

    /**
     * @covers ::validate_amount
     */
    public function test_validate_amount_accepts_minimum_for_zero_decimal_currency(): void {
        $instance = $this->donation_generator()->create_instance([
            'currency' => 'JPY',
            'cost' => '100',
            'customchar2' => '10000',
        ]);

        $this->assertNull(intent::validate_amount(100.0, $instance));
        $this->assertEquals(100.0, intent::get_minimum($instance));
    }

    // ------------------------------------------------------------------
    // create_or_reuse() - session-scoped reuse.
    // ------------------------------------------------------------------

    /**
     * @covers ::create_or_reuse
     */
    public function test_create_or_reuse_returns_same_intent_for_same_amount_within_session(): void {
        $instance = $this->donation_generator()->create_instance([
            'currency' => 'EUR',
            'cost' => '5.00',
            'customchar2' => '500.00',
        ]);

        $first = intent::create_or_reuse($instance, 25.00);
        $second = intent::create_or_reuse($instance, 25.00);

        $this->assertEquals($first->id, $second->id);
    }

    /**
     * @covers ::create_or_reuse
     */
    public function test_create_or_reuse_creates_new_intent_for_different_amount(): void {
        $instance = $this->donation_generator()->create_instance([
            'currency' => 'EUR',
            'cost' => '5.00',
            'customchar2' => '500.00',
        ]);

        $first = intent::create_or_reuse($instance, 25.00);
        $second = intent::create_or_reuse($instance, 30.00);

        $this->assertNotEquals($first->id, $second->id);
    }

    /**
     * @covers ::create_or_reuse
     */
    public function test_create_or_reuse_creates_new_intent_when_previous_is_older_than_24h(): void {
        global $SESSION;

        $instance = $this->donation_generator()->create_instance([
            'currency' => 'EUR',
            'cost' => '5.00',
            'customchar2' => '500.00',
        ]);

        $first = intent::create_or_reuse($instance, 25.00);

        // Simulate the session entry being more than 24h old.
        $SESSION->enrol_donation_intents[$instance->id][$first->id] = time() - (25 * HOURSECS);

        $second = intent::create_or_reuse($instance, 25.00);

        $this->assertNotEquals($first->id, $second->id);
    }

    /**
     * @covers ::create_or_reuse
     */
    public function test_create_or_reuse_creates_new_intent_when_previous_already_paid(): void {
        $instance = $this->donation_generator()->create_instance([
            'currency' => 'EUR',
            'cost' => '5.00',
            'customchar2' => '500.00',
        ]);
        $user = $this->getDataGenerator()->create_user();

        $first = intent::create_or_reuse($instance, 25.00);
        $this->donation_generator()->create_payment_for_intent($first, $user->id);

        $second = intent::create_or_reuse($instance, 25.00);

        $this->assertNotEquals($first->id, $second->id);
    }

    /**
     * @covers ::create_or_reuse
     */
    public function test_create_or_reuse_soft_limit_recounted_after_insert(): void {
        $instance = $this->donation_generator()->create_instance([
            'currency' => 'EUR',
            'cost' => '1.00',
            'customchar2' => '500.00',
        ]);

        for ($i = 1; $i <= 20; $i++) {
            intent::create_or_reuse($instance, 1.00 + $i);
        }

        $this->expectException(\moodle_exception::class);
        try {
            intent::create_or_reuse($instance, 100.00);
        } finally {
            global $DB;
            $this->assertEquals(20, $DB->count_records('enrol_donation_intent', ['instanceid' => $instance->id]));
        }
    }

    // ------------------------------------------------------------------
    // delete_stale() - single atomic DELETE, never touches paid intents.
    // ------------------------------------------------------------------

    /**
     * @covers ::delete_stale
     */
    public function test_delete_stale_removes_old_unpaid_intent(): void {
        global $DB;

        $old = $this->donation_generator()->create_intent([
            'timecreated' => time() - (40 * DAYSECS),
        ]);

        intent::delete_stale(time() - (30 * DAYSECS));

        $this->assertFalse($DB->record_exists('enrol_donation_intent', ['id' => $old->id]));
    }

    /**
     * @covers ::delete_stale
     */
    public function test_delete_stale_keeps_recent_unpaid_intent(): void {
        global $DB;

        $recent = $this->donation_generator()->create_intent([
            'timecreated' => time() - DAYSECS,
        ]);

        intent::delete_stale(time() - (30 * DAYSECS));

        $this->assertTrue($DB->record_exists('enrol_donation_intent', ['id' => $recent->id]));
    }

    /**
     * @covers ::delete_stale
     */
    public function test_delete_stale_never_removes_paid_intent_even_if_old_and_undelivered(): void {
        global $DB;

        $old = $this->donation_generator()->create_intent([
            'timecreated' => time() - (60 * DAYSECS),
            'timedelivered' => 0,
        ]);
        $user = $this->getDataGenerator()->create_user();
        $this->donation_generator()->create_payment_for_intent($old, $user->id);

        intent::delete_stale(time() - (30 * DAYSECS));

        $this->assertTrue($DB->record_exists('enrol_donation_intent', ['id' => $old->id]));
    }

    /**
     * @covers \enrol_donation\task\cleanup_stale_intents
     */
    public function test_cleanup_stale_intents_task_raises_retention_floor_to_30_days(): void {
        global $DB;

        set_config('intentretention', 5, 'enrol_donation');

        // 10 days old: would be deleted under the configured 5-day retention, but the floor is 30 days.
        $intent = $this->donation_generator()->create_intent([
            'timecreated' => time() - (10 * DAYSECS),
        ]);

        $task = new \enrol_donation\task\cleanup_stale_intents();
        $task->execute();

        $this->assertTrue($DB->record_exists('enrol_donation_intent', ['id' => $intent->id]));
    }
}
